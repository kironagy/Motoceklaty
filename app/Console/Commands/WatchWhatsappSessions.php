<?php

namespace App\Console\Commands;

use App\Models\WhatsappBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Owner 2026-11-09: "the bot answers some customers and not others". Bots 96, 101 and 104 had been
 * logged out by WhatsApp for one to three days (99 twice that day) while the dashboard still said
 * "qr" / active - every customer writing to those numbers got nothing and nobody knew. The worker
 * is the truth: this copies what it reports into whatsapp_bots.session_status and tells the owner
 * on WhatsApp (through a bot that IS connected) which numbers need their QR scanned again.
 */
class WatchWhatsappSessions extends Command
{
    protected $signature = 'whatsapp:watch-sessions {--quiet-alerts : update the status only}';

    protected $description = 'Sync live WhatsApp session status from the worker and alert when an active bot is down';

    public function handle(): int
    {
        try {
            $response = Http::connectTimeout(5)->timeout(10)
                ->withHeaders(['X-BOT-TOKEN' => config('services.whatsapp.bot_token'), 'Accept' => 'application/json'])
                ->get(config('services.whatsapp.worker_url').'/status');
        } catch (\Throwable $e) {
            $this->alertOnce('worker', 'سيرفر الواتساب (whatsapp-worker) مش بيرد: '.$e->getMessage(), null);

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->alertOnce('worker', 'سيرفر الواتساب (whatsapp-worker) رجّع '.$response->status(), null);

            return self::FAILURE;
        }

        $live = collect($response->json('sessions') ?? [])->keyBy(fn ($s) => (string) ($s['bot_id'] ?? ''));
        $connectedIds = $live->filter(fn ($s) => ($s['connected'] ?? false) === true)->keys()->map(fn ($id) => (int) $id)->all();
        $down = [];

        foreach (WhatsappBot::where('is_active', true)->get() as $bot) {
            $session = $live->get((string) $bot->id);
            $status = $session['status'] ?? 'not_running';
            $connected = ($session['connected'] ?? false) === true;

            $changes = ['session_status' => $connected ? 'connected' : $status];
            if ($connected && $bot->session_status !== 'connected') {
                $changes['connected_at'] = now();
            }
            $bot->forceFill($changes)->save();

            if (! $connected) {
                $down[] = $bot;
            }
        }

        $this->line('connected: '.implode(',', $connectedIds).' | down: '.collect($down)->map(fn ($b) => "{$b->id}({$b->session_status})")->implode(','));

        if ($down !== [] && ! $this->option('quiet-alerts')) {
            $lines = collect($down)->map(fn (WhatsappBot $b) => "• {$b->name} (#{$b->id}) - ".match ($b->session_status) {
                'logged_out' => 'واتساب عمل تسجيل خروج، محتاج QR جديد',
                'qr' => 'مستني مسح QR',
                'not_running' => 'مش شغال على السيرفر، محتاج تشغيل ومسح QR',
                default => $b->session_status,
            })->implode("\n");

            $this->alertOnce('bots:'.collect($down)->pluck('id')->sort()->implode(','),
                "⚠️ أرقام واتساب واقفة والعملاء اللي بيكلموها مش بيوصلهم رد:\n{$lines}\nادخل صفحة البوتات وامسح الـ QR. بعد الربط البوت هيرد على الرسايل اللي وصلت وهو واقف.",
                $connectedIds[0] ?? null);
        }

        return self::SUCCESS;
    }

    /** Same problem = one alert per hour, never a message every 5 minutes. */
    private function alertOnce(string $key, string $message, ?int $viaBotId): void
    {
        if (! Cache::add('whatsapp-session-alert:'.md5($key), true, now()->addHour())) {
            return;
        }

        $number = config('gemini.alerts.whatsapp_number');

        if (! $number || ! $viaBotId) {
            Log::error('WhatsApp session alert not sent (no number or no connected bot)', ['message' => $message]);

            return;
        }

        try {
            Http::connectTimeout(5)->timeout(30)
                ->withHeaders(['X-BOT-TOKEN' => config('services.whatsapp.bot_token'), 'Accept' => 'application/json'])
                ->post(config('services.whatsapp.worker_url').'/send-message', [
                    'bot_id' => (string) $viaBotId,
                    'jid' => str_contains($number, '@') ? $number : $number.'@s.whatsapp.net',
                    'message' => $message,
                ]);
        } catch (\Throwable $e) {
            Log::error('WhatsApp session alert failed', ['error' => $e->getMessage()]);
        }
    }
}
