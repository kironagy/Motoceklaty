<?php

namespace App\Filament\Clusters\WebsiteSettings\Resources;

use App\Filament\Support\HitlerOnly;
use App\Filament\Clusters\WebsiteSettings;
use App\Filament\Clusters\WebsiteSettings\Resources\NewsletterSubscriberResource\Pages;
use App\Models\NewsletterSubscriber;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NewsletterSubscriberResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use HitlerOnly;

    protected static ?string $model = NewsletterSubscriber::class;

    protected static ?string $cluster = WebsiteSettings::class;

    protected static ?string $navigationIcon = 'heroicon-o-at-symbol';

    protected static ?string $navigationGroup = 'رسائل الزوار';

    protected static ?string $navigationLabel = 'مشتركين النشرة البريدية';

    protected static ?string $modelLabel = 'مشترك';

    protected static ?string $pluralModelLabel = 'مشتركين النشرة البريدية';

    protected static ?int $navigationSort = 32;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('email')->label('الإيميل')->email()->required()->unique(ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('email')->label('الإيميل')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('created_at')->label('تاريخ الاشتراك')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageNewsletterSubscribers::route('/')];
    }
}
