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

    public const MAX_NUMBERS = 3;

    public function __construct(public int $staffId, public string $text, public ?string $key = null, public ?string $phone = null)
    {
    }

    /** Owner 2026-11-27: «على رقمين» - his numbers, international form */
    public static function numbers(?string $stored): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($p) => DirectWhatsapp::phone($p), preg_split('/[,،\s]+/u', (string) $stored, -1, PREG_SPLIT_NO_EMPTY)
        ))));
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
        foreach (self::numbers($staff->notify_whatsapp) as $phone) {
            self::dispatch($staff->id, $text, $key ? $key.':'.$phone : null, $phone)->afterCommit();
        }
    }

    public function handle(DeliveryService $delivery): void
    {
        // still one of his numbers (he may have removed it since)
        $phone = $this->phone ?? (self::numbers(Staff::whereKey($this->staffId)->value('notify_whatsapp'))[0] ?? null);
        if (! $phone || ! in_array($phone, self::numbers(Staff::whereKey($this->staffId)->value('notify_whatsapp')), true)) {
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
