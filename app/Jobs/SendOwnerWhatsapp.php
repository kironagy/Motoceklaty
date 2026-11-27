<?php

namespace App\Jobs;

use App\Domain\Conversations\DeliveryService;
use App\Domain\Conversations\DirectWhatsapp;
use App\Models\Staff;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Owner 2026-11-27: «عصفورة»'s reports on the owner's own WhatsApp (staff.notify_whatsapp), from a
 * connected bot number. That chat is marked owner_alerts so the bot never answers him there.
 */
class SendOwnerWhatsapp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const STATUS = 'owner_alerts';

    public int $timeout = 600;

    public int $backoff = 60;

    public int $maxExceptions = 5;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    public function __construct(public int $staffId, public string $text, public ?string $key = null)
    {
    }

    /** a chat answer as WhatsApp text: no tables/headings marks, at most ~1500 characters */
    public static function plain(string $markdown): string
    {
        $t = preg_replace('/```.*?```/su', '', $markdown);
        $t = preg_replace('/^\s*\|?[-:| ]+\|?\s*$/mu', '', $t);
        $t = preg_replace(['/\*\*(.+?)\*\*/u', '/^#+\s*/mu', '/`/u'], ['*$1*', '', ''], $t);
        $t = trim(preg_replace("/\n{3,}/u", "\n\n", $t));

        return mb_strlen($t) > 1500 ? mb_substr($t, 0, 1500).'…' : $t;
    }

    public static function to(Staff $staff, string $text, ?string $key = null): void
    {
        if (DirectWhatsapp::phone($staff->notify_whatsapp)) {
            self::dispatch($staff->id, $text, $key)->afterCommit();
        }
    }

    public function handle(DeliveryService $delivery): void
    {
        $phone = DirectWhatsapp::phone(Staff::whereKey($this->staffId)->value('notify_whatsapp'));
        if (! $phone) {
            return;
        }
        $conversation = DirectWhatsapp::conversation($phone, self::STATUS);
        if (! $conversation) {
            Log::warning('SendOwnerWhatsapp: no WhatsApp number connected', ['staff_id' => $this->staffId]);
            $this->release(300);

            return;
        }
        $delivery->deliverForConversation($conversation, ['messages' => [$this->text]], 'system',
            requireAgentEnabled: false, dedupeKey: $this->key ? 'owner:'.$this->key : null);
    }
}
