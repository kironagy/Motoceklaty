<?php

namespace App\Filament\Clusters\WebsiteSettings\Pages;

use App\Filament\Support\SiteSettingsPage;
use App\Models\Brand;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;

class HomeSettings extends SiteSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'الصفحة الرئيسية';

    protected static ?string $title = 'محتوى الصفحة الرئيسية';

    protected static ?int $navigationSort = 2;

    public static function contentGroup(): string
    {
        return 'home';
    }

    protected function previewUrl(): ?string
    {
        return route('home');
    }

    /** Font Awesome icons a "خطوات الامتلاك" step can show. */
    public static function stepIcons(): array
    {
        return [
            'fa-solid fa-motorcycle' => 'موتوسيكل',
            'fa-solid fa-magnifying-glass' => 'بحث / تصفح',
            'fa-solid fa-route' => 'طريق / تجربة قيادة',
            'fa-solid fa-calendar-check' => 'حجز موعد',
            'fa-solid fa-store' => 'المعرض',
            'fa-solid fa-wallet' => 'محفظة / دفع',
            'fa-solid fa-calculator' => 'حاسبة القسط',
            'fa-solid fa-money-bill-wave' => 'فلوس / كاش',
            'fa-solid fa-credit-card' => 'كارت',
            'fa-solid fa-file-signature' => 'عقد / توقيع',
            'fa-solid fa-id-card' => 'بطاقة / أوراق',
            'fa-solid fa-file-lines' => 'مستندات',
            'fa-solid fa-circle-check' => 'موافقة',
            'fa-solid fa-handshake' => 'اتفاق',
            'fa-solid fa-key' => 'مفتاح / استلام',
            'fa-solid fa-gift' => 'هدية',
            'fa-solid fa-shield-halved' => 'ضمان',
            'fa-solid fa-screwdriver-wrench' => 'صيانة',
            'fa-solid fa-truck-fast' => 'توصيل',
            'fa-solid fa-headset' => 'خدمة العملاء',
            'fa-brands fa-whatsapp' => 'واتساب',
            'fa-solid fa-phone' => 'تليفون',
        ];
    }

    protected function formSchema(): array
    {
        return [
            Tabs::make('home')->persistTabInQueryString()->tabs([
                Tab::make('الهيرو والحاسبة')->icon('heroicon-o-sparkles')->schema([
                    Forms\Components\Grid::make(3)->schema([
                        static::text('hero.small_title', 'العنوان الصغير'),
                        static::text('hero.brand_first', 'اسم البراند - الجزء الأول'),
                        static::text('hero.brand_second', 'اسم البراند - الجزء الملوّن'),
                    ]),
                    Forms\Components\Repeater::make('hero.typed_words')
                        ->label('الكلمات المتحركة قبل اسم البراند')
                        ->simple(static::text('word', 'كلمة')->required())
                        ->reorderable()->defaultItems(0),
                    static::image('hero.bg_image', 'صورة خلفية الهيرو', 'site/home'),
                    static::text('hero.calc_title', 'عنوان حاسبة التقسيط'),
                    Forms\Components\Repeater::make('hero.tabs')
                        ->label('تابات الحاسبة (موتوسيكل / اسكوتر / كهربائي ...)')
                        ->helperText('كل تاب بيفلتر الماركات والموديلات اللي بتظهر في الحاسبة. لو سيبت الفئات والماركات فاضيين التاب هيعرض كل الموديلات.')
                        ->schema([
                            static::text('label', 'اسم التاب')->required(),
                            static::text('browse_text', 'نص رابط "استعرض"'),
                            static::image('image', 'أيقونة التاب', 'site/home')->imageEditor(false),
                            Forms\Components\Select::make('categories')->label('الفئات')->multiple()->options(static::categoryOptions()),
                            Forms\Components\Select::make('brands')->label('الماركات')->multiple()
                                ->options(fn () => Brand::orderBy('name')->pluck('name', 'id')->map(fn ($name) => trim($name))),
                        ])
                        ->columns(2)->reorderable()->collapsible()->defaultItems(0)->maxItems(5)
                        ->itemLabel(fn (array $state) => $state['label'] ?? null),
                    Forms\Components\Repeater::make('hero.slides')
                        ->label('الموتوسيكلات اللي بتتقلب في يمين الهيرو')
                        ->helperText('يفضل صور PNG بخلفية شفافة.')
                        ->schema([
                            static::text('title', 'الاسم اللي يظهر فوق الصورة'),
                            static::image('image', 'الصورة', 'site/home')->required(),
                        ])
                        ->columns(2)->reorderable()->collapsible()->cloneable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['title'] ?? null),
                ]),

                Tab::make('اكتشف المعرض')->icon('heroicon-o-megaphone')->schema([
                    static::toggle('explore.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('explore.')),
                    static::image('explore.image', 'الصورة في النص', 'site/home'),
                    Forms\Components\Grid::make(2)->schema([
                        static::text('explore.button_text', 'نص الزرار'),
                        static::link('explore.button_url'),
                    ]),
                ]),

                Tab::make('عن موتوجيت')->icon('heroicon-o-information-circle')->schema([
                    static::toggle('about.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('about.')),
                    static::textarea('about.text', 'النص', 4),
                    Forms\Components\Repeater::make('about.bullets')
                        ->label('النقاط')
                        ->simple(static::text('text', 'نقطة')->required())
                        ->reorderable()->defaultItems(0),
                    Forms\Components\Grid::make(2)->schema([
                        static::image('about.image', 'الصورة الكبيرة', 'site/home'),
                        static::image('about.image2', 'الصورة الصغيرة', 'site/home'),
                        static::text('about.year_label', 'نص الخبرة'),
                        static::text('about.year', 'سنة البداية'),
                        static::text('about.video_url', 'رابط فيديو يوتيوب (زرار التشغيل)')->url(),
                        static::text('about.button_text', 'نص الزرار'),
                        static::link('about.button_url'),
                    ]),
                ]),

                Tab::make('ابدأ مغامرتك')->icon('heroicon-o-flag')->schema([
                    static::toggle('adventure.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('adventure.')),
                    static::image('adventure.bg_image', 'صورة الخلفية', 'site/home'),
                    Forms\Components\Repeater::make('adventure.items')
                        ->label('الكروت')
                        ->schema([
                            static::text('title', 'العنوان')->required(),
                            static::textarea('text', 'الوصف', 2),
                            static::image('icon', 'الأيقونة', 'site/icons')->imageEditor(false),
                        ])
                        ->reorderable()->collapsible()->cloneable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['title'] ?? null),
                ]),

                Tab::make('أسطول الدراجات')->icon('heroicon-o-truck')->schema([
                    static::toggle('fleet.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('fleet.')),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('fleet.source')->label('الموديلات المعروضة')->options([
                            'featured' => 'المميزة أولاً (علّم عليها من صفحة المكن) ثم الأحدث',
                            'latest' => 'الأحدث إضافة',
                            'offers' => 'العروض الخاصة فقط',
                        ])->required(),
                        Forms\Components\TextInput::make('fleet.limit')->label('عدد الموديلات')->numeric()->minValue(1)->maxValue(30),
                    ]),
                ]),

                Tab::make('لماذا تختارنا')->icon('heroicon-o-check-badge')->schema([
                    static::toggle('why.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('why.')),
                    static::textarea('why.text', 'النص'),
                    Forms\Components\Repeater::make('why.items')
                        ->label('المميزات')
                        ->schema([
                            static::text('title', 'العنوان')->required(),
                            static::textarea('text', 'الوصف', 2),
                            static::image('icon', 'الأيقونة', 'site/icons')->imageEditor(false),
                        ])
                        ->reorderable()->collapsible()->cloneable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['title'] ?? null),
                    static::image('why.image', 'الصورة', 'site/home'),
                    Forms\Components\Fieldset::make('بوكس "محتاج مساعدة؟"')->schema([
                        static::text('why.help_text', 'النص'),
                        static::text('why.help_phone', 'رقم الهاتف'),
                        static::image('why.help_icon', 'الأيقونة', 'site/icons')->imageEditor(false),
                    ])->columns(2),
                ]),

                Tab::make('الفئات')->icon('heroicon-o-squares-2x2')->schema([
                    static::toggle('categories.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('categories.')),
                    Forms\Components\Repeater::make('categories.items')
                        ->label('الفئات')
                        ->helperText('بتظهر في الرئيسية وفي الفوتر، وبتفتح المعرض مفلتر على الفئة.')
                        ->schema([
                            static::text('label', 'الاسم')->required(),
                            Forms\Components\Select::make('category')->label('فئة الموديلات')->options(static::categoryOptions()),
                            static::image('image', 'الصورة', 'site/categories')->columnSpanFull(),
                        ])
                        ->columns(2)->reorderable()->collapsible()->cloneable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['label'] ?? null),
                ]),

                Tab::make('خطوات الامتلاك')->icon('heroicon-o-list-bullet')->schema([
                    static::toggle('steps.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('steps.')),
                    static::image('steps.bg_image', 'صورة الخلفية', 'site/home'),
                    Forms\Components\Repeater::make('steps.items')
                        ->label('الخطوات (الترقيم تلقائي)')
                        ->schema([
                            Forms\Components\Select::make('icon')->label('الأيقونة')
                                ->options(static::stepIcons())->searchable()
                                ->placeholder('تلقائي حسب ترتيب الخطوة'),
                            static::text('title', 'العنوان')->required(),
                            static::textarea('text', 'الوصف', 2),
                        ])
                        ->reorderable()->collapsible()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['title'] ?? null),
                    static::image('steps.image', 'الصورة أسفل الخطوات', 'site/home'),
                ]),

                Tab::make('آراء العملاء')->icon('heroicon-o-chat-bubble-left-right')->schema([
                    static::toggle('testimonials.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('testimonials.')),
                    Forms\Components\Placeholder::make('reviews_hint')->hiddenLabel()
                        ->content('الآراء نفسها بتتضاف وتتعدل من "آراء العملاء" في القائمة الجانبية.'),
                ]),

                Tab::make('المدونة')->icon('heroicon-o-newspaper')->schema([
                    static::toggle('blog.enabled'),
                    Forms\Components\Grid::make(2)->schema(static::headings('blog.')),
                    Forms\Components\TextInput::make('blog.limit')->label('عدد المقالات')->numeric()->minValue(1)->maxValue(12),
                ]),

                Tab::make('SEO')->icon('heroicon-o-magnifying-glass')->schema([
                    static::seoSection()->collapsed(false),
                ]),
            ]),
        ];
    }
}
