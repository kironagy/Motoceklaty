<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MerchantResource\Pages;
use App\Models\Merchant;
use App\Support\Access;
use App\Support\Merchants\MerchantTenancy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Merchants (owner 2026-11-23): a showroom with its own dashboard on {subdomain}.{base domain}, its own
 * data (requests from 1, its own staff and companies) and only the pages ticked here.
 */
class MerchantResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource {
        canViewAny as roleCanViewAny;
    }

    protected static ?string $model = Merchant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'إدارة المعرض';

    protected static ?string $navigationLabel = 'التجار';

    protected static ?string $modelLabel = 'تاجر';

    protected static ?string $pluralModelLabel = 'التجار';

    protected static ?int $navigationSort = 50;

    /** Merchants are managed from the main site only, never from inside a showroom's dashboard. */
    public static function canViewAny(): bool
    {
        return MerchantTenancy::current() === null && static::roleCanViewAny();
    }

    public static function form(Form $form): Form
    {
        $base = config('merchants.base_domain');

        return $form->schema([
            Forms\Components\Section::make('التاجر')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('اسم التاجر')->required()->maxLength(255),
                Forms\Components\TextInput::make('email')->label('الإيميل')->email()->required()
                    ->unique(ignoreRecord: true)->maxLength(255),
                // Saved only in the merchant's own database (its login), never here
                Forms\Components\TextInput::make('password')->label('الباسورد')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->minLength(6)
                    ->dehydrated(false)
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'سيبه فاضي لو مش عايز تغيّره.' : null),
                Forms\Components\Toggle::make('is_active')->label('شغّال')->default(true)->inline(false),
            ]),
            Forms\Components\Section::make('المعرض')->columns(2)->schema([
                Forms\Components\TextInput::make('showroom_name')->label('اسم المعرض')->required()->maxLength(255),
                Forms\Components\TextInput::make('subdomain')->label('الدومين الفرعي')->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(40)
                    ->regex('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/')
                    ->notIn((array) config('merchants.reserved', []))
                    ->dehydrateStateUsing(fn ($state) => strtolower(trim((string) $state)))
                    ->suffix('.'.$base)
                    ->helperText('حروف إنجليزي صغيرة وأرقام وشرطة بس، مثال: aqsa'),
                Forms\Components\FileUpload::make('logo')->label('لوجو المعرض')->image()
                    ->disk('public')->directory('merchants')->imageEditor()->columnSpanFull(),
            ]),
            Forms\Components\Section::make('الصفحات اللي يوصلها')
                ->description('كل صفحة بتفتحها بتبقى ببيانات معرضه هو بس.')
                ->schema([
                    Forms\Components\CheckboxList::make('pages')->hiddenLabel()
                        ->options(fn () => static::pageOptions())
                        ->descriptions(fn () => collect(Access::tabs())->map(fn ($t) => $t['group'])->all())
                        ->default(['Dashboard'])
                        ->columns(3)
                        ->searchable()
                        ->bulkToggleable(),
                ]),
        ]);
    }

    /** @return array<string, string> every tab of the panel except this one */
    public static function pageOptions(): array
    {
        return collect(Access::tabs())
            ->except(class_basename(static::class))
            ->map(fn ($tab) => trim((string) $tab['label']))
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo')->label('اللوجو')->disk('public')->circular(),
                Tables\Columns\TextColumn::make('showroom_name')->label('المعرض')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('name')->label('التاجر')->searchable()
                    ->description(fn (Merchant $record) => $record->email),
                Tables\Columns\TextColumn::make('subdomain')->label('الرابط')
                    ->formatStateUsing(fn (Merchant $record) => $record->subdomain.'.'.config('merchants.base_domain'))
                    ->url(fn (Merchant $record) => $record->url(), true)
                    ->color('primary')->copyable()->copyableState(fn (Merchant $record) => $record->url()),
                Tables\Columns\TextColumn::make('pages')->label('الصفحات')
                    ->state(fn (Merchant $record) => count((array) $record->pages))->badge(),
                Tables\Columns\ToggleColumn::make('is_active')->label('شغّال'),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('الدومين بتاعه هيقفل. بيانات معرضه بتفضل محفوظة على السيرفر.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMerchants::route('/'),
            'create' => Pages\CreateMerchant::route('/create'),
            'edit' => Pages\EditMerchant::route('/{record}/edit'),
        ];
    }
}
