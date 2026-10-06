<?php

namespace App\Console\Commands;

use App\Agent\Tools\ToolRegistry;
use App\Models\AgentInstructionVersion;
use App\Models\BotLesson;
use App\Models\BusinessMemory;
use Illuminate\Console\Command;

/**
 * Rebuild PRE-002: every place that tells the model how to behave, in one
 * read-only file - instruction versions, business memories and lessons
 * (local, plus the server's when an export is given), tool descriptions,
 * prose hints inside tool results, guard hints, side-call prompts and
 * hard-coded customer sentences. Each row gets an id the classification
 * (INST-001) refers to. Nothing is changed anywhere.
 *
 * Server export: a JSON object with agent_instruction_versions,
 * business_memories, bot_lessons and agent_settings arrays, made with a
 * read-only SELECT on the server.
 */
class AgentInstructionInventory extends Command
{
    protected $signature = 'agent:instruction-inventory
        {--server-export= : path of the read-only JSON export from the server}
        {--out= : output path without extension, default docs/baselines/instruction-inventory}';

    protected $description = 'Export every instruction source (DB, server, tools, guards, side calls) into one read-only inventory file.';

    /** Keys inside tool results that carry wording rules, not facts (INST-012). */
    public const PROSE_KEYS = ['say', 'how_to_present', 'note', 'explain_to_customer', 'tell_customer', 'ask_next', 'price_difference_policy', 'reason_for_customer', 'next_step_hint', 'instruction', 'instructions', 'why', 'age_note', 'occupation_check'];

    /** Side calls: class => methods that build their prompt. */
    private const SIDE_CALL_PROMPTS = [
        \App\Domain\Documents\DocumentPipeline::class => ['classify', 'fillMissingFields'],
        \App\Agent\Tools\IdentifyMotorcycleFromImageTool::class => ['execute'],
        \App\Agent\Tools\LookupMotorcycleSpecsOnlineTool::class => ['prompt'],
        \App\Domain\Applications\AddressSplitter::class => ['split'],
        \App\Jobs\SummarizeConversation::class => ['handle'],
        \App\Domain\Conversations\GeminiVoiceTranscriber::class => ['PROMPT'],
        \App\Domain\Conversations\OpenAiVoiceTranscriber::class => ['PROMPT'],
        \App\Domain\Teaching\TeachingCoach::class => ['ask'],
    ];

    /** Customer-facing sentences written in code or config. */
    private const HARD_CODED = [
        \App\Agent\Runtime\AgentRunner::class => ['keepGoingReply', 'fallback'],
        \App\Domain\Installments\FinancingCapPolicy::class => ['explanation'],
        \App\Agent\Tools\SubmitApplicationTool::class => ['execute'],
        \App\Agent\Tools\GetApplicationRequirementsTool::class => ['execute'],
    ];

    private const CONFIG_TEXTS = ['agent.fallback.message', 'agent.handoff.waiting_message', 'agent.installments.price_difference_explanation'];

