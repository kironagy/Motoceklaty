<?php

namespace App\Filament\Clusters\WebsiteSettings\Pages;

use App\Filament\Support\SiteSettingsPage;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;

class InstallmentSettings extends SiteSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'الحاسبة وطلب التقسيط';

    protected static ?string $title = 'حاسبة التقسيط وصفحة الطلب';

    protected static ?int $navigationSort = 5;

    public static function contentGroup(): string
    {
        return 'installment';
    }

    protected function previewUrl(): ?string
    {
        return route('installment.index');
    }

    protected function formSchema(): array
    {
        return [
            Forms\Components\Placeholder::make('calc_hint')->hiddenLabel()
                ->content('الأنظمة والشهور والفوايد والمصاريف بتيجي من "أنظمة التقسيط" وربطها بكل مكنة - الحساب نفسه هو نفس حساب بوت الواتساب.'),

            Tabs::make('installment')->persistTabInQueryString()->tabs([
                Tab::make('نصوص الحاسبة')->icon('heroicon-o-calculator')->schema([
                    Forms\Components\Section::make('خانات الحاسبة')->columns(2)->schema([
                        static::text('calculator.labels.brand', 'الماركة'),
                        static::text('calculator.labels.brand_placeholder', 'اختيار الماركة'),
                        static::text('calculator.labels.machine', 'الموديل'),
                        static::text('calculator.labels.machine_placeholder', 'اختيار الموديل'),
                        static::text('calculator.labels.system', 'نظام التقسيط'),
                        static::text('calculator.labels.system_placeholder', 'اختيار النظام'),
                        static::text('calculator.labels.months', 'مدة التقسيط'),
                        static::text('calculator.labels.months_placeholder', 'اختيار المدة'),
                        static::text('calculator.labels.months_option', 'شكل اختيار الشهور')->helperText(':months = الشهور، :interest = نسبة الفايدة'),
                        static::text('calculator.labels.down_payment', 'المقدم'),
                        static::text('calculator.labels.down_payment_placeholder', 'مثال المقدم'),
                        static::text('calculator.labels.submit', 'زرار الحساب'),
                    ]),
                    Forms\Components\Section::make('نتيجة الحساب')->columns(2)->schema([
                        static::text('calculator.result.title', 'العنوان'),
                        static::text('calculator.result.monthly', 'القسط الشهري'),
                        static::text('calculator.result.per_month', 'كلمة "شهرياً"'),
                        static::text('calculator.result.duration', 'المدة')->helperText(':months = عدد الشهور'),
                        static::text('calculator.result.down_payment', 'المقدم'),
                        static::text('calculator.result.financed', 'المبلغ المتبقي'),
                        static::text('calculator.result.interest', 'قيمة الفايدة'),
                        static::text('calculator.result.total', 'الإجمالي'),
                        static::text('calculator.result.fees', 'المصاريف الإدارية'),
                        static::text('calculator.result.currency', 'العملة'),
                        static::text('calculator.result.apply', 'زرار التقديم'),
                        static::text('calculator.result.missing_fields', 'رسالة الحقول الناقصة'),
                        static::textarea('calculator.result.note', 'الملاحظة', 2)->columnSpanFull(),
                    ]),
                ]),

                Tab::make('صفحة "احسب قسطك"')->icon('heroicon-o-document')->schema([
                    Forms\Components\Grid::make(2)->schema([
                        static::text('page.seo_title', 'عنوان الصفحة (SEO)'),
                        static::text('page.banner_title', 'عنوان البانر'),
                        static::text('page.breadcrumb', 'اسم الصفحة في مسار التنقل'),
                        static::text('page.small_title', 'العنوان الصغير'),
                        static::text('page.title', 'العنوان الرئيسي'),
                    ]),
                    static::textarea('page.text', 'الوصف', 2),
                    static::image('page.banner_image', 'صورة البانر', 'site/banners'),
                ]),

                Tab::make('صفحة "طلب التقسيط"')->icon('heroicon-o-clipboard-document-check')->schema([
                    Forms\Components\Grid::make(2)->schema([
                        static::text('apply.seo_title', 'عنوان الصفحة (SEO)'),
                        static::text('apply.banner_title', 'عنوان البانر'),
                        static::text('apply.breadcrumb', 'اسم الصفحة في مسار التنقل'),
                        static::text('apply.small_title', 'العنوان الصغير'),
                        static::text('apply.title', 'العنوان الرئيسي'),
                    ]),
                    static::textarea('apply.text', 'الوصف', 2),
                    static::image('apply.banner_image', 'صورة البانر', 'site/banners'),
                ]),
            ]),
        ];
    }
}
