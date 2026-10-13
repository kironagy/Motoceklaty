<?php

namespace App\Filament\Clusters\WebsiteSettings\Pages;

use App\Filament\Support\SiteSettingsPage;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;

class BlogSettings extends SiteSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationLabel = 'المدونة';

    protected static ?string $title = 'صفحة المدونة';

    protected static ?int $navigationSort = 6;

    public static function contentGroup(): string
    {
        return 'blog';
    }

    protected function previewUrl(): ?string
    {
        return route('blog.index');
    }

    protected function formSchema(): array
    {
        return [
            Tabs::make('blog')->persistTabInQueryString()->tabs([
                Tab::make('صفحة المقالات')->icon('heroicon-o-squares-2x2')->schema([
                    static::bannerSection(),
                    Forms\Components\Grid::make(2)->schema([
                        ...static::headings(),
                        Forms\Components\Select::make('card_style')->label('شكل كروت المقالات')->options([
                            'classic' => 'كلاسيك (التاريخ على الصورة)',
                            'grid' => 'شبكة (التاريخ تحت الصورة)',
                        ])->required(),
                        Forms\Components\TextInput::make('per_page')->label('عدد المقالات في الصفحة')->numeric()->minValue(1)->maxValue(30),
                        static::text('empty_text', 'النص لو مفيش مقالات')->columnSpanFull(),
                    ]),
                    static::seoSection(),
                ]),

                Tab::make('صفحة المقال والتعليقات')->icon('heroicon-o-document-text')->schema([
                    Forms\Components\Grid::make(2)->schema([
                        static::text('detail.author_prefix', 'كلمة قبل اسم الكاتب'),
                        static::text('detail.tags_label', 'عنوان الوسوم'),
                        static::text('detail.share_label', 'عنوان المشاركة'),
                        static::text('detail.comments_title', 'عنوان التعليقات'),
                        static::text('detail.form_title', 'عنوان فورم التعليق'),
                        static::text('detail.submit', 'زرار نشر التعليق'),
                        static::text('detail.name_placeholder', 'خانة الاسم'),
                        static::text('detail.email_placeholder', 'خانة الإيميل'),
                        static::text('detail.body_placeholder', 'خانة التعليق'),
                        static::text('detail.success', 'رسالة بعد إرسال التعليق'),
                    ]),
                ]),
            ]),
        ];
    }
}
