<?php

namespace App\Console\Commands;

use App\Domain\Asfoora\AsfooraAgent;
use App\Filament\Pages\Asfoora;
use App\Models\AsfooraChat;
use App\Models\Staff;
use Illuminate\Console\Command;

/**
 * Owner 2026-11-26: every morning «عصفورة» writes yesterday's report by herself - the requests per
 * employee and from the bot, approved / rejected / stuck, requests taken from their employees and
 * anything odd - into a chat of each owner (is_hitler), with a notification that opens it.
 */
class AsfooraDailyReport extends Command
{
    protected $signature = 'asfoora:daily-report {--date= : the day to report, Y-m-d (default yesterday)}';

    protected $description = 'Yesterday\'s report by «عصفورة», sent to the owners';

    public function handle(AsfooraAgent $agent): int
    {
        // the status parking accounts («متوقف»، «مرفوض» ...) carry is_hitler too - they are not people
        $owners = Staff::where('is_hitler', true)->whereNotIn('email', array_column(\App\Models\InstallmentRequest::PARKING_ACCOUNTS, 0))
            ->orderBy('id')->get(['id', 'name']);
        if ($owners->isEmpty()) {
            return self::SUCCESS;
        }

        $day = $this->option('date') ? \Illuminate\Support\Carbon::parse($this->option('date')) : now()->subDay();
        $date = $day->toDateString();
        $question = "تقرير يوم {$date}: الطلبات اللي اترفعت لكل موظف ومن البوت، واللي اتوافق واترفض واتوقف في اليوم ده، "
            .'والطلبات اللي اتسحبت من موظفيها، ومقارنة باليوم اللي قبله. '
            .'وقولي أي حاجة تلفت النظر: موظف مارفعش ولا طلب، رقم أقل من العادي، سبب رفض أو توقف متكرر. ارسم الطلبات لكل موظف.';

        // made once, then the same report goes into every owner's chats
        $first = AsfooraChat::create(['staff_id' => $owners->first()->id, 'title' => "📅 تقرير يوم {$date}"]);
        $answer = $agent->answer($first, $question);
        $this->line(mb_substr($answer->content, 0, 200));

        foreach ($owners as $i => $owner) {
            $chat = $i === 0 ? $first : AsfooraChat::create(['staff_id' => $owner->id, 'title' => $first->title]);
            if ($i > 0) {
                foreach ($first->messages()->get() as $m) {
                    $chat->messages()->create($m->only(['role', 'content', 'meta']));
                }
            }

            $title = "عصفورة: تقرير يوم {$date} جاهز";
            $body = mb_substr(trim(preg_replace('/[*#|`>_-]+/u', ' ', strtok($answer->content, "\n") ?: '')), 0, 160);
            \App\Models\Notification::create(['user_id' => $owner->id, 'title' => $title, 'message' => $body, 'is_read' => false]);
            try {
                app(\App\Services\PushNotificationService::class)->sendToUser(
                    userId: $owner->id, title: $title, body: $body, url: Asfoora::getUrl(['chat' => $chat->id], panel: 'admin'), tag: 'asfoora-daily',
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
