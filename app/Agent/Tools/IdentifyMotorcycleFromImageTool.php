<?php

namespace App\Agent\Tools;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiProviderException;
use App\Agent\Providers\AiRequest;
use App\Domain\Catalog\CatalogService;
use App\Models\Machine;
use App\Models\MessageMedia;
use Illuminate\Support\Facades\Storage;

/** READ (caches result on the media row) — plan §6.4 */
class IdentifyMotorcycleFromImageTool implements Tool
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly CatalogService $catalog,
    ) {
    }

    public function name(): string
    {
        return 'identify_motorcycle_from_image';
    }

    public function description(): string
    {
        return 'Map a customer photo to catalog candidates. Use when the customer sends a motorcycle photo '
            .'(shown as "[صورة مرفقة - media_id: N]") and asks about it. band=match: you may name the model. '
            .'band=similar: say it looks like / resembles the candidates and ask or offer them - never state it IS that model or quote its price as the photo\'s price. '
            .'band=unknown: say you could not tell and ask for the name. '
            .'band=not_in_catalog: the photo is observed.model, which we do NOT carry - say plainly what it is and "للأسف مش متوفرة عندنا حاليا", '
            .'never call it one of our models and never guess when it will be available; you may offer the candidates as alternatives. '
            .'Do not use for documents (use process_document).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['media_id'],
            'properties' => [
                'media_id' => ['type' => 'integer'],
            ],
        ];
    }

    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $media = MessageMedia::with('message')->find($args['media_id']);

        if (! $media || $media->message?->whatsapp_conversation_id !== $ctx->conversationId) {
            return ToolResult::error('MEDIA_NOT_FOUND', 'No such image in this conversation. If this message has no attachment, '
                .'work out what "دي" means from the quoted message and the recent conversation - do not tell the customer an image failed.');
        }

        if ($media->media_type !== 'image' && $media->media_type !== 'sticker') {
            return ToolResult::error('NOT_AN_IMAGE');
        }

        $catalogList = Machine::query()->where('is_active', true)->with('brand')->get()
            ->map(fn (Machine $m) => "{$m->id}: ".trim(($m->brand?->name ?? '').' '.$m->name))
            ->implode("\n");

        // "Qg SRK 250" was the photo's caption and the bike's real model;
        // it was matched to our Rk200 R and the customer was told so three
        // times. What the customer wrote with the photo is evidence too.
        $caption = trim((string) ($media->message?->text ?? ''));

        try {
            $response = $this->provider->chat(new AiRequest(
                system: "You identify a motorcycle photo against this catalog (id: name), one per line:\n{$catalogList}\n\n"
                    .'Identify the MAIN motorcycle only: the one in the centre / in focus / taking most of the frame. '
                    .'Set vehicle_count to how many motorcycles or scooters are visible (even partly). '
                    .'Report how you know: identified_by = "visible_name_or_logo" only if the model name or brand is actually readable on the MAIN bike itself - '
                    .'a logo on a neighbouring bike says nothing about the main one; '
                    .'"shape_only" if you are judging by shape/colour/design; "not_a_motorcycle" if the image is not a motorcycle. '
                    .'Many models share a body - by shape alone, list every plausible candidate with honest (lower) confidence. '
                    .'Also say what the bike really is: observed.model = the exact make and model you recognise (from a readable name, the caption, '
                    .'or its distinctive design - e.g. "QJ Motor SRK 250"), even when it is not in the catalog; empty if you cannot tell. '
                    .'in_catalog = false when that model is not one of the catalog lines (a sibling model of the same brand is NOT the same model - '
                    .'SRK 250 is not Rk200 R); then candidates are only look-alike alternatives with low confidence.'
                    .($caption !== '' ? "\n\nThe customer wrote with the photo: \"{$caption}\"" : ''),
                contents: [
                    ['role' => 'user', 'parts' => [
                        ['type' => 'inline_media', 'mime' => $media->mime, 'base64' => base64_encode(Storage::disk($media->disk)->get($media->path))],
                    ]],
                ],
                toolMode: 'none',
                temperature: 0.1,
                responseSchema: [
                    'type' => 'object',
                    'properties' => [
                        'candidates' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'motorcycle_id' => ['type' => 'integer'],
                                    'confidence' => ['type' => 'number'],
                                ],
                            ],
                        ],
                        'identified_by' => ['type' => 'string', 'enum' => ['visible_name_or_logo', 'shape_only', 'not_a_motorcycle']],
                        'in_catalog' => ['type' => 'boolean'],
                        'vehicle_count' => ['type' => 'integer'],
                        'observed' => [
                            'type' => 'object',
                            'properties' => [
                                'brand' => ['type' => 'string'],
                                'model' => ['type' => 'string'],
                                'style' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ));
        } catch (AiProviderException) {
            return ToolResult::error('VISION_UNAVAILABLE');
        }

        $parsed = json_decode(implode('', $response->textParts), true) ?? [];
        $activeIds = Machine::where('is_active', true)->pluck('id')->all();

        $candidates = collect($parsed['candidates'] ?? [])
            ->filter(fn ($c) => in_array((int) ($c['motorcycle_id'] ?? 0), $activeIds, true))
            ->map(fn ($c) => [
                'motorcycle_id' => (int) $c['motorcycle_id'],
                'name' => Machine::find($c['motorcycle_id'])->name,
                'confidence' => (float) ($c['confidence'] ?? 0),
            ])
            ->sortByDesc('confidence')
            ->values();

        $identifiedBy = $parsed['identified_by'] ?? 'shape_only';

        if ($identifiedBy === 'not_a_motorcycle') {
            $candidates = collect();
        }

        $band = $this->band($candidates->first()['confidence'] ?? 0);

        // The official H250 photo came back as "VLM 200, 0.95, match": a
        // confidence the model gives itself for a front shot with no name on
        // it. An exact model needs visible evidence; by shape alone the most
        // this can be is "similar", and the agent must say so.
        if ($band === 'match' && $identifiedBy !== 'visible_name_or_logo') {
            $band = 'similar';
        }

        // Live: a photo of a row of scooters came back "vigorey, logo, match"
        // - the logo was on the scooter beside the one the customer meant
        // (a Demora). With several bikes in frame the photo cannot pin one
        // model down; the agent has to ask which one.
        $vehicleCount = (int) ($parsed['vehicle_count'] ?? 1);

        if ($band === 'match' && $vehicleCount > 1) {
            $band = 'similar';
        }

        // The model it recognised is not one we carry: never "this is our X".
        $observedModel = trim((string) ($parsed['observed']['model'] ?? ''));

        if (($parsed['in_catalog'] ?? true) === false && $observedModel !== '' && $identifiedBy !== 'not_a_motorcycle') {
            $band = 'not_in_catalog';
            $candidates = $candidates->map(fn ($c) => ['confidence' => min($c['confidence'], 0.4)] + $c);
        }

        $result = [
            'band' => $band,
            'identified_by' => $identifiedBy,
            'candidates' => $candidates->all(),
            'observed' => $parsed['observed'] ?? [],
        ] + ($vehicleCount > 1 ? [
            'vehicle_count' => $vehicleCount,
            'note' => 'Several motorcycles are in this photo. Do not claim which model it is: name the likely candidate(s) '
                .'and ask which bike in the photo the customer means.',
        ] : []);

        $media->update(['analysis' => $result]);

        return ToolResult::ok($result);
    }

    /** DEC-05: no defaults. Until the owner sets thresholds, never overclaim - always 'unknown'. */
    private function band(float $confidence): string
    {
        $matchThreshold = config('agent.recognition.match_threshold');
        $similarThreshold = config('agent.recognition.similar_threshold');

        if ($matchThreshold === null || $similarThreshold === null) {
            return 'unknown';
        }

        if ($confidence >= (float) $matchThreshold) {
            return 'match';
        }

        if ($confidence >= (float) $similarThreshold) {
            return 'similar';
        }

        return 'unknown';
    }
}
