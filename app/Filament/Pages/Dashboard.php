<?php

namespace App\Filament\Pages;

use App\Support\Access;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Filament's dashboard with an Arabic name in the sidebar (the panel locale stays en).
 * It is the panel's home: a user whose role doesn't include it is sent to the first tab he may open.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'الرئيسية';

    protected static ?string $title = 'الرئيسية';

    protected static ?int $navigationSort = -2;

    public static function shouldRegisterNavigation(): bool
    {
        return Access::check(static::class, 'view') ?? true;
    }

    public function mount(): void
    {
        if (Access::check(static::class, 'view') !== false) {
            return;
        }

        $home = Access::homeUrl();

        abort_unless($home, 403, 'مفيش صفحات مفتوحة لحسابك، كلّم المدير.');

        $this->redirect($home);
    }
}
