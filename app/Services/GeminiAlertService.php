<?php

namespace App\Services;

use App\Models\GeminiApiKeyModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiAlertService
{
    /**
     * بيبعت تنبيه فوري وواتساب لما موديل واحد بس يخلص الحد اليومي بتاعه -
     * مش لازم ننتظر كل الموديلات تخلص عشان الرسالة توصل. الرسالة نفسها
     * نص عادي مبني من بيانات الموديل في الداتابيز، مفيش AI بيولّده.
     */
    public function modelExhaustedAlert(GeminiApiKeyModel $model): void
    {
        if (! config('gemini.alerts.enabled', true)) {
            return;
        }

        $dedupeKey = 'gemini_model_exhausted_alert_' . $model->id . '_' . now()->toDateString();

        if (Cache::has($dedupeKey)) {
            return;
        }

        Cache::put($dedupeKey, true, now()->endOfDay());

        $name = $model->display_name ?: $model->model_code;

        $message = "⚠️ تنبيه Gemini\n\n"
            . "الموديل \"{$name}\" ({$model->model_code}) خلص الحد اليومي بتاعه.\n"
            . 'الوقت: ' . now()->format('Y-m-d H:i:s');

        $this->sendWhatsappMessage($message);

        Log::warning('Gemini single model exhausted alert sent', ['model_id' => $model->id]);
    }

    public function startAllKeysExhaustedAlert(array $failedModels = []): void
    {
        if (! config('gemini.alerts.enabled', true)) {
            return;
        }

        $openAlert = DB::table('gemini_alerts')
            ->where('type', 'all_keys_exhausted')
            ->whereNull('acknowledged_at')
            ->latest('id')
            ->first();

        if ($openAlert) {
            return;
        }

        $message = $this->buildAllKeysExhaustedMessage($failedModels);

        DB::table('gemini_alerts')->insert([
            'type' => 'all_keys_exhausted',
            'message' => $message,
            'sent_at' => null,
            'acknowledged_at' => null,
            'acknowledged_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::warning('Gemini all keys exhausted alert started');
    }

    public function repeatOpenAlerts(): void
    {
        if (! config('gemini.alerts.enabled', true)) {
            return;
        }

        $repeatEveryMinutes = (int) config('gemini.alerts.repeat_every_minutes', 5);

        $alerts = DB::table('gemini_alerts')
            ->whereNull('acknowledged_at')
            ->where(function ($q) use ($repeatEveryMinutes) {
                $q->whereNull('sent_at')
                    ->orWhere('sent_at', '<=', now()->subMinutes($repeatEveryMinutes));
            })
            ->get();

        foreach ($alerts as $alert) {
            $this->sendWhatsappMessage($alert->message);

            DB::table('gemini_alerts')
                ->where('id', $alert->id)
                ->update([
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    public function acknowledgeOpenAlerts(string $by = 'admin'): void
    {
        DB::table('gemini_alerts')
            ->whereNull('acknowledged_at')
            ->update([
                'acknowledged_at' => now(),
                'acknowledged_by' => $by,
                'updated_at' => now(),
            ]);

        Log::info('Gemini alerts acknowledged', [
            'by' => $by,
        ]);
    }

    public function shouldStopFromMessage(string $message): bool
    {
        $message = trim(mb_strtolower($message));

        foreach (config('gemini.alerts.stop_words', []) as $word) {
            if ($message === mb_strtolower($word)) {
                return true;
            }
        }

        return false;
    }

    private function buildAllKeysExhaustedMessage(array $failedModels = []): string
    {
        $message = "⚠️ تنبيه Gemini\n\n";
        $message .= "كل Gemini API Keys المتاحة خلصت أو بترجع 429.\n";
        $message .= "السيستم جرّب كل المفاتيح المتاحة ومفيش ولا مفتاح اشتغل.\n\n";
        $message .= "هفضل أبعتلك التنبيه ده كل شوية لحد ما تبعت: وقف أو تمام.\n\n";
        $message .= "الوقت: " . now()->format('Y-m-d H:i:s') . "\n";

        if (! empty($failedModels)) {
            $message .= "\nآخر المفاتيح/المودلز اللي فشلت:\n";

            foreach (array_slice($failedModels, 0, 10) as $item) {
                $message .= "- Key ID: " . ($item['key_id'] ?? 'unknown')
                    . " | Model: " . ($item['model'] ?? 'unknown')
                    . "\n";
            }
        }

        return $message;
    }

    private function sendWhatsappMessage(string $message): void
    {
        $number = config('gemini.alerts.whatsapp_number');
        $botId = config('gemini.alerts.whatsapp_bot_id');
        $url = config('gemini.alerts.whatsapp_url');

        if (! $number || ! $botId || ! $url) {
            Log::warning('Gemini alert skipped: missing whatsapp_number, whatsapp_bot_id, or whatsapp_url', [
                'has_number' => (bool) $number,
                'has_bot_id' => (bool) $botId,
                'has_url' => (bool) $url,
            ]);
            return;
        }

        $jid = str_contains($number, '@') ? $number : $number . '@s.whatsapp.net';

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                    'Accept' => 'application/json',
                ])
                ->post($url, [
                    'bot_id' => (string) $botId,
                    'jid' => $jid,
                    'message' => $message,
                ]);

            if ($response->successful() && ($response->json('ok') ?? false)) {
                Log::warning('Gemini alert whatsapp message sent');
            } else {
                Log::error('Gemini alert whatsapp send failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send Gemini alert whatsapp message', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}