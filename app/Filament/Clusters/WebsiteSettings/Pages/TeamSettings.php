<?php

namespace App\Filament\Clusters\WebsiteSettings\Pages;

use App\Filament\Support\SiteSettingsPage;
use Filament\Forms;

class TeamSettings extends SiteSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'صفحة فريق العمل';

    protected static ?string $title = 'صفحة فريق العمل';

    protected static ?int $navigationSort = 7;

    public static function contentGroup(): string
    {
        return 'team';
    }

    protected function previewUrl(): ?string
    {
        return route('team.index');
    }

    protected function formSchema(): array
    {
        return [
            static::bannerSection(),
            Forms\Components\Section::make('عناوين القسم')->columns(2)->schema(static::headings()),
            Forms\Components\Placeholder::make('team_hint')->hiddenLabel()
                ->content('الأعضاء (الاسم، الوظيفة، الصورة، الترتيب) بيتعدلوا من "فريق العمل" في قسم محتوى الموقع.'),
            static::seoSection(),
        ];
    }
}
