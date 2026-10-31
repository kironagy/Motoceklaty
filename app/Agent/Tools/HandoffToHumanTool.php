<?php

namespace App\Agent\Tools;

use App\Domain\Handoff\HandoffService;
use App\Models\WhatsappConversation;

/** WRITE — plan §6.18. Thin: all logic lives in HandoffService. */
class HandoffToHumanTool implements WriteTool
{
    public function __construct(private readonly HandoffService $handoffService)
    {
    }

    public function name(): string
    {
        return 'handoff_to_human';
    }

    public function description(): string
    {
        return config('agent.handoff.tool_description') ?: 'Give the conversation to staff (the bot then stops, except call_request). Only when: he asks for a person or a call, '
            .'a complaint about a bike or money, he insists on a discount, IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER, or he insists on information we do not have.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => config('agent.handoff.customer_only') ? ['reason', 'note', 'request_quote'] : ['reason', 'note'],
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    // "not sure" and "out of scope" are not the owner's reasons to
                    // stop the bot - the model answers or asks instead.
                    'enum' => config('agent.handoff.customer_only') ? array_merge(['call_request', 'customer_request'], array_merge(config('agent.cash.sales_handoff') ? ['cash_purchase_confirmed'] : [], config('agent.cash.shipping_handoff') ? ['cash_shipping_request'] : [])) : ['call_request', 'customer_request', 'complaint', 'document_unresolvable', 'other'],
                    'description' => config('agent.handoff.tool_description') ?: 'call_request = he asks for a phone/voice call: staff call him and you KEEP answering him here meanwhile. customer_request = he asks for a person instead of you. other = he insists on a discount, IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER, or he insists on information we do not have to decide.',
                ],
                'motorcycle_id' => ['type' => 'integer'],
                'request_quote' => ['type' => 'string', 'description' => 'Exact quote from his current turn.'],
                'note' => ['type' => 'string', 'maxLength' => 300],
            ],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $conversation = WhatsappConversation::findOrFail($ctx->conversationId);

        if (($args['reason'] ?? '') === 'cash_shipping_request') return $this->cashShipping($args, $ctx, $conversation);

        if (($args['reason'] ?? '') === 'cash_purchase_confirmed') return $this->cashPurchase($args, $ctx, $conversation);

        if (config('agent.handoff.customer_only')) {
            if (! in_array($args['reason'] ?? '', ['customer_request', 'call_request'], true)) {
                return ToolResult::error('CUSTOMER_REQUEST_REQUIRED');
            }
            $message = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
                ->where('turn_id', $ctx->turnId)->where('sender_type', 'customer')->orderBy('id')->get(['text', 'transcript'])->map(fn ($row) => $row->text ?: $row->transcript)->implode("\n");
            if (! app(\App\Agent\Runtime\EvidenceReview::class)->customerRequestedHandoff(trim((string) ($args['request_quote'] ?? '')), $message)) {
                return ToolResult::error('CUSTOMER_REQUEST_REQUIRED');
            }
        }

        $this->handoffService->handOff($conversation, $args['reason'], $args['note'], source: 'ai');

        return ToolResult::ok(['handed_off' => true]);
    }
    private function cashShipping(array $args, ToolContext $ctx, WhatsappConversation $conversation): ToolResult
    {
        if (! config('agent.cash.shipping_handoff')) return ToolResult::error('CASH_SHIPPING_DISABLED');
        $quote = trim((string) ($args['request_quote'] ?? ''));
        $current = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
            ->where('turn_id', $ctx->turnId)->where('sender_type', 'customer')->orderBy('id')->get(['text','transcript'])
            ->map(fn ($m) => $m->text ?: $m->transcript)->implode("\n");
        if ($quote === '' || ! str_contains($current, $quote)) return ToolResult::error('CURRENT_SHIPPING_REQUEST_REQUIRED');
        $messages = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
            ->latest('id')->limit(20)->get(['direction','text','transcript'])->reverse()->values();
        if (! app(\App\Agent\Runtime\EvidenceReview::class)->judge('cash_shipping_request', [
            'policy'=>(string) config('agent.cash.shipping_review_instructions'),
            'current_message'=>$current, 'request_quote'=>$quote,
            'history'=>$messages->map(fn ($m) => ['role'=>$m->direction,'text'=>$m->text ?: $m->transcript])->all(),
        ])) return ToolResult::error('CASH_SHIPPING_NOT_CONFIRMED');
        if (blank(config('agent.cash.shipping_handoff_message')) || blank(config('agent.cash.shipping_handoff_topic'))) return ToolResult::error('CASH_POLICY_MISSING');
        $this->handoffService->cashShippingRequested($conversation);
        return ToolResult::ok(['handed_off'=>true,'handoff_reason'=>'cash_shipping_request',
            'customer_message'=>(string) config('agent.cash.shipping_handoff_message')]);
    }

    private function cashPurchase(array $args, ToolContext $ctx, WhatsappConversation $conversation): ToolResult
    {
        if (! config('agent.cash.sales_handoff')) return ToolResult::error('CASH_SALES_DISABLED');
        $machine = \App\Models\Machine::where('is_active', true)->find($args['motorcycle_id'] ?? 0);
        if (! $machine) return ToolResult::error('UNKNOWN_MOTORCYCLE');
        $quote = trim((string) ($args['request_quote'] ?? ''));
        $current = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
            ->where('turn_id', $ctx->turnId)->where('sender_type', 'customer')->orderBy('id')->get(['text','transcript'])
            ->map(fn ($m) => $m->text ?: $m->transcript)->implode("\n");
        if ($quote === '' || ! str_contains($current, $quote)) return ToolResult::error('CURRENT_PURCHASE_CONFIRMATION_REQUIRED');
        $messages = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
            ->latest('id')->limit(20)->get(['id','direction','text','transcript'])->reverse()->values();
        $firstCurrent = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)->where('turn_id', $ctx->turnId)->where('sender_type', 'customer')->min('id');
        $price = (float) ($machine->type === 'offer' && $machine->new_price ? $machine->new_price : $machine->cash_price);
        $priceQuoted = $messages->contains(function ($m) use ($machine, $price, $firstCurrent) {
            if ($m->direction !== 'outgoing' || $m->id >= $firstCurrent || $price <= 0) return false;
            $text = \App\Support\ArabicTextNormalizer::normalize((string) $m->text);
            return in_array((int) $machine->id, app(\App\Agent\Runtime\CatalogMentions::class)->fullNames($text), true)
                && preg_match('/(?<![0-9])'.preg_quote((string) (int) $price, '/').'(?![0-9])/u', str_replace([',','٬',' '], '', $text));
        });
        if (! $priceQuoted) return ToolResult::error('CASH_PRICE_MUST_BE_QUOTED_FIRST');
        if (! app(\App\Agent\Runtime\EvidenceReview::class)->judge('cash_purchase_confirmed', [
            'current_message'=>$current, 'confirmation_quote'=>$quote, 'motorcycle'=>$machine->name,
            'history'=>$messages->map(fn ($m) => ['role'=>$m->direction,'text'=>$m->text ?: $m->transcript])->all(),
        ])) return ToolResult::error('CASH_PURCHASE_NOT_CONFIRMED');
        if (blank(config('agent.cash.purchase_handoff_message'))) return ToolResult::error('CASH_POLICY_MISSING');
        $this->handoffService->cashPurchaseConfirmed($conversation, (int) $machine->id);
        return ToolResult::ok(['handed_off'=>true,'handoff_reason'=>'cash_purchase_confirmed',
            'customer_message'=>(string) config('agent.cash.purchase_handoff_message')]);
    }

}
