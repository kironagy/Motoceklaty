<?php

namespace App\Filament\Clusters\WebsiteSettings\Pages;

use App\Filament\Support\SiteSettingsPage;
use Filament\Forms\Components\Section;

class TrackingSettings extends SiteSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';
    protected static ?string $navigationLabel = 'تتبع الطلب';
    protected static ?string $title = 'محتوى صفحة تتبع الطلب';
    protected static ?int $navigationSort = 9;

    public static function contentGroup(): string { return 'tracking'; }
    protected function previewUrl(): ?string { return route('tracking.index'); }

    protected function formSchema(): array
    {
        return [Section::make('صفحة التتبع')->schema([
            static::text('title', 'العنوان'), static::textarea('text', 'الوصف'),
            static::text('form_title', 'عنوان النموذج'), static::text('code_label', 'اسم حقل الكود'),
            static::text('submit', 'زر التتبع'), static::text('not_found', 'رسالة الكود غير الصحيح'),
            static::text('result_title', 'عنوان النتيجة'), static::textarea('result_note', 'نص النتيجة'),
            static::text('empty', 'النص قبل البحث'), static::text('contact_label', 'زر التواصل'),
        ]), Section::make('مسميات الحالات للعميل')->schema(array_map(
            fn ($key) => static::text('statuses.' . $key, $key),
            array_keys(\App\Support\SiteDefaults::all()['tracking']['statuses']),
        ))];
    }
}
