<?php

namespace App\Domain\Forms;

use App\Domain\Conversations\DeliveryService;
use App\Models\FormLink;
use App\Models\Handoff;
use App\Models\WhatsappMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Follows up a link the customer has not sent (spec §4.6): never while he is filling it in
 * or talking to us, never at night, at most two, the second a day after the first. What it
 * says depends on where he stopped: never opened, or the list of what is left.
 */
class FormLinkFollowUpService
{
    public function __construct(
        private readonly FormLinkService $links,
        private readonly DeliveryService $delivery,
    ) {
    }

    public function run(): int
    {
        $this->links->expireStale();

        if (! config('agent.enabled') || ! FormLinkService::formsMode() || $this->quietHours()) {
            return 0;
        }

        $sent = 0;

        FormLink::query()
            ->whereIn('status', FormLink::OPEN_STATUSES)
            ->where('followups_sent', '<', (int) config('agent.form_links.max_followups', 2))
            ->where('created_at', '>=', now()->subDays(8))
            ->with(['conversation.whatsappBot', 'version'])
            ->get()
            ->each(function (FormLink $link) use (&$sent) {
                try {
                    $sent += $this->followUp($link) ? 1 : 0;
                } catch (\Throwable $e) {
                    Log::warning('Form link follow-up failed', ['form_link_id' => $link->id, 'error' => $e->getMessage()]);
                }
            });

        return $sent;
    }

    public function followUp(FormLink $link): bool
    {
        $conversation = $link->conversation;

        if (! $conversation || $conversation->status !== 'open' || ! $conversation->whatsappBot?->is_active) {
            return false;
        }

        $silence = $this->lastActivity($link);
        $after = (int) config('agent.form_links.followup_after_minutes', 15);

        if ($silence->gt(now()->subMinutes($after))) {
            return false;
        }

        // the second (and last) waits a day after the first
        if ($link->followups_sent >= 1 && $link->last_followup_at
            && $link->last_followup_at->gt(now()->subHours((int) config('agent.form_links.second_followup_after_hours', 24)))) {
            return false;
        }

        // a colleague has the conversation, or staff wrote from the phone
        if (Handoff::where('conversation_id', $conversation->id)->whereNull('closed_at')->exists()
            || \App\Domain\Conversations\TurnScheduler::staffActive($conversation)) {
            return false;
        }

        $text = $this->message($link);
        $this->delivery->deliverForConversation($conversation, ['messages' => [$text]], 'bot');
        $link->update(['followups_sent' => $link->followups_sent + 1, 'last_followup_at' => now()]);

        return true;
    }

    /** The later of his last move on the form and his (or our) last WhatsApp message. */
    private function lastActivity(FormLink $link): Carbon
    {
        $lastMessage = WhatsappMessage::where('whatsapp_conversation_id', $link->whatsapp_conversation_id)->max('created_at');

        return collect([$link->created_at, $link->last_activity_at, $lastMessage ? Carbon::parse($lastMessage) : null])
            ->filter()->max();
    }

    public function message(FormLink $link): string
    {
        $progress = $this->links->progress($link);

        // a photo the check flagged is what stops him most - say exactly what is wrong with it
        $warned = $link->files()->where('check_status', 'warning')->whereNotNull('message')->latest('id')->first();

        if ($warned) {
            $label = $link->version->formSchema()->field($warned->field_key)['label'] ?? 'الصورة';

            return strtr((string) config('agent.form_links.followup_warned', 'فيه صورة محتاجة تتعدّل في طلبك ({label}): {problem} ارفعها تاني من نفس الرابط: {url}'), [
                '{label}' => $label, '{problem}' => $warned->message, '{url}' => $link->url(),
            ]);
        }

        if ($link->status === 'in_progress' && $progress['missing'] !== []) {
            $missing = collect($progress['missing'])->take(4)->implode('، ').(count($progress['missing']) > 4 ? ' وحاجات تانية بسيطة' : '');

            return strtr((string) config('agent.form_links.followup_partial'), ['{missing}' => $missing, '{url}' => $link->url()]);
        }

        return (string) config('agent.form_links.followup_not_opened');
    }

    private function quietHours(): bool
    {
        $hour = (int) now()->format('G');
        $from = (int) config('agent.applications.nudge_quiet_from', 23);
        $to = (int) config('agent.applications.nudge_quiet_to', 9);

        return $from > $to ? ($hour >= $from || $hour < $to) : ($hour >= $from && $hour < $to);
    }
}
