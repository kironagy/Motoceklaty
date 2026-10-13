<?php

namespace App\Filament\Clusters\WebsiteSettings\Pages;

use App\Filament\Support\SiteSettingsPage;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;

class ShowroomSettings extends SiteSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'المعرض والموديلات';

    protected static ?string $title = 'صفحات المعرض وتفاصيل الموديل';

    protected static ?int $navigationSort = 4;

    public static function contentGroup(): string
    {
        return 'showroom';
    }

    protected function previewUrl(): ?string
    {
        return route('machines.index');
    }

    protected function formSchema(): array
    {
        return [
            Forms\Components\Placeholder::make('catalog_hint')->hiddenLabel()
                ->content('الموديلات نفسها (الصور، الأسعار، المواصفات، الألوان، فيديو المراجعة) بتتعدل من "المعرض > المكن"، وأيقونات الماركات من "الشركات".'),

            Tabs::make('showroom')->persistTabInQueryString()->tabs([
                Tab::make('صفحة المعرض')->icon('heroicon-o-squares-2x2')->schema([
                    Forms\Components\Section::make('الهيرو')->columns(2)->schema([
                        static::text('hero.badge', 'الشارة أعلى العنوان')->columnSpanFull(),
                        static::text('hero.title_before', 'العنوان'),
                        static::text('hero.title_highlight', 'الجزء الملوّن من العنوان'),
                        static::textarea('hero.subtitle', 'الوصف', 2)->columnSpanFull(),
                        static::text('hero.breadcrumb', 'اسم الصفحة في مسار التنقل'),
                    ]),
                    Forms\Components\Section::make('فلتر الماركات')->columns(2)->schema([
                        static::text('filter.title', 'عنوان الفلتر'),
                        static::text('filter.all_label', 'اسم زرار "الكل"'),
                        static::text('filter.search_placeholder', 'النص داخل خانة البحث'),
                        static::text('filter.search_button', 'زرار البحث'),
                        static::text('filter.search_label', 'نص "نتائج البحث عن"'),
                        static::text('filter.clear_search', 'نص مسح البحث'),
                        static::text('filter.results', 'عداد النتائج')->helperText(':from و :to = أرقام الصفحة، :total = الإجمالي'),
                        Forms\Components\TextInput::make('filter.per_page')->label('عدد الموديلات في الصفحة')->numeric()->minValue(4)->maxValue(48),
                        Forms\Components\TextInput::make('filter.visible_chips')->label('عدد الماركات الظاهرة قبل "عرض المزيد"')->numeric()->minValue(1),
                        static::text('filter.more_label', 'نص "عرض باقي الماركات"'),
                        static::text('filter.less_label', 'نص "عرض أقل"'),
                        static::text('filter.count_all', 'عداد الكل')->helperText(':count = عدد الموديلات'),
                        static::text('filter.count_brand', 'عداد الماركة')->helperText(':count = العدد، :brand = اسم الماركة'),
                        static::text('filter.category_label', 'نص "الفئة:"'),
                        static::text('filter.clear_category', 'نص إلغاء فلتر الفئة'),
                    ]),
                    Forms\Components\Section::make('لو مفيش موديلات')->columns(2)->schema([
                        static::text('empty.title', 'العنوان'),
                        static::text('empty.button', 'نص الزرار'),
                        static::textarea('empty.text', 'النص', 2)->columnSpanFull(),
                    ]),
                ]),

                Tab::make('كارت الموديل')->icon('heroicon-o-rectangle-stack')->schema([
                    static::text('card.details_label', 'نص زرار التفاصيل'),
                    static::toggle('card.show_price', 'إظهار سعر الكاش على الكارت'),
                    Forms\Components\Grid::make(2)->schema([
                        static::text('card.price_prefix', 'كلمة قبل السعر'),
                        static::text('card.currency', 'العملة'),
                    ]),
                ]),

                Tab::make('صفحة تفاصيل الموديل')->icon('heroicon-o-document-magnifying-glass')->schema([
                    Forms\Components\Grid::make(2)->schema([
                        static::text('detail.color_label', 'نص اختيار اللون'),
                        static::text('detail.offer_label', 'شارة العرض الخاص'),
                        static::text('detail.cash_label', 'عنوان سعر الكاش'),
                        static::text('detail.installment_label', 'عنوان سعر التقسيط'),
                        static::toggle('detail.show_installment_price', 'إظهار سعر التقسيط في صفحة المكنة'),
                        static::text('detail.calc_button', 'زرار "احسب قسطك"'),
                        static::text('detail.apply_button', 'زرار "قدّم على التقسيط"'),
                        static::text('detail.whatsapp_button', 'زرار الواتساب'),
                        static::text('detail.whatsapp_message', 'رسالة الواتساب الجاهزة')->helperText(':name = اسم الموديل'),
                        static::text('detail.gallery_zoom', 'تلميح تكبير الصورة'),
                        static::text('detail.specs_title', 'عنوان المواصفات'),
                        static::text('detail.features_title', 'عنوان المميزات'),
                        static::text('detail.calc_title', 'عنوان الحاسبة')->helperText(':name = اسم الموديل'),
                        static::text('detail.related_title', 'عنوان الموديلات المشابهة'),
                    ]),
                    Forms\Components\Fieldset::make('قسم الفيديو (بيظهر لو الموديل له رابط فيديو)')->schema([
                        static::text('detail.video_small_title', 'العنوان الصغير'),
                        static::text('detail.video_title', 'العنوان')->helperText(':name = اسم الموديل'),
                        static::textarea('detail.video_text', 'الوصف', 2)->columnSpanFull(),
                    ])->columns(2),
                ]),

                Tab::make('الأسئلة الشائعة')->icon('heroicon-o-question-mark-circle')->schema([
                    static::text('detail.faq_title', 'عنوان القسم'),
                    Forms\Components\Repeater::make('detail.faqs')
                        ->label('الأسئلة (بتظهر في صفحة كل موديل)')
                        ->schema([
                            static::text('question', 'السؤال')->required(),
                            static::textarea('answer', 'الإجابة', 3)->required(),
                        ])
                        ->reorderable()->collapsible()->cloneable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['question'] ?? null),
                ]),

                Tab::make('SEO')->icon('heroicon-o-magnifying-glass')->schema([
                    static::seoSection()->collapsed(false),
                ]),
            ]),
        ];
    }
}
