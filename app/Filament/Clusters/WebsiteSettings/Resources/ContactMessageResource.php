<?php

namespace App\Filament\Clusters\WebsiteSettings\Resources;

use App\Filament\Support\HitlerOnly;
use App\Filament\Clusters\WebsiteSettings;
use App\Filament\Clusters\WebsiteSettings\Resources\ContactMessageResource\Pages;
use App\Models\ContactMessage;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ContactMessageResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use HitlerOnly;

    protected static ?string $model = ContactMessage::class;

    protected static ?string $cluster = WebsiteSettings::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationGroup = 'رسائل الزوار';

    protected static ?string $navigationLabel = 'رسائل تواصل معنا';

    protected static ?string $modelLabel = 'رسالة';

    protected static ?string $pluralModelLabel = 'رسائل تواصل معنا';

    protected static ?int $navigationSort = 30;

    public static function getNavigationBadge(): ?string
    {
        $unread = ContactMessage::where('is_read', false)->count();

        return $unread ? (string) $unread : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('name')->label('الاسم'),
            Infolists\Components\TextEntry::make('phone')->label('الهاتف')
                ->url(fn (ContactMessage $record) => 'https://wa.me/2' . ltrim(preg_replace('/\D/', '', $record->phone), '2'), shouldOpenInNewTab: true)
                ->color('primary'),
            Infolists\Components\TextEntry::make('email')->label('الإيميل')->placeholder('-'),
            Infolists\Components\TextEntry::make('subject')->label('نوع الاستفسار')->placeholder('-'),
            Infolists\Components\TextEntry::make('message')->label('الرسالة')->columnSpanFull(),
            Infolists\Components\TextEntry::make('created_at')->label('وصلت')->dateTime('Y-m-d H:i'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordClasses(fn (ContactMessage $record) => $record->is_read ? null : 'font-bold')
            ->columns([
                Tables\Columns\IconColumn::make('is_read')->label('')->boolean()
                    ->trueIcon('heroicon-o-envelope-open')->falseIcon('heroicon-s-envelope')
                    ->trueColor('gray')->falseColor('warning'),
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('الهاتف')->searchable(),
                Tables\Columns\TextColumn::make('subject')->label('النوع')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('message')->label('الرسالة')->limit(60),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_read')->label('مقروءة'),
            ])
            ->actions([
                // Opening the message counts as reading it (a view modal has no submit, so no after()).
                Tables\Actions\ViewAction::make()->mountUsing(fn (ContactMessage $record) => $record->is_read || $record->update(['is_read' => true])),
                Tables\Actions\Action::make('toggleRead')
                    ->label(fn (ContactMessage $record) => $record->is_read ? 'غير مقروءة' : 'مقروءة')
                    ->icon(fn (ContactMessage $record) => $record->is_read ? 'heroicon-o-envelope' : 'heroicon-o-envelope-open')
                    ->color('gray')
                    ->action(fn (ContactMessage $record) => $record->update(['is_read' => ! $record->is_read])),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('read')->label('تحديد كمقروءة')->icon('heroicon-o-envelope-open')
                    ->action(fn (Collection $records) => $records->each->update(['is_read' => true]))
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageContactMessages::route('/')];
    }
}
