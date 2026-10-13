<?php

namespace App\Filament\Clusters\WebsiteSettings\Resources;

use App\Filament\Support\HitlerOnly;
use App\Filament\Clusters\WebsiteSettings;
use App\Filament\Clusters\WebsiteSettings\Resources\PostCommentResource\Pages;
use App\Models\PostComment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class PostCommentResource extends Resource
{
    use HitlerOnly;

    protected static ?string $model = PostComment::class;

    protected static ?string $cluster = WebsiteSettings::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';

    protected static ?string $navigationGroup = 'رسائل الزوار';

    protected static ?string $navigationLabel = 'تعليقات المقالات';

    protected static ?string $modelLabel = 'تعليق';

    protected static ?string $pluralModelLabel = 'تعليقات المقالات';

    protected static ?int $navigationSort = 31;

    public static function getNavigationBadge(): ?string
    {
        $pending = PostComment::where('is_approved', false)->count();

        return $pending ? (string) $pending : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('الاسم')->required(),
            Forms\Components\TextInput::make('email')->label('الإيميل')->email()->required(),
            Forms\Components\Textarea::make('body')->label('التعليق')->rows(4)->required()->columnSpanFull(),
            Forms\Components\Toggle::make('is_approved')->label('منشور على الموقع'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('post.title')->label('المقال')->limit(40),
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable()
                    ->description(fn (PostComment $record) => $record->email),
                Tables\Columns\TextColumn::make('body')->label('التعليق')->limit(80)->wrap(),
                Tables\Columns\ToggleColumn::make('is_approved')->label('منشور'),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_approved')->label('منشور'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('approve')->label('نشر المحدد')->icon('heroicon-o-check')
                    ->action(fn (Collection $records) => $records->each->update(['is_approved' => true]))
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePostComments::route('/')];
    }
}
