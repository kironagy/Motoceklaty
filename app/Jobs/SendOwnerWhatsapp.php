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

    /** @param  int|null  $messageId  the «عصفورة» message the report is; its tables go with it as an Excel file */
    public function __construct(public int $staffId, public string $text, public ?string $key = null, public ?string $phone = null, public ?int $messageId = null)
    {
    }

    /** Owner 2026-11-27: «على رقمين» - his numbers, international form */
    public static function numbers(?string $stored): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($p) => DirectWhatsapp::phone($p), preg_split('/[,،\s]+/u', (string) $stored, -1, PREG_SPLIT_NO_EMPTY)
        ))));
    }

    /** rows of a table shown in the WhatsApp text; the rest are in the Excel file */
    public const TEXT_ROWS = 8;

    /**
     * A chat answer as WhatsApp text, readable on a phone (owner 2026-11-27: a markdown table came
     * out as one long line of «|»). Each table row becomes its own small block, one «field: value»
     * per line; bold marks get the spaces WhatsApp needs to show them.
     */
    public static function plain(string $markdown, bool $withFile = false): string
    {
        $t = preg_replace('/```.*?```/su', '', $markdown);
        $t = self::twelveHour($t);
        $t = self::tablesAsBlocks($t, $withFile);
        $t = preg_replace(['/^#+\s*/mu', '/`/u', '/^\s*[-*]\s+/mu'], ['', '', '• '], $t);
        // **x** → *x*, with a space when a letter touches it («و**#5874**» showed the stars)
        $t = preg_replace('/\*\*\s*(.+?)\s*\*\*/u', "\x01$1\x02", $t);
        $t = preg_replace(['/(?<=[\p{L}\p{N}])\x01/u', '/\x02(?=[\p{L}\p{N}])/u'], [" \x01", "\x02 "], $t);
        $t = str_replace(["\x01", "\x02"], '*', $t);
        $t = trim(preg_replace(["/[ \t]+\n/u", "/\n{3,}/u"], ["\n", "\n\n"], $t));

        return mb_strlen($t) > 3500 ? mb_substr($t, 0, 3500).'…' : $t;
    }

    /** Owner 2026-11-27: «بنظام الـ12 ساعة مش الـ24» - 16:30 → 4:30 م, 09:05:12 → 9:05 ص */
    public static function twelveHour(string $text): string
    {
        return preg_replace_callback('/(?<![\d:])([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?(?![\d:])(?!\s*(?:ص|م|[AaPp]\.?[Mm]))/u', function ($m) {
            $h = (int) $m[1];

            return (($h % 12) ?: 12).':'.$m[2].' '.($h < 12 ? 'ص' : 'م');
        }, $text);
    }

    /** the markdown tables of a text: [header cells, rows of cells] each */
    public static function tables(string $markdown): array
    {
        $tables = [];
        foreach (self::tableBlocks($markdown) as [, $lines]) {
            $rows = array_map(fn ($l) => array_map('trim', explode('|', trim(trim($l), '|'))), $lines);
            $rows = array_values(array_filter($rows, fn ($r) => ! preg_match('/^[-:\s|]*$/u', implode('|', $r))));
            if (count($rows) >= 2) {
                $tables[] = [array_shift($rows), $rows];
            }
        }

        return $tables;
    }

    /** runs of lines starting with «|»: [the run's text, its lines] */
    private static function tableBlocks(string $text): array
    {
        preg_match_all('/(?:^[ \t]*\|.*(?:\n|$))+/mu', $text, $m);

        return array_map(fn ($block) => [$block, array_values(array_filter(explode("\n", $block), fn ($l) => trim($l) !== ''))], $m[0]);
    }

    private static function tablesAsBlocks(string $text, bool $withFile): string
    {
        foreach (self::tableBlocks($text) as [$block]) {
            $table = self::tables($block)[0] ?? null;
            if (! $table) {
                continue;
            }
            [$head, $rows] = $table;
            $out = [];
            foreach (array_slice($rows, 0, self::TEXT_ROWS) as $n => $cells) {
                $lines = [];
                foreach ($cells as $i => $value) {
                    $value = trim(str_replace('**', '', $value));
                    if ($value === '' || $value === '-') {
                        continue;
                    }
                    $label = trim(str_replace('**', '', $head[$i] ?? ''));
                    $lines[] = $lines === [] ? '*'.($n + 1).') '.($label !== '' ? $label.': ' : '').$value.'*' : ($label !== '' ? $label.': ' : '').$value;
                }
                $out[] = implode("\n", $lines);
            }
            $more = count($rows) - self::TEXT_ROWS;
            if ($more > 0) {
                $out[] = "… و{$more} كمان".($withFile ? '، كلهم في ملف الإكسيل.' : '.');
            }
            $text = str_replace($block, "\n".implode("\n\n", $out)."\n\n", $text);
        }

        return $text;
    }

    /** @param  \App\Models\AsfooraMessage|null  $message  the report itself: its tables go as an Excel file */
    public static function to(Staff $staff, string $text, ?string $key = null, ?\App\Models\AsfooraMessage $message = null): void
    {
        foreach (self::numbers($staff->notify_whatsapp) as $phone) {
            self::dispatch($staff->id, $text, $key ? $key.':'.$phone : null, $phone, $message?->id)->afterCommit();
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

        // Owner 2026-11-27: «ومعاه ملف اكسيل فيه نفس التقرير»
        if ($file = $this->excel()) {
            $delivery->deliverForConversation($conversation, ['media' => [$file]], 'system',
                requireAgentEnabled: false, dedupeKey: $this->key ? 'owner:'.$this->key.':xlsx' : null);
        }
    }

    public const REPORTS_DIR = 'owner-reports';

    /** the report's tables as a public .xlsx (a random name, removed after two days); null = no table */
    private function excel(): ?array
    {
        $message = $this->messageId ? \App\Models\AsfooraMessage::find($this->messageId) : null;
        if (! $message || (empty($message->meta['tables']) && self::tables($message->content) === [])) {
            return null;
        }
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            foreach ($disk->files(self::REPORTS_DIR) as $old) {
                if ($disk->lastModified($old) < now()->subDays(2)->timestamp) {
                    $disk->delete($old);
                }
            }
            $temp = \App\Domain\Asfoora\AsfooraExcel::write($message, '');
            $name = \App\Domain\Asfoora\AsfooraExcel::filename($message);
            $path = self::REPORTS_DIR.'/'.\Illuminate\Support\Str::random(32).'.xlsx';
            $disk->put($path, file_get_contents($temp));
            @unlink($temp);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return ['url' => asset('storage/'.$path), 'type' => 'document', 'path' => $path, 'filename' => $name,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'caption' => ''];
    }
}
