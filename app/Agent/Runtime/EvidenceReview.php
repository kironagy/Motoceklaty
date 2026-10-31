<?php
namespace App\Agent\Runtime;
use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiRequest;
use Illuminate\Support\Facades\Log;
/** Technical enforcement; policy and customer wording are dashboard data. */
class EvidenceReview
{
    private string $lastReason = '';
    public function lastReason(): string { return $this->lastReason; }
    public function enabled(): bool { return (bool) config('agent.safety.enabled', false); }
    public function uncertainty(): array
    {
        $text = trim((string) config('agent.safety.unknown_reply'));
        return ['messages' => $text === '' ? [] : [$text]];
    }
    public function check(array $args, string $system, array $outcomes, string $question = ''): ?string
    {
        if (config('agent.safety.reply_guard_mode') === 'advisory') return null;
        if (! $this->enabled() || ($args['messages'] ?? []) === []) return null;
        $reply = implode("\n", $args['messages']);
        $rules = json_decode((string) config('agent.safety.claim_rules', '[]'), true);
        if (! is_array($rules)) return 'INVALID_CLAIM_POLICY';
        foreach ($rules as $rule) {
            $pattern = $rule['pattern'] ?? '';
            if ($pattern === '' || @preg_match($pattern, '') === false) return 'INVALID_CLAIM_POLICY';
            if (preg_match($pattern, $reply) && ! $this->hasEvidence($outcomes, (string) ($rule['evidence_key'] ?? ''))) return 'CLAIM_WITHOUT_EVIDENCE: '.($rule['id'] ?? 'claim');
        }
        return $this->judge('reply', ['candidate' => $reply, 'sources' => $system, 'tool_results' => $outcomes, 'customer_question_context_only' => $question]) ? null : 'EVIDENCE_REVIEW_REJECTED';
    }
    private function hasEvidence(array $outcomes, string $key): bool
    {
        if ($key === '') return false;
        foreach ($outcomes as $o) if (($o['ok'] ?? false) && filled(data_get($o['data'] ?? [], $key))) return true;
        return false;
    }
    public function customerRequestedHandoff(string $quote, string $message): bool
    {
        // Authorize clear requests from the real current message, never the model's paraphrase.
        $text=\App\Support\ArabicTextNormalizer::normalize($message);
        $pattern=(string)config('agent.handoff.explicit_request_pattern');
        $negative=(string)config('agent.handoff.negative_request_pattern');
        if($pattern!=='' && @preg_match($pattern,'')!==false && preg_match($pattern,$text)
            && !($negative!=='' && @preg_match($negative,'')!==false && preg_match($negative,$text))) return true;
        return $quote !== '' && str_contains($message, $quote) && $this->judge('handoff', ['customer_message' => $message, 'request_quote' => $quote]);
    }
    public function judge(string $task, array $data): bool
    {
        $this->lastReason = '';
        $policy = trim((string) config(match ($task) { 'cash_purchase_confirmed'=>'agent.cash.purchase_review_instructions', 'cash_shipping_request'=>'agent.cash.shipping_review_instructions', 'reply_language'=>'agent.safety.language_review_instructions', default=>'agent.safety.review_instructions' }));
        if ($policy === '') return false;
        try {
            $response = app(AiProvider::class)->chat(new AiRequest(
                system: $policy,
                contents: [['role' => 'user', 'parts' => [['type' => 'text', 'text' => json_encode(['task' => $task, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]]],
                temperature: 0, maxOutputTokens: 256, timeoutSeconds: 12,
                responseSchema: ['type' => 'object', 'properties' => ['approved' => ['type' => 'boolean'], 'reason' => ['type' => 'string']], 'required' => ['approved', 'reason']],
                purpose: 'reply', label: 'evidence_review',
            ));
            $result = json_decode(implode('', $response->textParts), true, 16, JSON_THROW_ON_ERROR);
            $this->lastReason = is_string($result['reason'] ?? null) ? mb_substr($result['reason'], 0, 600) : 'INVALID_REVIEW_RESPONSE';
            return ($result['approved'] ?? null) === true && is_string($result['reason'] ?? null);
        } catch (\Throwable $e) {
            $this->lastReason = 'REVIEW_UNAVAILABLE';
            Log::warning('Reply evidence review unavailable', ['exception' => get_class($e)]);
            return false;
        }
    }
}
