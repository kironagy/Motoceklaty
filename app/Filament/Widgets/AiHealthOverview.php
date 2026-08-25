<?php

namespace App\Filament\Widgets;

use App\Models\AiMemory;
use App\Models\AiMemoryRetrievalLog;
use App\Models\CustomerProfile;
use App\Models\GeminiApiKey;
use App\Models\GeminiApiKeyModel;
use App\Models\InstallmentRequest;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Plan task 4.2: the numbers that tell you the bot is degrading before a
 * customer does.
 *
 * Deliberately built only on things stored in the database - conversations,
 * retrieval logs, key quotas, memories - rather than by parsing
 * storage/logs. The single most useful number here is the pooled daily
 * quota: every reply spends from it, it is capped per key, and when it runs
 * out the bot simply stops answering - there is no quieter fallback left.
 */
class AiHealthOverview extends BaseWidget
{
    protected ?string $heading = 'صحة الـ AI';

    protected static ?int $sort = -1;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 3;
    }

    public static function canView(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->is_super_admin || $user?->role === 'super_admin');
    }

    protected function getStats(): array
    {
        return [
            $this->pooledQuotaStat(),
            $this->keysStat(),
            $this->escalationsStat(),
            $this->trafficStat(),
            $this->memoryRetrievalStat(),
            $this->memoryCoverageStat(),
        ];
    }

    /**
     * The pooled daily allowance of the model the bot actually replies on.
     *
     * Counting every provisioned model here was misleading: the gemma and
     * 2.5/3.5 rows carry thousands of requests nobody spends, because no
     * code path ever asks for them. That inflated total hid the only number
     * that can actually run out. What is left is the real answer to "how
     * many more customers can we serve today" - one key's 500-a-day cap
     * summed across every working key, since GeminiKeyManager reserves from
     * whichever key still has room and GeminiClient moves to the next one
     * mid-request when a key hits 429.
     */
    private function pooledQuotaStat(): Stat
    {
        $rows = self::spendableModels()->get();

        $used = (int) $rows->sum('requests_today');
        $limit = (int) $rows->sum('rpd_limit');
        $remaining = max(0, $limit - $used);
        $keys = $rows->pluck('gemini_api_key_id')->unique()->count();
        $cooling = $rows->filter(fn ($row) => $row->cooldown_until && $row->cooldown_until->isFuture())->count();

        // Quota provisioned for the same model on a key or row that is
        // switched off: real allowance, just not reachable until someone
        // turns it back on.
        $blockedLimit = (int) GeminiApiKeyModel::query()
            ->where('provider', 'gemini')
            ->whereIn('model_code', self::modelCodesInUse())
            ->where(function ($query) {
                $query->where('is_active', false)
                    ->orWhereHas('apiKey', fn ($q) => $q->where('is_active', false));
            })
            ->sum('rpd_limit');

        $percent = $limit > 0 ? (int) round($used / $limit * 100) : 0;

        return Stat::make(
            'الرصيد المتاح النهاردة (' . implode(' + ', self::modelCodesInUse()) . ')',
            $limit > 0 ? number_format($used) . ' / ' . number_format($limit) . ' نداء' : 'مفيش مفاتيح شغالة'
        )
            ->description(
                $limit > 0
                    ? 'فاضل ' . number_format($remaining) . " نداء على {$keys} مفتاح شغال"
                        . ($cooling > 0 ? " · {$cooling} مستني cooldown" : '')
                        . ($blockedLimit > 0 ? ' · ' . number_format($blockedLimit) . ' نداء متقفلة في مفاتيح موقوفة' : '')
                    : 'ضيف مفتاح Gemini شغال عشان البوت يرد'
            )
            ->descriptionIcon($limit > 0 && $percent < 80 ? 'heroicon-m-battery-100' : 'heroicon-m-exclamation-triangle')
            ->color($limit === 0 || $percent >= 90 ? 'danger' : ($percent >= 80 ? 'warning' : 'success'));
    }

    /**
     * The model codes the bot can actually call, straight from config rather
     * than from whatever happens to be provisioned in the database.
     *
     * Both settings point at gemini-3.1-flash-lite today, so this is one
     * code; it stays an array so that reintroducing a separate reasoning
     * model via .env shows up here without touching this widget.
     */
    public static function modelCodesInUse(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('gemini.models.reasoning'),
            (string) config('gemini.models.fast'),
        ])));
    }

    /**
     * Rows that can take a customer request right now: a model the bot
     * actually calls, active, on an active key.
     */
    public static function spendableModels(): \Illuminate\Database\Eloquent\Builder
    {
        return GeminiApiKeyModel::query()
            ->where('provider', 'gemini')
            ->whereIn('model_code', self::modelCodesInUse())
            ->where('is_active', true)
            ->whereHas('apiKey', fn ($query) => $query->where('is_active', true));
    }

    private function keysStat(): Stat
    {
        $total = GeminiApiKey::query()->count();
        $active = GeminiApiKey::query()->where('is_active', true)->count();

        return Stat::make('مفاتيح Gemini', "{$active} / {$total} شغال")
            ->description($active === $total ? 'كل المفاتيح سليمة' : ($total - $active) . ' مفتاح متوقف - محتاج تجديد')
            ->descriptionIcon($active === $total ? 'heroicon-m-key' : 'heroicon-m-exclamation-triangle')
            ->color($active === $total ? 'success' : 'danger');
    }

    private function escalationsStat(): Stat
    {
        $waiting = WhatsappConversation::query()->where('status', 'awaiting_agent')->count();
        $openToday = WhatsappConversation::query()->whereDate('updated_at', today())->count();

        return Stat::make('محوّل لموظف', (string) $waiting)
            ->description("من {$openToday} محادثة اتحركت النهاردة")
            ->descriptionIcon('heroicon-m-user-group')
            ->color($waiting > 5 ? 'warning' : 'success');
    }

    private function trafficStat(): Stat
    {
        $incoming = WhatsappMessage::query()->where('direction', 'incoming')->whereDate('created_at', today())->count();
        $outgoing = WhatsappMessage::query()->where('direction', 'outgoing')->whereDate('created_at', today())->count();
        $requests = InstallmentRequest::query()->whereDate('created_at', today())->count();

        return Stat::make('رسايل النهاردة', "{$incoming} داخلة / {$outgoing} خارجة")
            ->description("{$requests} طلب تقسيط اتسجل النهاردة")
            ->descriptionIcon('heroicon-m-chat-bubble-left-right');
    }

    private function memoryRetrievalStat(): Stat
    {
        $today = AiMemoryRetrievalLog::query()->whereDate('created_at', today());
        $total = (clone $today)->count();
        $fullSet = (clone $today)->where('retrieval_method', 'full_set')->count();

        return Stat::make('استرجاع الميموري', $total > 0 ? "{$fullSet} / {$total} كامل" : 'مفيش استرجاع النهاردة')
            ->description(
                $total > 0 && $fullSet < $total
                    ? ($total - $fullSet) . ' مرة اترشّحت بالتقييم (الميموري كبرت عن البرومبت)'
                    : 'كل الميموري بتوصل للموديل'
            )
            ->descriptionIcon('heroicon-m-circle-stack')
            ->color($total === 0 || $fullSet === $total ? 'success' : 'warning');
    }

    private function memoryCoverageStat(): Stat
    {
        $active = AiMemory::query()->where('is_active', true)->count();
        $tagged = AiMemory::query()->where('is_active', true)
            ->whereNotNull('keywords')->where('keywords', '!=', '[]')->count();
        $profiles = CustomerProfile::query()->count();

        return Stat::make('الميموري', "{$active} نشطة")
            ->description("{$tagged} منهم بكلمات مفتاحية · {$profiles} ملف عميل محفوظ")
            ->descriptionIcon('heroicon-m-book-open')
            ->color($active > 0 && $tagged >= $active * 0.8 ? 'success' : 'warning');
    }
}
