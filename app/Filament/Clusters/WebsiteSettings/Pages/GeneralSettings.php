<?php

namespace App\Filament\Clusters\WebsiteSettings\Pages;

use App\Filament\Support\SiteSettingsPage;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;

class GeneralSettings extends SiteSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'الإعدادات العامة';

    protected static ?string $title = 'الإعدادات العامة للموقع';

    protected static ?int $navigationSort = 1;

    public static function contentGroup(): string
    {
        return 'general';
    }

    protected function previewUrl(): ?string
    {
        return route('home');
    }

    public static function socialIcons(): array
    {
        return [
            'fa-brands fa-facebook-f' => 'Facebook',
            'feather feather-instagram' => 'Instagram',
            'fa-brands fa-youtube' => 'YouTube',
            'fa-brands fa-tiktok' => 'TikTok',
            'fa-brands fa-whatsapp' => 'WhatsApp',
            'fa-brands fa-x-twitter' => 'X (Twitter)',
            'fa-brands fa-telegram' => 'Telegram',
            'fa-brands fa-snapchat' => 'Snapchat',
            'fa-brands fa-linkedin-in' => 'LinkedIn',
        ];
    }

    protected function formSchema(): array
    {
        return [
            Tabs::make('general')->persistTabInQueryString()->tabs([
                Tab::make('الهوية والشعار')->icon('heroicon-o-identification')->columns(2)->schema([
                    static::text('site_name', 'اسم الموقع')->required(),
                    Forms\Components\Placeholder::make('brand_hint')
                        ->hiddenLabel()
                        ->content('الاسم ده بيظهر في نص الصور البديل وفي آخر عناوين الصفحات.'),
                    static::image('logo', 'اللوجو (الهيدر)', 'site/brand'),
                    static::image('footer_logo', 'اللوجو (الفوتر)', 'site/brand'),
                    static::image('favicon', 'أيقونة المتصفح (Favicon)', 'site/brand')->imageEditor(false),
                ]),

                Tab::make('بيانات التواصل')->icon('heroicon-o-phone')->schema([
                    Forms\Components\Grid::make(2)->schema([
                        static::text('phone', 'رقم الهاتف'),
                        static::text('whatsapp', 'رقم الواتساب'),
                        static::text('email', 'البريد الإلكتروني')->email(),
                        static::text('address', 'العنوان'),
                    ]),
                    static::toggle('show_header_email', 'إظهار الإيميل في الهيدر'),
                    Forms\Components\Repeater::make('working_hours')
                        ->label('مواعيد العمل')
                        ->helperText('بتظهر في الفوتر وصفحة تواصل معنا.')
                        ->schema([
                            static::text('day', 'الأيام')->required(),
                            static::text('time', 'المواعيد')->required(),
                        ])
                        ->columns(2)->reorderable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['day'] ?? null),
                    Forms\Components\Repeater::make('social')
                        ->label('السوشيال ميديا')
                        ->schema([
                            Forms\Components\Select::make('icon')->label('المنصة')->options(static::socialIcons())->required(),
                            Forms\Components\TextInput::make('url')->label('الرابط')->url()->required(),
                        ])
                        ->columns(2)->reorderable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => static::socialIcons()[$state['icon'] ?? ''] ?? null),
                ]),

                Tab::make('القائمة الرئيسية')->icon('heroicon-o-bars-3')->schema([
                    Forms\Components\Repeater::make('menu')
                        ->label('روابط القائمة في الهيدر')
                        ->schema([
                            static::text('label', 'اسم الرابط')->required(),
                            static::link('url', 'الرابط')->required(),
                        ])
                        ->columns(2)->reorderable()->cloneable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['label'] ?? null),
                ]),

                Tab::make('الفوتر')->icon('heroicon-o-rectangle-group')->schema([
                    static::image('footer.bg_image', 'صورة خلفية الفوتر', 'site/brand')
                        ->helperText('يفضل صورة غامقة عرض 1920 بيكسل أو أكبر - النصوص فوقها بيضا. لو مسحتها الفوتر هيبقى بلون غامق سادة.'),
                    static::textarea('footer.about_text', 'نبذة الفوتر'),
                    Forms\Components\Grid::make(3)->schema([
                        static::text('footer.hours_title', 'عنوان مواعيد العمل'),
                        static::text('footer.links_title', 'عنوان الروابط السريعة'),
                        static::text('footer.categories_title', 'عنوان فئات الدراجات')
                            ->helperText('الفئات نفسها بتتعدل من الصفحة الرئيسية > الفئات.'),
                    ]),
                    Forms\Components\Repeater::make('footer.links')
                        ->label('الروابط السريعة')
                        ->schema([
                            static::text('label', 'اسم الرابط')->required(),
                            static::link('url', 'الرابط')->required(),
                        ])
                        ->columns(2)->reorderable()->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['label'] ?? null),
                    Forms\Components\Fieldset::make('النشرة البريدية')->schema([
                        static::toggle('footer.newsletter_enabled', 'إظهار الاشتراك في النشرة'),
                        static::text('footer.newsletter_text', 'نص الدعوة للاشتراك'),
                        static::text('footer.newsletter_placeholder', 'النص داخل خانة الإيميل'),
                        static::text('footer.newsletter_success', 'رسالة نجاح الاشتراك'),
                    ])->columns(2),
                    Forms\Components\Grid::make(2)->schema([
                        static::text('footer.copyright_brand', 'اسم البراند في حقوق النشر'),
                        static::text('footer.copyright_text', 'نص حقوق النشر')
                            ->helperText('السنة بتتحدّث لوحدها.'),
                    ]),
                ]),

                Tab::make('شاشة التحميل')->icon('heroicon-o-play-circle')->schema([
                    static::toggle('loader.enabled', 'تشغيل فيديو شاشة التحميل'),
                    Forms\Components\Grid::make(2)->schema([
                        static::video('loader.video', 'فيديو الكمبيوتر (MP4)'),
                        static::video('loader.video_mobile', 'فيديو الموبايل (MP4)'),
                    ]),
                ]),

                Tab::make('SEO العام')->icon('heroicon-o-magnifying-glass')->schema([
                    static::text('seo.title_suffix', 'لاحقة عناوين الصفحات')
                        ->helperText('بتتضاف بعد اسم المكنة أو المقال في عنوان الصفحة، مثال: "دايو ٤ | موتوجيت MotoGate".'),
                    static::textarea('seo.keywords', 'الكلمات المفتاحية', 2),
                    static::textarea('seo.description', 'الوصف الافتراضي للصفحات', 2),
                    static::image('seo.og_image', 'صورة المشاركة على السوشيال (OG Image)', 'site/brand'),
                ]),

                Tab::make('أكواد وإضافات')->icon('heroicon-o-code-bracket')->schema([
                    static::toggle('integrations.tawk_enabled', 'تشغيل شات Tawk.to'),
                    static::text('integrations.tawk_src', 'رابط سكريبت Tawk.to')->url(),
                    static::toggle('integrations.protect_images', 'منع حفظ الصور بالكليك يمين'),
                    static::textarea('integrations.head_code', 'كود إضافي داخل <head> (Google Analytics، Pixel ...)', 4)
                        ->extraInputAttributes(['dir' => 'ltr', 'style' => 'font-family: monospace']),
                    static::textarea('integrations.footer_code', 'كود إضافي قبل نهاية الصفحة', 4)
                        ->extraInputAttributes(['dir' => 'ltr', 'style' => 'font-family: monospace']),
                ]),
            ]),
        ];
    }
}
