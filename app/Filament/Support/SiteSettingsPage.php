<?php

namespace App\Filament\Support;

use App\Filament\Clusters\WebsiteSettings;
use App\Models\SiteSetting;
use App\Support\SiteContent;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * One editable page of the public website. Subclasses name their content
 * group (see App\Support\SiteDefaults) and describe the form; loading,
 * saving, "view page" and "reset to design" live here.
 */
abstract class SiteSettingsPage extends Page implements HasForms
{
    use HitlerOnly;
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static ?string $cluster = WebsiteSettings::class;

    protected static string $view = 'filament.website-settings.page';

    protected static ?string $navigationGroup = 'صفحات الموقع';

    public ?array $data = [];

    /** Key in SiteDefaults::all() / site_settings.key. */
    abstract public static function contentGroup(): string;

    abstract protected function formSchema(): array;

    /** Public URL this page edits, for the "view page" button. */
    protected function previewUrl(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        SiteContent::publishDefaults();

        $this->form->fill(SiteContent::group(static::contentGroup()));
    }

    public function form(Form $form): Form
    {
        return $form->schema(array_merge($this->approvedSchema(), $this->designSchema()))->statePath('data');
    }

    /** Controls correspond to the approved source, on the existing page URLs. */
    protected function approvedSchema(): array
    {
        $section = fn ($title, $fields) => Forms\Components\Section::make($title)->schema($fields)->collapsible();
        $repeater = fn ($key, $label, $fields) => Forms\Components\Repeater::make($key)->label($label)->schema($fields)->reorderable()->collapsible()->defaultItems(0);
        return match (static::contentGroup()) {
            'home' => [static::seoSection(), $section('الهيرو والحاسبة', [
                static::image('hero.bg_image', 'خلفية الهيرو'), static::image('hero.calculator_image', 'صورة الحاسبة'),
                static::text('hero.calc_title', 'عنوان الحاسبة'),
                $repeater('hero.slides', 'صور سلايدر الهيرو', [static::text('title', 'الموديل'), static::image('image', 'الصورة')]),
                static::link('about.video_url', 'رابط فيديو حكايتنا'),
            ]), $section('الأقسام الموجودة في التصميم', [
                static::toggle('about.enabled', 'إظهار حكايتنا'), static::toggle('fleet.enabled', 'إظهار الموديلات'),
                Forms\Components\Select::make('fleet.source')->label('مصدر الموديلات')->options(['featured'=>'المميزة', 'offers'=>'العروض', 'all'=>'الكل']),
                Forms\Components\TextInput::make('fleet.limit')->label('عدد الموديلات')->numeric()->minValue(1)->maxValue(30),
                static::toggle('blog.enabled', 'إظهار المجلة'), Forms\Components\TextInput::make('blog.limit')->label('عدد المقالات')->numeric()->minValue(1)->maxValue(30),
                static::toggle('why.enabled', 'إظهار المزايا'), static::toggle('categories.enabled', 'إظهار الفئات'),
                $repeater('categories.items', 'كروت الفئات', [static::text('label', 'الاسم'), Forms\Components\Select::make('category')->label('الفئة')->options(static::categoryOptions()), static::image('image', 'الصورة')]),
            ])],
            'about' => [static::seoSection(), $section('الفريق والآراء والأسئلة', [
                static::toggle('features.enabled', 'إظهار شريط المميزات'), static::toggle('why.enabled', 'إظهار أسباب الاختيار'),
                static::toggle('team.enabled', 'إظهار بطاقات الموظفين'), Forms\Components\TextInput::make('team.limit')->label('عدد الموظفين')->numeric()->minValue(1)->maxValue(50),
                static::toggle('testimonial.enabled', 'إظهار آراء العملاء'), static::toggle('cta.enabled', 'إظهار الانضمام والتعاون'),
                static::toggle('faq.enabled', 'إظهار الأسئلة'), static::text('faq.title', 'عنوان الأسئلة'),
                $repeater('faq.items', 'الأسئلة', [static::text('question', 'السؤال'), static::textarea('answer', 'الإجابة')]),
                $repeater('stats.items', 'الأرقام المعروضة في عداد الصفحة الرئيسية', [static::text('number', 'الرقم'),static::text('suffix', 'اللاحقة'),static::text('label', 'الوصف')]),
            ])],
            'contact' => [static::seoSection(), $section('النموذج والفروع', [
                static::textarea('form.text', 'وصف إرسال النموذج'), static::text('form.submit', 'نص زر الإرسال'),
                static::textarea('form.success', 'رسالة النجاح'),
                $repeater('form.subjects', 'موضوعات الاستفسار', [static::text('label', 'الموضوع')]),
                static::toggle('show_branches', 'إظهار بيانات الفروع في مكان الزيارة'), static::text('branch_map_label', 'نص رابط الخريطة'),
            ])],
            default => $this->formSchema(),
        };
    }

