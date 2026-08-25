<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AiHealthOverview;
use App\Models\GeminiApiKey;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Plan task 4.2. The panel's own Dashboard page renders an empty view, so
 * the AI stats get their own page rather than being registered into a
 * dashboard that would never show them.
 */
class AiHealth extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'صحة الـ AI';

    protected static ?string $title = 'صحة الـ AI';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.ai-health';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->is_super_admin || $user?->role === 'super_admin');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AiHealthOverview::class,
        ];
    }

    /**
     * Per-key quota breakdown behind the pooled total in the widget above.
     *
     * Restricted to the model codes the bot actually calls
     * (AiHealthOverview::modelCodesInUse()). The table used to list every
     * provisioned model, which buried the one row that matters under gemma
     * and 2.5/3.5 rows carrying thousands of requests no code path ever
     * spends.
     *
     * What the pooled number hides and this table shows: which single key is
     * carrying the load (GeminiKeyManager orders by requests_today, so usage
     * should look even), and which keys are contributing nothing because
     * they are switched off or sitting in a cooldown.
     */
    public function getKeyQuotaRows(): array
    {
        return GeminiApiKey::query()
            ->where('provider', 'gemini')
            ->with(['models' => fn ($query) => $query
                ->whereIn('model_code', AiHealthOverview::modelCodesInUse())
                ->orderBy('priority')
                ->orderBy('model_code')])
            ->whereHas('models', fn ($query) => $query
                ->whereIn('model_code', AiHealthOverview::modelCodesInUse()))
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get()
            ->map(fn (GeminiApiKey $key) => [
                'name' => $key->name ?: ('مفتاح #' . $key->id),
                'is_active' => (bool) $key->is_active,
                'last_error' => $key->last_error,
                'models' => $key->models->map(fn ($model) => [
                    'model_code' => $model->model_code,
                    'used' => (int) $model->requests_today,
                    'limit' => (int) $model->rpd_limit,
                    'remaining' => $model->remaining_today,
                    'percent' => $model->rpd_limit > 0
                        ? (int) round($model->requests_today / $model->rpd_limit * 100)
                        : 0,
                    'is_active' => (bool) $model->is_active,
                    'is_available' => $model->is_available,
                    'cooldown_until' => $model->cooldown_until && $model->cooldown_until->isFuture()
                        ? $model->cooldown_until->diffForHumans()
                        : null,
                    'last_error' => $model->last_error,
                ])->all(),
            ])
            ->all();
    }
}
