<?php

namespace App\Console\Commands;

use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use App\Services\WhatsappIntentRouter;
use Illuminate\Console\Command;

/**
 * Live regression check against the real dev database (not a PHPUnit test:
 * the suite's phpunit.xml forces sqlite :memory: with no seeded machines or
 * ai_memories, and seeding that data just to run this would be its own
 * project). Runs real turns through WhatsappIntentRouter - including real
 * Gemini calls - against temporary throwaway conversations that are always
 * deleted afterward, win or lose.
 *
 * This is a starting set, not the full 40-60 cases the upgrade plan
 * describes (AI_UPGRADE_PLAN.md, Phase 4.1) - it only covers the specific
 * behaviors fixed and verified live during Phases 1-3, as a safety net
 * before Phase 2's task 2.5 (removing the router's regex overrides) is
 * attempted. Add a case here for every future accepted bug report before
 * fixing it, exactly like the plan asks.
 */
class AiGoldenSet extends Command
{
    protected $signature = 'ai:golden-set {--phone-prefix=29990000 : Prefix for the throwaway test phone numbers}';

    protected $description = 'Run a small live regression suite against WhatsappIntentRouter (Phase 4.1 starter set)';

    private int $passed = 0;
    private int $failed = 0;

    public function handle(): int
    {
        $bot = WhatsappBot::first();

        if (! $bot) {
            $this->error('No WhatsappBot row found - cannot create test conversations.');

            return self::FAILURE;
        }

        $cases = $this->cases();

        foreach ($cases as $i => $case) {
            $this->runCase($bot->id, (string) $this->option('phone-prefix') . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), $case);
        }

        $this->newLine();
        $this->line("Passed: {$this->passed} / " . ($this->passed + $this->failed));

        return $this->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Each case is [name, turns, assertions]. 'turns' is a list of customer
     * messages sent in order on the same conversation. 'assertions' checks
     * only the LAST turn's result: a list of [check, ...args] tuples.
     */
    private function cases(): array
    {
        return [
            [
                'name' => 'compound: price and images in one message',
                'turns' => ['سعر دايو ٤ وصورها'],
                'assertions' => [
                    ['contains_all', ['جنيه']],
                    ['images_at_least', 1],
                ],
            ],
            [
                'name' => 'compound: model + months + deposit + full breakdown (report\'s original example)',
                'turns' => ['مكنه هوجن جامبو عايزها علي سنه هدفع مقدم ٥ الاف وعاوزك تبعتلي تفاصيل التقسيط الكامل'],
                'assertions' => [
                    ['contains_all', ['شهر', 'جنيه']],
                    ['not_contains', ['تحب التقسيط على كام شهر']],
                ],
            ],
            [
                'name' => 'variant narrowing: اصلي returns only that variant, not the whole family',
                'turns' => ['صور دايو ٤ اصلي'],
                'assertions' => [
                    ['contains_all', ['اصلي']],
                    ['images_at_least', 1],
                ],
            ],
            [
                'name' => 'regression: plain family query (no variant) still returns the group',
                'turns' => ['سعر دايو ٤'],
                'assertions' => [
                    ['contains_all', ['دايو ٤']],
                ],
            ],
            [
                'name' => 'application: banned profession stops before documents are requested',
                'turns' => [
                    'عايز اقدم علي كي تي اكس',
                    'تقسيط',
                    'كيرلس ناجي',
                    '29912345678901',
                    '01012345678',
                    'انا محامي بمكتب في وسط البلد',
                ],
                'assertions' => [
                    ['contains_all', ['مش متاح']],
                    ['not_contains', ['صورة البطاقة', 'ابعتلي صورة']],
                ],
            ],
            [
                'name' => 'application: legitimate job_type is not rejected',
                'turns' => [
                    'عايز اقدم علي كي تي اكس',
                    'تقسيط',
                    'كيرلس ناجي',
                    '29912345678902',
                    '01012345679',
                    'انا موظف في شركة خاصة',
                ],
                'assertions' => [
                    ['not_contains', ['مش متاح لوظيفتك']],
                ],
            ],
            [
                'name' => 'pure greeting gets a reply, not a support handoff',
                'turns' => ['مساء الخير'],
                'assertions' => [
                    ['not_contains', ['هحولك دلوقتي لموظف']],
                    ['reply_not_empty'],
                ],
            ],
            [
                'name' => 'installment system question gets a real answer',
                'turns' => ['عايز اعرف نظام التقسيط بتاعكم'],
                'assertions' => [
                    ['contains_all', ['نظام']],
                ],
            ],
        ];
    }

    private function runCase(int $botId, string $phone, array $case): void
    {
        $conversation = WhatsappConversation::create([
            'whatsapp_bot_id' => $botId,
            'phone' => $phone,
            'status' => 'open',
        ]);

        $result = [];

        try {
            foreach ($case['turns'] as $message) {
                $result = app(WhatsappIntentRouter::class)->handle($conversation->fresh(), $message);
            }

            $failures = [];

            foreach ($case['assertions'] as $assertion) {
                $check = $assertion[0];
                $args = array_slice($assertion, 1);
                $error = $this->evaluate($check, $args, $result);

                if ($error !== null) {
                    $failures[] = $error;
                }
            }

            if (empty($failures)) {
                $this->passed++;
                $this->info("PASS  {$case['name']}");
            } else {
                $this->failed++;
                $this->error("FAIL  {$case['name']}");

                foreach ($failures as $f) {
                    $this->line("      - {$f}");
                }

                $this->line('      reply: ' . mb_substr((string) ($result['reply'] ?? '(none)'), 0, 200));
            }
        } catch (\Throwable $e) {
            $this->failed++;
            $this->error("FAIL  {$case['name']} (exception: {$e->getMessage()})");
        } finally {
            $conversation->messages()->delete();
            $conversation->delete();
        }
    }

    private function evaluate(string $check, array $args, array $result): ?string
    {
        $reply = (string) ($result['reply'] ?? '');

        return match ($check) {
            'contains_all' => $this->firstMissing($reply, $args[0]),
            'not_contains' => $this->firstPresent($reply, $args[0]),
            'reply_not_empty' => trim($reply) === '' ? 'reply was empty' : null,
            'images_at_least' => count($result['images'] ?? []) >= $args[0]
                ? null
                : 'expected >= ' . $args[0] . ' images, got ' . count($result['images'] ?? []),
            default => 'unknown check: ' . $check,
        };
    }

    private function firstMissing(string $haystack, array $needles): ?string
    {
        foreach ($needles as $needle) {
            if (! str_contains($haystack, $needle)) {
                return "expected reply to contain \"{$needle}\"";
            }
        }

        return null;
    }

    private function firstPresent(string $haystack, array $needles): ?string
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return "expected reply to NOT contain \"{$needle}\"";
            }
        }

        return null;
    }
}
