<?php

namespace App\Filament\Clusters\WebsiteSettings\Resources;

use App\Filament\Support\HitlerOnly;
use App\Filament\Clusters\WebsiteSettings;
use App\Filament\Clusters\WebsiteSettings\Resources\PostResource\Pages;
use App\Models\Post;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PostResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use HitlerOnly;

    protected static ?string $model = Post::class;

    protected static ?string $cluster = WebsiteSettings::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'محتوى الموقع';

    protected static ?string $navigationLabel = 'مقالات المدونة';

    protected static ?string $modelLabel = 'مقال';

    protected static ?string $pluralModelLabel = 'مقالات المدونة';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Group::make()->columnSpan(['lg' => 2])->schema([
                Forms\Components\Section::make()->schema([
                    Forms\Components\TextInput::make('title')->label('عنوان المقال')->required()->maxLength(255),
                    Forms\Components\TextInput::make('slug')->label('رابط المقال (Slug)')
                        ->helperText('اتركه فاضي وهيتعمل تلقائياً من العنوان.')
                        ->unique(ignoreRecord: true)->maxLength(255),
                    Forms\Components\Textarea::make('excerpt')->label('ملخص قصير')->rows(2)
                        ->helperText('اختياري - بيظهر في نتائج البحث لو مفيش وصف SEO.'),
                    Forms\Components\RichEditor::make('body')->label('محتوى المقال')
                        ->fileAttachmentsDisk('public')
                        ->fileAttachmentsDirectory('site/blog/attachments')
                        ->columnSpanFull(),
                ]),
            ]),

            Forms\Components\Group::make()->columnSpan(['lg' => 1])->schema([
                Forms\Components\Section::make('النشر')->schema([
                    Forms\Components\Toggle::make('is_published')->label('منشور')->default(true),
                    Forms\Components\DateTimePicker::make('published_at')->label('تاريخ النشر')->default(now())
                        ->helperText('لو تاريخ قدام، المقال هيظهر لوحده في الميعاد ده.'),
                    Forms\Components\Toggle::make('allow_comments')->label('السماح بالتعليقات')->default(true),
                ]),
                Forms\Components\Section::make('التفاصيل')->schema([
                    Forms\Components\FileUpload::make('cover_image')->label('صورة المقال')
                        ->image()->disk('public')->directory('site/blog')->imageEditor()->required(),
                    Forms\Components\TextInput::make('author_name')->label('الكاتب')->placeholder('فريق موتوجيت'),
                    Forms\Components\TagsInput::make('tags')->label('الوسوم'),
                    Forms\Components\Textarea::make('meta_description')->label('وصف SEO')->rows(2)->maxLength(500),
                ]),
            ]),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover_image')->label('الصورة')->disk('public')
                    ->getStateUsing(fn (Post $record) => $record->coverUrl()),
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable()->limit(60)->wrap(),
                Tables\Columns\TextColumn::make('author_name')->label('الكاتب')->toggleable(),
                Tables\Columns\IconColumn::make('is_published')->label('منشور')->boolean(),
                Tables\Columns\TextColumn::make('comments_count')->label('التعليقات')->counts('comments')->badge(),
                Tables\Columns\TextColumn::make('published_at')->label('تاريخ النشر')->date('Y-m-d')->sortable(),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')->label('منشور'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')->label('عرض')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (Post $record) => route('blog.show', $record), shouldOpenInNewTab: true),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
