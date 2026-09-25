<?php

namespace App\Agent\Tools;

use App\Domain\Applications\CustomerDataService;
use App\Domain\Applications\SnapshotService;
use App\Models\Application;
use App\Models\Customer;
use App\Models\WhatsappMessage;

/** WRITE — plan §6.9 */
class RecordCustomerDataTool implements Tool
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
                            'key' => ['type' => 'string'],
                            // Gemini's function-calling schema (unlike JSON Schema) has no union
                            // types - a numeric answer arrives as a numeral string, which every
                            // field validator already expects (CustomerDataService casts to string).
                            'value' => ['type' => 'string'],
                            'quote' => ['type' => 'string', 'description' => 'The customer\'s exact words this value comes from. Required for enum fields.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $customer = Customer::findOrFail($ctx->customerId);
        $application = $ctx->activeApplicationId ? Application::find($ctx->activeApplicationId) : null;

        $result = $this->customerData->record($customer, $application, $args['fields'], $ctx->conversationId);

        $data = [
            'saved' => $result['saved'],
            'rejected' => $result['rejected'],
            'conflicts' => $result['conflicts'],
            'snapshot' => $application ? $this->snapshots->for($application->refresh()) : null,
        ];

        // Nothing saved is not a success: the model used to reply "سجلت"
        // on a result whose saved list was empty.
        if ($result['saved'] === []) {
            return ToolResult::error('NOTHING_SAVED', 'No field was saved. rejected/conflicts say why: NOT_STATED_BY_CUSTOMER = the customer never wrote this value '
                .'(if you read it from a photo, use process_document; otherwise ask the customer); QUOTE_NOT_FOUND = give the customer\'s exact words in quote; '
                .'CONFLICTS_WITH_VERIFIED_VALUE = a document/staff value differs - ask the customer which is right. Details: '
                .json_encode(['rejected' => $result['rejected'], 'conflicts' => $result['conflicts']], JSON_UNESCAPED_UNICODE));
        }

        return ToolResult::ok($data);
    }
}
