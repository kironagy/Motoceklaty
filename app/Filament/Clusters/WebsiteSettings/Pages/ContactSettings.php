<?php

namespace App\Filament\Clusters\WebsiteSettings\Pages;

use App\Filament\Support\SiteSettingsPage;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;

class ContactSettings extends SiteSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'تواصل معنا';

    protected static ?string $title = 'صفحة تواصل معنا';

    protected static ?int $navigationSort = 8;

    public static function contentGroup(): string
    {
        return 'contact';
    }

    protected function previewUrl(): ?string
    {
        return route('contact');
    }

    protected function formSchema(): array
    {
        return [
            Forms\Components\Placeholder::make('contact_hint')->hiddenLabel()
                ->content('الهاتف والإيميل والعنوان ومواعيد العمل والسوشيال بيتعدلوا من "الإعدادات العامة > بيانات التواصل"، والفروع من "المعرض > الفروع".'),

            Tabs::make('contact')->persistTabInQueryString()->tabs([
                Tab::make('بيانات الصفحة')->icon('heroicon-o-information-circle')->schema([
                    static::bannerSection(),
                    Forms\Components\Grid::make(2)->schema(static::headings()),
                    static::textarea('text', 'الوصف', 2),
                    Forms\Components\Grid::make(2)->schema([
                        static::text('phone_label', 'عنوان الهاتف'),
                        static::text('email_label', 'عنوان الإيميل'),
                        static::text('address_label', 'عنوان العنوان'),
                        static::text('hours_label', 'عنوان مواعيد العمل'),
                        static::text('social_title', 'عنوان السوشيال ميديا'),
                    ]),
                    Forms\Components\Fieldset::make('الفروع')->schema([
                        static::toggle('show_branches', 'عرض الفروع تحت الفورم'),
                        static::text('branches_title', 'عنوان قسم الفروع'),
                        static::text('branch_map_label', 'نص رابط الخريطة'),
                    ])->columns(3),
                    static::seoSection(),
                ]),

                Tab::make('فورم التواصل')->icon('heroicon-o-pencil-square')->schema([
                    Forms\Components\Grid::make(2)->schema([
                        static::text('form.title', 'عنوان الفورم'),
                        static::text('form.text', 'وصف الفورم'),
                        static::text('form.name_label', 'خانة الاسم'),
                        static::text('form.name_placeholder', 'مثال الاسم'),
                        static::text('form.phone_label', 'خانة الهاتف'),
                        static::text('form.phone_placeholder', 'مثال الهاتف'),
                        static::text('form.email_label', 'خانة الإيميل'),
                        static::text('form.email_placeholder', 'مثال الإيميل'),
                        static::text('form.subject_label', 'خانة نوع الاستفسار'),
                        static::text('form.message_label', 'خانة الرسالة'),
                        static::text('form.message_placeholder', 'مثال الرسالة'),
                        static::text('form.submit', 'زرار الإرسال'),
                    ]),
                    Forms\Components\Repeater::make('form.subjects')
                        ->label('أنواع الاستفسار')
                        ->schema([static::text('label', 'النوع')->required()])
                        ->reorderable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['label'] ?? null),
                    static::textarea('form.success', 'رسالة بعد الإرسال', 2),
                ]),
            ]),
        ];
    }
}
