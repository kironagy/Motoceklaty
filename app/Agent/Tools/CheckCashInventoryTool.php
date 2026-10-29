<?php

namespace App\Agent\Tools;

use App\Agent\Runtime\EvidenceReview;
use App\Domain\Catalog\CashInventoryService;
use App\Domain\Handoff\HandoffService;
use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;

/** Live stock lookup; the owner-approved zero-stock cash route can open a handoff. */
class CheckCashInventoryTool implements WriteTool
{
    public function name(): string { return 'check_cash_inventory'; }
    public function description(): string
    {
        return (string) config('agent.cash.tool_description');
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['motorcycle_id'], 'properties' => [
            'motorcycle_id' => ['type' => 'integer'],
        ]];
    }
    public function permission(): string { return 'WRITE'; }
    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $machine = Machine::where('is_active', true)->find($args['motorcycle_id']);
        if (! $machine) return ToolResult::error('UNKNOWN_MOTORCYCLE');
        $stock = app(CashInventoryService::class)->forMachine((int) $machine->id);
        $data = ['motorcycle' => $machine->name, 'cash_price' => (float) ($machine->type === 'offer' && $machine->new_price ? $machine->new_price : $machine->cash_price)] + $stock;
        if (! $stock['available'] && config('agent.cash.inventory_enabled')) {
            if (blank(config('agent.cash.unavailable_message'))) return ToolResult::error('CASH_POLICY_MISSING');
            $messages = WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
                ->where('sender_type', 'customer')->orderByDesc('id')->limit(12)->get(['text','transcript'])->reverse()
                ->map(fn ($row) => $row->text ?: $row->transcript)->filter()->values()->all();
            if (app(EvidenceReview::class)->judge('cash_intent', ['customer_messages' => $messages, 'motorcycle' => $machine->name])) {
                $handoff = app(HandoffService::class)->cashStockUnavailable(WhatsappConversation::findOrFail($ctx->conversationId), (int) $machine->id);
                if ($handoff) {
                    $data['handed_off'] = true;
                    $data['handoff_reason'] = 'cash_stock_unavailable';
                    $data['customer_message'] = str_replace('{motorcycle}', $machine->name, (string) config('agent.cash.unavailable_message'));
                } else {
                    $data = array_replace($data, app(CashInventoryService::class)->forMachine((int) $machine->id));
                }
            }
        }
        return ToolResult::ok($data);
    }
}