    /** Copy and meaningful images from the approved source sections. */
    protected function designSchema(): array
    {
        $schema = [];
        foreach (SiteContent::group(static::contentGroup())['design'] ?? [] as $section => $values) {
            $fields = [];
            foreach ($values as $key => $value) {
                $path = 'design.' . $section . '.' . $key;
                if (str_starts_with($key, 'image_')) {
                    $fields[] = static::image($path, 'صورة ' . substr($key, 6), 'site/design');
                } else {
                    $label = \Illuminate\Support\Str::limit((string) $value, 70);
                    $fields[] = static::textarea($path, $label ?: $key, 2);
                }
            }
            $schema[] = Forms\Components\Section::make('التصميم المعتمد — ' . str_replace('_', ' ', $section))
                ->description('تعديل محتوى نفس عناصر التصميم، دون إضافة أقسام للواجهة.')
                ->schema($fields)->collapsible()->collapsed();
        }
        return $schema;
    }

    public function save(): void
    {
        SiteContent::save(static::contentGroup(), SiteContent::merge(SiteContent::group(static::contentGroup()), $this->form->getState()));

        Notification::make()->success()->title('تم حفظ التعديلات ونشرها على الموقع')->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('حفظ التعديلات')->submit('save')->keyBindings(['mod+s']),
        ];
    }

    protected function getHeaderActions(): array
    {
        return array_values(array_filter([
            $this->previewUrl() ? Action::make('preview')
                ->label('عرض الصفحة')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url($this->previewUrl(), shouldOpenInNewTab: true) : null,
            Action::make('reset')
                ->label('استرجاع محتوى التصميم الأصلي')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('هيتم مسح كل تعديلاتك في الصفحة دي والرجوع للنصوص والصور الأصلية للتصميم. الصور اللي رفعتها هتفضل محفوظة على السيرفر.')
                ->action(function () {
                    SiteSetting::where('key', static::contentGroup())->delete();
                    SiteContent::flush();
                    $this->form->fill(SiteContent::group(static::contentGroup()));
                    Notification::make()->success()->title('تم الرجوع للمحتوى الأصلي')->send();
                }),
        ]));
    }

    /* ------------------------------------------------------------------
     | Field builders - keep every page's form consistent.
     * ------------------------------------------------------------------ */

    protected static function text(string $name, string $label): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($name)->label($label)->maxLength(500);
    }

    protected static function textarea(string $name, string $label, int $rows = 3): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make($name)->label($label)->rows($rows)->autosize();
    }

    protected static function toggle(string $name, string $label = 'إظهار القسم ده في الموقع'): Forms\Components\Toggle
    {
        return Forms\Components\Toggle::make($name)->label($label)->inline(false);
    }

    protected static function link(string $name, string $label = 'رابط الزرار'): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($name)
            ->label($label)
            ->placeholder('/machines')
            ->helperText('رابط داخلي زي /contact أو رابط كامل يبدأ بـ https://');
    }

    protected static function image(string $name, string $label, string $directory = 'site'): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make($name)
            ->label($label)
            ->image()
            ->disk('public')
            ->directory($directory)
            ->visibility('public')
            ->imageEditor()
            ->maxSize(8192)
            ->downloadable()
            ->openable();
    }

    protected static function video(string $name, string $label, string $directory = 'site/videos'): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make($name)
            ->label($label)
            ->disk('public')
            ->directory($directory)
            ->visibility('public')
            ->acceptedFileTypes(['video/mp4', 'video/webm'])
            ->maxSize(51200);
    }

    /** Small title + main title pair used by nearly every design section. */
    protected static function headings(string $prefix = ''): array
    {
        return [
            static::text($prefix . 'small_title', 'العنوان الصغير (فوق العنوان)'),
            static::text($prefix . 'title', 'العنوان الرئيسي'),
        ];
    }

    protected static function seoSection(string $prefix = 'seo'): Forms\Components\Section
    {
        return Forms\Components\Section::make('SEO - عنوان الصفحة في جوجل')
            ->description('العنوان اللي بيظهر في تاب المتصفح ونتائج البحث.')
            ->icon('heroicon-o-magnifying-glass')
            ->collapsible()
            ->collapsed()
            ->schema([
                static::text("{$prefix}.title", 'عنوان الصفحة (Title)'),
                static::textarea("{$prefix}.description", 'وصف الصفحة (Meta description)', 2)
                    ->helperText('لو سيبته فاضي هيتستخدم الوصف العام من الإعدادات العامة.'),
            ]);
    }

    protected static function bannerSection(string $prefix = 'banner'): Forms\Components\Section
    {
        return Forms\Components\Section::make('البانر أعلى الصفحة')
            ->icon('heroicon-o-photo')
            ->columns(2)
            ->schema([
                static::text("{$prefix}.title", 'عنوان البانر'),
                static::text("{$prefix}.breadcrumb", 'اسم الصفحة في مسار التنقل'),
                static::image("{$prefix}.image", 'صورة خلفية البانر', 'site/banners')->columnSpanFull(),
            ]);
    }

    protected static function featherIconHelp(): HtmlString
    {
        return new HtmlString('اسم أيقونة Feather زي <code>feather-shield</code> أو <code>feather-heart</code> — <a href="https://feathericons.com" target="_blank" class="underline text-primary-600">شوف كل الأيقونات</a>');
    }

    protected static function categoryOptions(): array
    {
        return config('agent.catalog.categories', []);
    }
}
