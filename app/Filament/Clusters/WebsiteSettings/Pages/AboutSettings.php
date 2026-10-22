<?php

namespace App\Filament\Clusters\WebsiteSettings\Pages;

use App\Filament\Support\SiteSettingsPage;
use App\Models\ClientReview;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;

class AboutSettings extends SiteSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'من نحن';

    protected static ?string $title = 'محتوى صفحة من نحن';

    protected static ?int $navigationSort = 3;

    public static function contentGroup(): string
    {
        return 'about';
    }

    protected function previewUrl(): ?string
    {
        return route('about.index');
    }

    protected function formSchema(): array
    {
        return [
            Tabs::make('about')->persistTabInQueryString()->tabs([
                Tab::make('الأسئلة الشائعة')->schema([
                    static::toggle('faq.enabled'),
                    static::text('faq.title', 'العنوان'),
                    static::text('faq.title_highlight', 'العنوان — الجزء الملون'),
                    Forms\Components\Repeater::make('faq.items')->label('الأسئلة')->schema([
                        static::text('question', 'السؤال')->required(),
                        static::textarea('answer', 'الإجابة')->required(),
                    ])->reorderable()->collapsible()->defaultItems(0),
                ]),
                Tab::make('البانر و SEO')->icon('heroicon-o-photo')->schema([
                    static::bannerSection(),
                    static::seoSection(),
                ]),

                Tab::make('قصتنا')->icon('heroicon-o-book-open')->schema([
                    Forms\Components\Fieldset::make('الكارت الداكن')->schema([
                        static::text('story.card_icon', 'الأيقونة')->helperText(static::featherIconHelp()),
                        static::text('story.card_title', 'العنوان'),
                        static::text('story.card_subtitle', 'العنوان الفرعي'),
                        static::text('story.card_note', 'الملاحظة السفلية'),
                    ])->columns(2),
                    Forms\Components\Grid::make(2)->schema(static::headings('story.')),
                    Forms\Components\RichEditor::make('story.body')->label('نص القصة')
                        ->toolbarButtons(['bold', 'italic', 'underline', 'link', 'bulletList', 'orderedList', 'h3', 'undo', 'redo']),
                ]),

                Tab::make('المميزات')->icon('heroicon-o-star')->schema([
                    static::toggle('features.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('features.')),
                    Forms\Components\Repeater::make('features.items')
                        ->label('المميزات')
                        ->schema([
                            static::text('title', 'العنوان')->required(),
                            static::text('icon', 'الأيقونة')->helperText(static::featherIconHelp()),
                            Forms\Components\ColorPicker::make('color')->label('لون الأيقونة'),
                            static::textarea('text', 'الوصف', 2)->columnSpanFull(),
                        ])
                        ->columns(3)->reorderable()->collapsible()->cloneable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['title'] ?? null),
                ]),

                Tab::make('الأرقام')->icon('heroicon-o-chart-bar')->schema([
                    static::toggle('stats.enabled'),
                    Forms\Components\Repeater::make('stats.items')
                        ->label('الأرقام')
                        ->schema([
                            static::text('number', 'الرقم')->numeric()->required(),
                            static::text('suffix', 'بعد الرقم (مثلاً +)'),
                            static::text('label', 'الوصف')->required(),
                        ])
                        ->columns(3)->reorderable()->defaultItems(0)->maxItems(6)
                        ->itemLabel(fn (array $state) => trim(($state['number'] ?? '') . ($state['suffix'] ?? '') . ' ' . ($state['label'] ?? ''))),
                ]),

                Tab::make('ليه تختارنا')->icon('heroicon-o-hand-thumb-up')->schema([
                    static::toggle('why.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('why.')),
                    Forms\Components\Repeater::make('why.items')
                        ->label('الأسباب')
                        ->schema([
                            static::text('title', 'العنوان')->required(),
                            static::text('icon', 'الأيقونة')->helperText(static::featherIconHelp()),
                            static::textarea('text', 'الوصف', 2)->columnSpanFull(),
                        ])
                        ->columns(2)->reorderable()->collapsible()->cloneable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['title'] ?? null),
                ]),

                Tab::make('البيانات الرسمية')->icon('heroicon-o-document-text')->schema([
                    static::toggle('official.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('official.')),
                    Forms\Components\Repeater::make('official.items')
                        ->label('البيانات')
                        ->schema([
                            static::text('label', 'البند')->required(),
                            static::text('value', 'القيمة')->required(),
                        ])
                        ->columns(2)->reorderable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['label'] ?? null),
                ]),

                Tab::make('فريق العمل')->icon('heroicon-o-user-group')->schema([
                    static::toggle('team.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('team.')),
                    Forms\Components\TextInput::make('team.limit')->label('عدد الأعضاء المعروضين')->numeric()->minValue(1)->maxValue(12),
                    Forms\Components\Placeholder::make('team_hint')->hiddenLabel()
                        ->content('الأعضاء بيتعدلوا من "فريق العمل" في القائمة الجانبية، واختار مين يظهر هنا من خانة "يظهر في صفحة من نحن".'),
                ]),

                Tab::make('رأي عميل ودعوة للتواصل')->icon('heroicon-o-chat-bubble-bottom-center-text')->schema([
                    static::toggle('testimonial.enabled', 'إظهار رأي العميل'),
                    Forms\Components\Grid::make(2)->schema(static::headings('testimonial.')),
                    Forms\Components\Select::make('testimonial.review_id')
                        ->label('الرأي المعروض')
                        ->options(fn () => ClientReview::query()->where('is_active', true)->pluck('name', 'id'))
                        ->placeholder('أول رأي في الترتيب'),
                    Forms\Components\Fieldset::make('بوكس الدعوة للتواصل')->schema([
                        static::toggle('cta.enabled'),
                        static::text('cta.title', 'العنوان'),
                        static::textarea('cta.text', 'النص', 2)->columnSpanFull(),
                        static::text('cta.button_text', 'نص الزرار'),
                        static::link('cta.button_url'),
                    ])->columns(2),
                ]),
            ]),
        ];
    }
}
