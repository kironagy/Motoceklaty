<?php

namespace App\Domain\Applications;

use App\Domain\Conversations\DeliveryService;
use App\Models\Application;
use App\Models\Handoff;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * A customer who goes quiet half way through an application is usually
 * busy, not gone. One short line naming the single thing left ("فاضل بس
 * صورة البطاقة") brings him back; a second one a day later, then silence.
 * Off until agent.applications.nudge_after_minutes is set.
 */
class ApplicationNudgeService
{
    public const MAX_PER_SILENCE = 2;

    public function __construct(
        private readonly SnapshotService $snapshots,
        private readonly DeliveryService $delivery,
    ) {
    }

    public function nudgeStalled(): int
    {
        $after = config('agent.applications.nudge_after_minutes');

        if ($after === null || $after === '' || ! config('agent.enabled') || $this->quietHours()) {
            return 0;
        }

        $sent = 0;

        Application::query()
            ->where('status', 'collecting')
            ->where('updated_at', '>=', now()->subDays(3))
            ->with('customer')
            ->get()
            ->each(function (Application $application) use ($after, &$sent) {
                try {
                    $sent += $this->nudge($application, (int) $after) ? 1 : 0;
                } catch (\Throwable $e) {
                    Log::warning('Application nudge failed', ['application_id' => $application->id, 'error' => $e->getMessage()]);
                }
            });

        return $sent;
    }

    private function nudge(Application $application, int $afterMinutes): bool
    {
        $conversation = WhatsappConversation::with('whatsappBot')->find($application->origin_conversation_id);

        if (! $conversation || $conversation->status !== 'open' || ! $conversation->whatsappBot?->is_active) {
            return false;
        }

        $last = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->latest('id')->first();

        // Only when the ball is in the customer's court: our message was last.
        if (! $last || $last->direction !== 'outgoing' || $last->created_at->gt(now()->subMinutes($afterMinutes))) {
            return false;
        }

        $state = $conversation->state ?? [];
        $nudges = $state['application_nudges'] ?? [];
        $lastCustomerAt = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'incoming')->max('created_at');

        // He was told "زميلي هيرد عليك": the silence is ours, not his. A
        // customer handed off over his ID got "ابعتلي نوع الشغل" 14 hours
        // later, right after staff returned the conversation.
        // Nudging starts again only once he has written after the handoff ended.
        $handoff = Handoff::where('conversation_id', $conversation->id)->latest('opened_at')->first();

        if ($handoff && (! $lastCustomerAt || Carbon::parse($lastCustomerAt)->lte($handoff->closed_at ?? $handoff->opened_at))) {
            return false;
        }

        // A reply from the customer starts a new silence.
        if (($nudges['application_id'] ?? null) !== $application->id
            || ($lastCustomerAt && isset($nudges['last_at']) && Carbon::parse($lastCustomerAt)->gt(Carbon::parse($nudges['last_at'])))) {
            $nudges = ['application_id' => $application->id, 'count' => 0];
        }

        if ($nudges['count'] >= self::MAX_PER_SILENCE) {
            return false;
        }

        // The second one waits a day after the first.
        if ($nudges['count'] === 1 && Carbon::parse($nudges['last_at'])->gt(now()->subDay())) {
            return false;
        }

        $text = $this->message($application, $nudges['count']);

        if ($text === null) {
            return false;
        }

        $this->delivery->deliverForConversation($conversation, ['messages' => [$text]], 'bot');

        $state['application_nudges'] = ['application_id' => $application->id, 'count' => $nudges['count'] + 1, 'last_at' => now()->toIso8601String()];
        $conversation->update(['state' => $state]);

        return true;
    }

    public function message(Application $application, int $alreadySent): ?string
    {
        $snapshot = $this->snapshots->for($application);
        $step = $snapshot['next_step'] ?? null;

        if (! $step) {
            return null;
        }

        if ($alreadySent > 0) {
            $model = $application->machine?->name;

            return 'لو لسه حابب'.($model ? " الـ{$model}" : '').' أنا موجود، نكمّل طلبك من مكان ما وقفنا 🙌';
        }

        if ($step['type'] === 'submit') {
            return 'لسه معايا؟ 🙏 كل حاجة جاهزة، قولي بس "تمام" وأبعت طلبك.';
        }

        $remaining = $snapshot['progress']['remaining'] ?? 4;
        $what = match (true) {
            $step['key'] === 'national_id_front' => 'صورة وش البطاقة',
            $step['key'] === 'duration' => 'تختار مدة التقسيط',
            default => $step['label'],
        };

        return match (true) {
            $remaining <= 1 => "لسه معايا؟ 🙏 طلبك قرب يخلص، فاضل بس {$what} ونكمّل.",
            $remaining <= 3 => "لسه معايا؟ 🙏 طلبك ماشي، الخطوة الجاية {$what} - وفاضل {$remaining} حاجات بس.",
            default => "لسه معايا؟ 🙏 طلبك ماشي، ابعتلي بس {$what} ونكمّل من هنا.",
        };
    }

    /** No messages at night: 23:00-09:00 Cairo time by default. */
    private function quietHours(): bool
    {
        $hour = (int) now()->format('G');
        $from = (int) config('agent.applications.nudge_quiet_from', 23);
        $to = (int) config('agent.applications.nudge_quiet_to', 9);

        return $from > $to ? ($hour >= $from || $hour < $to) : ($hour >= $from && $hour < $to);
    }
}
