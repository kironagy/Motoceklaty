<?php

namespace App\Domain\Applications;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiProviderException;
use App\Agent\Providers\AiRequest;
use Illuminate\Support\Facades\Log;

/**
 * The deliveries form has one field per address part (governorate, area,
 * street, branch street, building, floor, apartment, landmark). The bot
 * collects the address as the customer writes it - "مدينه بدر مميز الجمعه
 * الروسيه الحي التاني عماره 2 شقه 41" landed whole in the street field and
 * the apartment in the floor field. This reads the customer's own words and
 * puts each part where it belongs; the governorate is inferred from a known
 * city/area when he did not name it (مدينة بدر → القاهرة).
 */
class AddressSplitter
{
    public const PARTS = ['governorate', 'area', 'street', 'branch_street', 'building_number', 'floor', 'apartment', 'landmark'];

    public function __construct(private readonly AiProvider $ai)
    {
    }

    /**
     * @param  array<string, string>  $stored  what the bot saved (address, building_no, floor, apartment, landmark)
     * @param  string[]  $customerMessages  the customer's own messages the values came from
     * @return array<string, string> part => value, only the parts that are known
     */
    public function split(array $stored, array $customerMessages): array
    {
        $stored = array_filter($stored, fn ($v) => trim((string) $v) !== '');

        if ($stored === [] && $customerMessages === []) {
            return [];
        }

        try {
            $response = $this->ai->chat(new AiRequest(
                system: 'You split an Egyptian address into the fields of a finance application form. '
                    .'Use only what the customer wrote; never invent a number, street or landmark. '
                    .'governorate = the Egyptian governorate in Arabic (القاهرة، الجيزة، القليوبية ...) - infer it from a well known city/district '
                    .'(مدينة بدر، مدينة نصر، العبور، الشروق، التجمع → القاهرة؛ الهرم، فيصل، ٦ أكتوبر → الجيزة؛ شبرا الخيمة، الخصوص → القليوبية) when he did not say it. '
                    .'area = the district / city / neighbourhood (e.g. "مدينة بدر - الحي التاني"). street = the street name. '
                    .'branch_street = the street it branches from ("متفرع من ..."). building_number = the building/house number (عمارة/عقار/بيت). '
                    .'floor = the floor only (دور). apartment = the apartment number only (شقة) - "شقة 41" is an apartment, never a floor. '
                    .'landmark = the nearby known place (جنب/أمام/خلف ...). Fix obvious typos of well known places only (الجمعه الروسيه → الجامعة الروسية). '
                    .'Leave a field empty when it is not given.',
                contents: [[
                    'role' => 'user',
                    'parts' => [['type' => 'text', 'text' => json_encode([
                        'saved_fields' => $stored,
                        'customer_messages' => array_values($customerMessages),
                    ], JSON_UNESCAPED_UNICODE)]],
                ]],
                toolMode: 'none',
                temperature: 0.0,
                maxOutputTokens: 600,
                thinkingBudget: 0,
                // Runs inside the submission transaction - never hold it long.
                timeoutSeconds: 15,
                responseSchema: [
                    'type' => 'object',
                    'properties' => collect(self::PARTS)->mapWithKeys(fn ($p) => [$p => ['type' => 'string']])->all(),
                ],
            ));
        } catch (AiProviderException|\Throwable $e) {
            Log::warning('AddressSplitter failed', ['error' => $e->getMessage()]);

            return [];
        }

        $parsed = json_decode(implode('', $response->textParts), true);

        if (! is_array($parsed)) {
            return [];
        }

        return array_filter(
            array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', array_intersect_key($parsed, array_flip(self::PARTS))),
            fn ($v) => $v !== '' && ! in_array(mb_strtolower($v), ['null', 'none', 'n/a', '-', 'غير محدد'], true)
        );
    }
}
