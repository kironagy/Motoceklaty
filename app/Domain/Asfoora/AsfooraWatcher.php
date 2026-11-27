<?php

namespace App\Domain\Asfoora;

use App\Filament\Pages\Asfoora;
use App\Filament\Resources\DeliveryResource;
use App\Models\AsfooraChat;
use App\Models\AsfooraMessage;
use App\Models\AsfooraWatch;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-26: the watches he started from the chat. Each due one posts its news into its
 * chat and pushes it to his phone - "مفيش جديد" too - until he stops it there.
 */
class AsfooraWatcher
{
    public function runDue(): int
    {
        $ran = 0;
        foreach (AsfooraWatch::where('is_active', true)->where('next_run_at', '<=', now())->get() as $watch) {
            if ($watch->created_at->lt(now()->subDays(AsfooraWatch::MAX_DAYS))) {
                $watch->update(['is_active' => false]);
                $this->post($watch, 'وقفت متابعة '.$watch->describe().' لوحدها بعد '.AsfooraWatch::MAX_DAYS.' أيام. قولي «تابعي تاني» لو عايزها.', []);

                continue;
            }
            // taken first, so two runs never send it twice
            if (! AsfooraWatch::whereKey($watch->id)->where('next_run_at', $watch->next_run_at)->update(['next_run_at' => now()->addMinutes($watch->every_minutes)])) {
                continue;
            }
            try {
                $this->run($watch);
                $ran++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $ran;
    }

    public function run(AsfooraWatch $watch): void
    {
        $since = $watch->last_checked_at ?? $watch->created_at;
        $now = now();

        if ($watch->kind === 'question') {
            $chat = AsfooraChat::find($watch->asfoora_chat_id);
            if ($chat) {
                $answer = app(AsfooraAgent::class)->answer($chat, '🔔 '.$watch->question.' (من '.$since->format('H:i').' لحد دلوقتي)');
                $this->notify($watch, $answer);
            }
        } else {
            [$text, $tables] = $this->newRequests($watch, $since, $now);
            $this->notify($watch, $this->post($watch, $text, $tables));
        }

        $watch->update(['last_checked_at' => $now]);
    }

    /** @return array{0: string, 1: array} */
    private function newRequests(AsfooraWatch $watch, \DateTimeInterface $since, \DateTimeInterface $now): array
    {
        $filters = (array) ($watch->filters ?? []);
        $args = ['since' => $since->format('Y-m-d H:i:s')] + $filters;
        $rows = AsfooraTools::exportList($args);
        $from = \Illuminate\Support\Carbon::parse($since)->format('h:i A');

        if ($rows === []) {
            return ["🔔 مفيش طلبات جديدة من الساعة {$from}. هبصلك تاني ".$watch->describe().'.', []];
        }

        $byStaff = collect($rows)->countBy(fn ($r) => $r['staff'] ?: 'من غير موظف')->sortDesc();
        $bot = DB::table('installment_requests')->whereIn('id', array_column($rows, 'id'))->where('request_type', 'bot')->count();
        $text = '🔔 جه **'.count($rows).' طلب جديد** من الساعة '.$from
            .($bot ? " ({$bot} من البوت)" : '').":\n\n"
            .$byStaff->map(fn ($n, $s) => "- {$s}: {$n}")->implode("\n")
            ."\n\n| رقم الطلب | العميل | الموظف | الحالة |\n|---|---|---|---|\n"
            .collect($rows)->take(15)->map(fn ($r) => "| #{$r['id']} | {$r['customer']} | ".($r['staff'] ?: '—')." | {$r['status']} |")->implode("\n")
            .(count($rows) > 15 ? "\n\nوالباقي (".(count($rows) - 15).') في ملف الإكسيل.' : '')
            ."\n\nقولي «وقفي المتابعة» لما تكتفي.";

        return [$text, [['title' => 'الطلبات الجديدة', 'rows' => $rows, 'columns' => array_keys($rows[0]), 'source' => ['tool' => 'list_requests', 'args' => $args]]]];
    }

    private function post(AsfooraWatch $watch, string $text, array $tables): AsfooraMessage
    {
        $chat = AsfooraChat::find($watch->asfoora_chat_id);
        $chat?->touch();

        return AsfooraMessage::create(['asfoora_chat_id' => $watch->asfoora_chat_id, 'role' => 'assistant', 'content' => $text,
            'meta' => ['watch_id' => $watch->id, 'tables' => $tables]]);
    }

    private function notify(AsfooraWatch $watch, AsfooraMessage $message): void
    {
        $title = 'عصفورة: '.$watch->describe();
        $body = mb_substr(trim(preg_replace('/[*#|`>]+/u', ' ', strtok($message->content, "\n") ?: '')), 0, 160);
        \App\Models\Notification::create(['user_id' => $watch->staff_id, 'title' => $title, 'message' => $body, 'is_read' => false]);
        try {
            app(\App\Services\PushNotificationService::class)->sendToUser(
                userId: $watch->staff_id, title: $title, body: $body,
                url: Asfoora::getUrl(['chat' => $watch->asfoora_chat_id], panel: 'admin'), tag: 'asfoora-watch-'.$watch->id,
            );
        } catch (\Throwable $e) {
            report($e);
        }
        // Owner 2026-11-27: and on his WhatsApp, which shows on a locked phone
        if ($staff = \App\Models\Staff::find($watch->staff_id)) {
            \App\Jobs\SendOwnerWhatsapp::to($staff, "🐦 {$title}\n\n".\App\Jobs\SendOwnerWhatsapp::plain($message->content), 'watch:'.$message->id);
        }
    }
}