    public function handle(ToolRegistry $registry): int
    {
        $rows = [];
        $add = function (string $source, string $location, string $kind, ?string $content, array $meta = []) use (&$rows) {
            $rows[] = [
                'id' => sprintf('INV-%04d', count($rows) + 1),
                'source' => $source,
                'location' => $location,
                'kind' => $kind,
                'chars' => $content === null ? null : mb_strlen($content),
                'sha1' => $content === null ? null : sha1($content),
                'meta' => $meta,
                'content' => $content,
            ];
        };

        // 1. L0 - every version; the full text of the active one and of the file
        foreach (AgentInstructionVersion::orderBy('id')->get() as $v) {
            $add('local_db', "agent_instruction_versions#{$v->id}", 'instruction_version', $v->is_active ? $v->content : null,
                ['version' => $v->version, 'active' => (bool) $v->is_active, 'notes' => $v->notes, 'chars_total' => mb_strlen((string) $v->content), 'sha1_total' => sha1((string) $v->content)]);
        }
        $file = resource_path('agent/instructions/agent.md');
        $add('code', 'resources/agent/instructions/agent.md', 'instruction_file', (string) @file_get_contents($file));

        // 2. Business memories and lessons
        foreach (BusinessMemory::orderBy('id')->get() as $m) {
            $add('local_db', "business_memories#{$m->id}", 'business_memory', (string) $m->content,
                ['key' => $m->key, 'title' => $m->title, 'pinned' => (bool) $m->is_pinned, 'active' => (bool) $m->is_active, 'scope_customer_types' => $m->scope_customer_types, 'scope_application_statuses' => $m->scope_application_statuses]);
        }
        foreach (BotLesson::orderBy('id')->get() as $l) {
            $add('local_db', "bot_lessons#{$l->id}", 'lesson', (string) $l->rule, $this->lessonMeta($l->toArray()));
        }

        // 3. Server (read-only export)
        $server = $this->serverExport();
        foreach ($server['agent_instruction_versions'] ?? [] as $v) {
            $add('server_db', "agent_instruction_versions#{$v['id']}", 'instruction_version', $v['is_active'] ? $v['content'] : null,
                ['version' => $v['version'], 'active' => (bool) $v['is_active'], 'notes' => $v['notes'], 'chars_total' => mb_strlen((string) $v['content']), 'sha1_total' => sha1((string) $v['content'])]);
        }
        foreach ($server['business_memories'] ?? [] as $m) {
            $add('server_db', "business_memories#{$m['id']}", 'business_memory', (string) $m['content'],
                ['key' => $m['key'], 'title' => $m['title'], 'pinned' => (bool) $m['is_pinned'], 'active' => (bool) $m['is_active'], 'scope_customer_types' => $m['scope_customer_types'], 'scope_application_statuses' => $m['scope_application_statuses']]);
        }
        foreach ($server['bot_lessons'] ?? [] as $l) {
            $add('server_db', "bot_lessons#{$l['id']}", 'lesson', (string) $l['rule'], $this->lessonMeta($l));
        }

        // 4. Tool descriptions
        foreach ($registry->declarations() as $declaration) {
            $add('code', 'tool:'.$declaration['name'], 'tool_description', (string) $declaration['description'],
                ['parameters_chars' => mb_strlen((string) json_encode($declaration['parameters'], JSON_UNESCAPED_UNICODE))]);
        }

        // 5. Prose inside tool results
        foreach ($this->proseInToolResults() as $hit) {
            $add('code', $hit['location'], 'tool_result_prose', $hit['line'], ['key' => $hit['key']]);
        }

        // 6. Guard hints
        $hints = (new \ReflectionClassConstant(\App\Agent\Runtime\AgentRunner::class, 'GUARD_HINTS'))->getValue();
        foreach ($hints as $code => $hint) {
            $add('code', 'AgentRunner::GUARD_HINTS['.$code.']', 'guard_hint', (string) $hint, ['code' => $code]);
        }

        // 7. Side-call prompts and 8. hard-coded customer sentences
        foreach (self::SIDE_CALL_PROMPTS as $class => $members) {
            foreach ($members as $member) {
                $add('code', class_basename($class).'::'.$member, 'side_call_prompt', $this->source($class, $member));
            }
        }
        $add('code', 'resources/agent/instructions/coach.md', 'side_call_prompt', (string) @file_get_contents(resource_path('agent/instructions/coach.md')));
        foreach (self::HARD_CODED as $class => $members) {
            foreach ($members as $member) {
                $add('code', class_basename($class).'::'.$member, 'hard_coded_text', $this->source($class, $member));
            }
        }
        foreach (self::CONFIG_TEXTS as $key) {
            $add('config', $key, 'hard_coded_text', is_string(config($key)) ? config($key) : json_encode(config($key), JSON_UNESCAPED_UNICODE));
        }
        foreach ($server['agent_settings'] ?? [] as $setting) {
            $add('server_db', 'agent_settings:'.$setting['key'], 'setting', (string) $setting['value']);
        }

        $counts = $this->verification($rows, $registry, $hints, $server);
        $out = $this->option('out') ?: base_path('docs/baselines/instruction-inventory');
        @mkdir(dirname($out), 0755, true);
        file_put_contents($out.'.json', json_encode(['generated_at' => now()->toDateTimeString(), 'counts' => $counts, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        file_put_contents($out.'.md', $this->markdown($rows, $counts));

        $this->table(['Source', 'Inventory rows', 'Expected', 'Match'], array_map(fn ($c) => [$c['what'], $c['rows'], $c['expected'], $c['rows'] === $c['expected'] ? 'yes' : 'NO'], $counts));
        $this->info("Written: {$out}.json / .md (".count($rows).' rows)');

        return collect($counts)->every(fn ($c) => $c['rows'] === $c['expected']) ? self::SUCCESS : self::FAILURE;
    }

    private function lessonMeta(array $l): array
    {
        return array_intersect_key($l, array_flip(['title', 'fixed_facts', 'example_context', 'example_reply', 'scope_customer_types', 'scope_stage', 'priority', 'is_active', 'revision', 'teaching_session_id', 'created_at', 'updated_at']));
    }

    private function serverExport(): array
    {
        $path = $this->option('server-export');

        if (! $path) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw new \RuntimeException("Server export {$path} is not JSON.");
        }

        return $data;
    }

    /** @return list<array{location: string, key: string, line: string}> */
    public static function proseInToolResults(): array
    {
        $pattern = '/[\'"]('.implode('|', self::PROSE_KEYS).')[\'"]\s*=>/';
        $hits = [];

        // every place whose output reaches the model as tool data or L4 facts
        $files = array_merge(glob(app_path('Agent/Tools/*Tool.php')), array_map('app_path', [
            'Domain/Applications/SnapshotService.php', 'Domain/Applications/WorkClassification.php', 'Domain/Documents/DocumentPipeline.php',
            'Domain/Branches/BranchService.php', 'Domain/Applications/CustomerRequestStatus.php', 'Agent/Context/ContextBuilder.php',
        ]));

        foreach ($files as $file) {
            foreach (file($file) as $i => $line) {
                // an input-schema property ('note' => ['type' => ...]) is the model's own argument, not prose to it
                if (preg_match($pattern, $line, $m) && ! preg_match('/=>\s*\[\s*[\'"]type[\'"]\s*=>/', $line)) {
                    $hits[] = ['location' => str_replace(base_path().'/', '', $file).':'.($i + 1), 'key' => $m[1], 'line' => trim($line)];
                }
            }
        }

        return $hits;
    }

    /** The source text of a method or a constant. */
    private function source(string $class, string $member): ?string
    {
        $reflection = new \ReflectionClass($class);

        if ($reflection->hasConstant($member)) {
            $value = $reflection->getConstant($member);

            return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (! $reflection->hasMethod($member)) {
            return null;
        }

        $method = $reflection->getMethod($member);
        $lines = file((string) $method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    private function verification(array $rows, ToolRegistry $registry, array $hints, array $server): array
    {
        $count = fn (string $source, string $kind) => collect($rows)->where('source', $source)->where('kind', $kind)->count();
        $checks = [
            ['what' => 'local agent_instruction_versions', 'rows' => $count('local_db', 'instruction_version'), 'expected' => AgentInstructionVersion::count()],
            ['what' => 'local business_memories', 'rows' => $count('local_db', 'business_memory'), 'expected' => BusinessMemory::count()],
            ['what' => 'local bot_lessons', 'rows' => $count('local_db', 'lesson'), 'expected' => BotLesson::count()],
            ['what' => 'tool descriptions', 'rows' => $count('code', 'tool_description'), 'expected' => count($registry->declarations())],
            ['what' => 'tool-result prose lines', 'rows' => $count('code', 'tool_result_prose'), 'expected' => count(self::proseInToolResults())],
            ['what' => 'guard hints', 'rows' => $count('code', 'guard_hint'), 'expected' => count($hints)],
        ];

        if ($server !== []) {
            $checks[] = ['what' => 'server agent_instruction_versions', 'rows' => $count('server_db', 'instruction_version'), 'expected' => count($server['agent_instruction_versions'] ?? [])];
            $checks[] = ['what' => 'server business_memories', 'rows' => $count('server_db', 'business_memory'), 'expected' => count($server['business_memories'] ?? [])];
            $checks[] = ['what' => 'server bot_lessons', 'rows' => $count('server_db', 'lesson'), 'expected' => count($server['bot_lessons'] ?? [])];
        }

        return $checks;
    }

    private function markdown(array $rows, array $counts): string
    {
        $lines = [
            '# Instruction inventory (PRE-002)',
            '',
            'Generated '.now()->toDateTimeString().' by `php artisan agent:instruction-inventory`. Read-only snapshot; the full texts are in `instruction-inventory.json` (row ids `INV-xxxx` are what INST-001 classifies).',
            '',
            '## Row counts vs DB / code',
            '',
            '| Source | Rows | Expected | Match |',
            '|---|---|---|---|',
            ...array_map(fn ($c) => "| {$c['what']} | {$c['rows']} | {$c['expected']} | ".($c['rows'] === $c['expected'] ? 'yes' : '**NO**').' |', $counts),
            '',
            '## Rows',
            '',
            '| ID | Source | Location | Kind | Chars | Note |',
            '|---|---|---|---|---|---|',
        ];

        foreach ($rows as $row) {
            $meta = $row['meta'];
            $note = $meta['key'] ?? $meta['title'] ?? $meta['version'] ?? $meta['code'] ?? '';
            if (array_key_exists('active', $meta)) {
                $note .= $meta['active'] ? ' (active)' : ' (inactive)';
            } elseif (array_key_exists('is_active', $meta)) {
                $note .= $meta['is_active'] ? ' (active)' : ' (inactive)';
            }
            $lines[] = '| '.$row['id'].' | '.$row['source'].' | `'.str_replace('|', '\|', $row['location']).'` | '.$row['kind'].' | '.($row['chars'] ?? ($meta['chars_total'] ?? '—')).' | '.str_replace(['|', "\n"], ['\|', ' '], mb_substr((string) $note, 0, 80)).' |';
        }

        return implode("\n", $lines)."\n";
    }
}
