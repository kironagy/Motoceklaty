<?php

namespace App\Agent\Tools;

use App\Domain\Applications\CustomerDataService;
use App\Domain\Applications\SnapshotService;
use App\Models\Application;
use App\Models\Customer;
use App\Models\WhatsappMessage;

/** WRITE — plan §6.9 */
class RecordCustomerDataTool implements WriteTool
{
    public function __construct(
        private readonly CustomerDataService $customerData,
        private readonly SnapshotService $snapshots,
    ) {
    }

    public function name(): string
    {
        return 'record_customer_data';
    }

    public function description(): string
    {
        return 'Save facts the customer WROTE or SAID (text or voice), at any point in the conversation, asked or not. '
            .'Each value must appear in the customer\'s own messages - it is checked, and a value that only appears '
            .'in a photo/document is rejected (NOT_STATED_BY_CUSTOMER): documents go through process_document, '
            .'which records their real source. For a choice-type field (enum) put the customer\'s exact words you '
            .'relied on in `quote`. Not for motorcycle/plan/down payment selections (use update_application_selection).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['fields'],
            'properties' => [
                'fields' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 10,
                    'items' => [
                        'type' => 'object',
                        'required' => ['key', 'value'],
                        'properties' => [
                            // "phone_number" was sent for phone and the number was lost.
                            'key' => ['type' => 'string', 'enum' => $this->fieldKeys()],
                            // Gemini's function-calling schema (unlike JSON Schema) has no union
                            // types - a numeric answer arrives as a numeral string, which every
                            // field validator already expects (CustomerDataService casts to string).
                            'value' => ['type' => 'string'],
                            'quote' => ['type' => 'string', 'description' => 'The customer\'s exact words this value comes from. Required for enum fields.'],
                            'same_as_home' => ['type' => 'boolean', 'description' => 'work_address only: true when he said he works at/under his home (same address).'],
                            'none' => ['type' => 'boolean', 'description' => 'Address parts (building/floor/apartment/landmark) only: true when he said there is none or he does not know it; quote = his words.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return string[] */
    private function fieldKeys(): array
    {
        try {
            $keys = \App\Models\RequirementField::where('is_active', true)->orderBy('id')->pluck('key')->all();
        } catch (\Throwable) {
            $keys = [];
        }

        return $keys !== [] ? $keys : ['full_name'];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $customer = Customer::findOrFail($ctx->customerId);
        $application = $ctx->activeApplicationId ? Application::find($ctx->activeApplicationId) : null;

        // "work_landmark: قدام البنك الاهلي" - the key pasted into the value
        // made it look like something the customer never wrote.
        $fields = array_map(fn (array $f) => ['value' => preg_replace('/^\s*'.preg_quote((string) $f['key'], '/').'\s*[:=]\s*/u', '', (string) $f['value'])] + $f, $args['fields']);

        // Simulator 2026-10-05: an insured employee's second number was saved
        // as a guarantor's phone. A guarantor field his application does not
        // ask for is refused (structural: the requirement list decides).
        $notNeeded = [];

        // Simulator 2026-10-05: "رقمي X ورقم تاني Y" came as phone=X and phone=Y
        // in one call - Y overwrote his main number. One value per field.
        $seen = [];
        $fields = array_values(array_filter($fields, function (array $f) use (&$seen, &$notNeeded) {
            if (isset($seen[$f['key']])) {
                $notNeeded[] = ['key' => $f['key'], 'code' => 'ONE_VALUE_PER_FIELD'];

                return false;
            }

            return $seen[$f['key']] = true;
        }));

        if ($application) {
            $required = app(\App\Domain\Applications\RequirementService::class)
                ->requirementsFor($application->customerType, [])['fields'] ?? [];
            $requiredKeys = array_column($required, 'key');
            $fields = array_values(array_filter($fields, function (array $f) use ($requiredKeys, &$notNeeded) {
                if (str_starts_with((string) $f['key'], 'guarantor_') && ! in_array($f['key'], $requiredKeys, true)) {
                    $notNeeded[] = ['key' => $f['key'], 'code' => 'NOT_NEEDED_FOR_THIS_APPLICATION'];

                    return false;
                }

                return true;
            }));
        }

        $result = $fields === []
            ? ['saved' => [], 'rejected' => [], 'conflicts' => [], 'facts' => []]
            : $this->customerData->record($customer, $application, $fields, $ctx->conversationId);
        $result['rejected'] = array_merge($result['rejected'], $notNeeded);

        // Owner 2026-10-07: "رقمي X ولو مردتش ده رقم تاني Y" - Y was lost (one value per field).
        // His message holds both: the number that is not his main one is saved as phone_2.
        if (in_array('phone', $result['saved'], true) || in_array('phone', array_column($result['rejected'], 'key'), true)) {
            $main = preg_replace('/\D/', '', (string) (collect($fields)->firstWhere('key', 'phone')['value'] ?? ''));
            $said = strtr(app(\App\Agent\Runtime\ReplyGuard::class)->customerTextSinceLastReply(\App\Models\WhatsappConversation::findOrFail($ctx->conversationId)),
                ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
            preg_match_all('/(?<!\d)01[0125]\d{8}(?!\d)/', $said, $numbers);
            $other = collect($numbers[0])->unique()->first(fn ($n) => $n !== $main);

            if ($other !== null && $main !== '') {
                $second = $this->customerData->record($customer, $application, [['key' => 'phone_2', 'value' => $other, 'quote' => $other]], $ctx->conversationId);
                if (in_array('phone_2', $second['saved'], true)) {
                    $result['saved'] = array_merge($result['saved'], $second['saved']);
                    $result['rejected'] = array_values(array_filter($result['rejected'], fn ($r) => ! ($r['key'] === 'phone' && $r['code'] === 'ONE_VALUE_PER_FIELD')));
                }
            }
        }

        $data = [
            'saved' => $result['saved'],
            'rejected' => $result['rejected'],
            'conflicts' => $result['conflicts'],
        ];
        $snapshot = $application ? $this->snapshots->for($application->refresh()) : null;
        // TOOL-006: the compact status, not the whole snapshot again
        $data['application_now'] = SnapshotService::compact($snapshot);

        // Server 2026-10-04: 7 of 15 requests reached the dashboard with no
        // governorate or area. An address line with neither (and no district
        // that tells it) gets one short question for just that part.
        foreach ($fields as $field) {
            if (in_array($field['key'], ['address', 'work_address'], true) && in_array($field['key'], $result['saved'], true)
                && app(\App\Domain\Applications\AddressParser::class)->placeIn((string) $field['value']) === [null, null]) {
                $data['address_missing_place'][] = $field['key'];
            }
        }

        // Nothing saved is not a success: the model used to reply "سجلت"
        // on a result whose saved list was empty.
        if ($result['saved'] === []) {
            return ToolResult::error('NOTHING_SAVED', 'No field was saved. rejected/conflicts say why: NOT_STATED_BY_CUSTOMER = the customer never wrote this value '
                .'(if you read it from a photo, use process_document; otherwise ask the customer); QUOTE_NOT_FOUND = give the customer\'s exact words in quote; '
                .'CONFLICTS_WITH_VERIFIED_VALUE = a document/staff value differs - ask the customer which is right; '
                .'NOT_A_BUILDING_NUMBER / NOT_A_LANDMARK / NOT_AN_APARTMENT_NUMBER / NOT_A_FLOOR = that value is not one (e.g. "ورشة", or "شقة ملك" which is residence_ownership=owned) - save it in the right field or ask him; '
                .'SAME_AS_RESIDENCE = the work address equals his home address - if he works at home send it again with same_as_home=true, otherwise ask where he works; '
                .'DOCUMENT_ONLY = never taken from his words - it is read from the shop photo / tax card (process_document); never tell him it was saved. Details: '
                .json_encode(['rejected' => $result['rejected'], 'conflicts' => $result['conflicts']], JSON_UNESCAPED_UNICODE));
        }

        return ToolResult::ok($data);
    }
}
