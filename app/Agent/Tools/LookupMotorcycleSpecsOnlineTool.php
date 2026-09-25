<?php

namespace App\Agent\Tools;

use App\Models\Machine;
use App\Services\GeminiClient;
use Illuminate\Support\Facades\Cache;

/**
 * READ. Specs the showroom did not enter, looked up online for the exact
 * version only. "مقاس الكوتش كام؟" on an F250 with an empty spec sheet got
 * "the branch will tell you" - or, worse, numbers from a similar model.
 * A result is only accepted when the source matches brand, model, cc AND
 * model year; anything less comes back found=false.
 */
class LookupMotorcycleSpecsOnlineTool implements Tool
{
    private const FOUND_TTL_DAYS = 30;

    private const NOT_FOUND_TTL_HOURS = 24;

    /** After a failed search (no grounding quota on the key), skip retrying for a while. */
    private const UNAVAILABLE_KEY = 'spec-lookup:unavailable';

    private const UNAVAILABLE_TTL_MINUTES = 10;

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function name(): string
    {
        return 'lookup_motorcycle_specs_online';
    }

    public function description(): string
    {
        return 'Looks up specs of one catalog motorcycle on the internet, for its exact version (brand + model + cc + model year). '
            .'Use ONLY when the customer asks for a spec and get_motorcycle_details (this turn) has no answer for it in '
            .'`specifications`, `features` or `description`. Pass the specs he asked for, in his words. '
            .'found=false means the exact version could not be confirmed: do not state any value for those specs.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['motorcycle_id', 'specs'],
            'properties' => [
                'motorcycle_id' => ['type' => 'integer'],
                'specs' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 8,
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $machine = Machine::query()->where('is_active', true)->with('brand')->find($args['motorcycle_id']);

        if (! $machine) {
            return ToolResult::error('UNKNOWN_MOTORCYCLE', 'Unknown id: '.$args['motorcycle_id']);
        }

        $missing = array_keys(array_filter([
            'brand' => blank($machine->brand?->name),
            'cc' => blank($machine->cc),
            'model_year' => blank($machine->model_year),
        ]));

        // Without cc and year a search cannot tell this version from its
        // look-alikes, and a similar model's numbers are worse than none.
        if ($missing !== []) {
            return ToolResult::ok([
                'found' => false,
                'reason' => 'VERSION_NOT_DEFINED',
                'missing_in_dashboard' => $missing,
                'note' => 'The exact version is not defined, so nothing was looked up. Do not state any value for these specs; '
                    .'say the branch confirms them.',
            ]);
        }

        $specs = array_values(array_unique(array_map('trim', $args['specs'])));
        sort($specs);
        $key = 'spec-lookup:'.$machine->id.':'.$machine->updated_at?->timestamp.':'.md5(implode('|', $specs));

        if (($cached = Cache::get($key)) !== null) {
            return ToolResult::ok($cached);
        }

        $unavailable = ToolResult::error('LOOKUP_UNAVAILABLE', 'The online lookup failed. Do not state any value for these specs; say the branch confirms them.');

        if (Cache::has(self::UNAVAILABLE_KEY)) {
            return $unavailable;
        }

        $response = $this->gemini->generateText($this->prompt($machine, $specs), config('gemini.models.fast', 'gemini-3.1-flash-lite'), [
            'tools' => [['google_search' => new \stdClass]],
            'temperature' => 0.1,
            'maxOutputTokens' => 1024,
            'timeout' => 20,
            'quotaIsolated' => true,
        ]);

        if (! ($response['ok'] ?? false)) {
            Cache::put(self::UNAVAILABLE_KEY, true, now()->addMinutes(self::UNAVAILABLE_TTL_MINUTES));

            return $unavailable;
        }

        $result = $this->result($machine, $specs, $this->parse((string) ($response['text'] ?? $response['reply'] ?? '')), $response['sources'] ?? []);

        Cache::put($key, $result, $result['found']
            ? now()->addDays(self::FOUND_TTL_DAYS)
            : now()->addHours(self::NOT_FOUND_TTL_HOURS));

        return ToolResult::ok($result);
    }

    private function prompt(Machine $machine, array $specs): string
    {
        $asked = implode("\n", array_map(fn ($s) => "- {$s}", $specs));

        return <<<PROMPT
        Use Google Search to find the manufacturer specifications of this exact motorcycle:
        Brand: {$machine->brand->name}
        Model: {$machine->name}
        Engine displacement: {$machine->cc} cc
        Model year: {$machine->model_year}

        Specs requested (answer each by the same name, as asked):
        {$asked}

        Rules:
        - Only use sources about this exact brand and model, with the same engine displacement and the same model year (or a source that explicitly covers that year).
        - A different year, a different engine size, or a similarly named model does NOT count - then that spec is not found.
        - Never estimate, round or infer a value. Leave out any spec you could not confirm.
        - Write each value in Egyptian Arabic with its unit (e.g. "11.5 لتر", "90/90-18").

        Reply with ONLY this JSON, no other text:
        {"found": true|false, "matched_model": "...", "matched_cc": 0, "matched_year": 0, "specs": [{"name": "...", "value": "..."}]}
        PROMPT;
    }

    private function parse(string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        return json_decode(substr($text, $start, $end - $start + 1), true) ?? [];
    }

    private function result(Machine $machine, array $asked, array $parsed, array $sources): array
    {
        $specs = collect($parsed['specs'] ?? [])
            ->filter(fn ($s) => filled($s['name'] ?? null) && filled($s['value'] ?? null))
            ->map(fn ($s) => ['name' => (string) $s['name'], 'value' => (string) $s['value']])
            ->values();

        // The model's own claim of a match is checked, not trusted.
        $exactVersion = (int) ($parsed['matched_cc'] ?? 0) === (int) $machine->cc
            && (int) ($parsed['matched_year'] ?? 0) === (int) $machine->model_year;

        if (! ($parsed['found'] ?? false) || ! $exactVersion || $specs->isEmpty() || $sources === []) {
            return [
                'found' => false,
                'reason' => 'EXACT_VERSION_NOT_CONFIRMED',
                'note' => 'No source was found for this exact version. Do not state any value for these specs; say the branch confirms them.',
            ];
        }

        return [
            'found' => true,
            'version' => [
                'brand' => $machine->brand->name,
                'model' => $machine->name,
                'cc' => (int) $machine->cc,
                'model_year' => (int) $machine->model_year,
            ],
            'specs' => $specs->all(),
            'not_found' => array_values(array_diff($asked, $specs->pluck('name')->all())),
            'sources' => array_slice($sources, 0, 3),
            'note' => 'Manufacturer specs from the internet for this exact version, not from the showroom sheet. '
                .'Say them as the manufacturer\'s specs ("حسب مواصفات الشركة"). For anything in not_found, give no value.',
        ];
    }
}
