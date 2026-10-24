<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LetterResource\Pages;
use App\Models\Letter;
use App\Models\Warehouse;
use App\Models\Inventory;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;

class LetterResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use \App\Filament\Concerns\HasFullAccess;
    protected static ?string $model = Letter::class;

    protected static ?string $navigationLabel = 'الجوابات';
    protected static ?string $modelLabel = 'جواب';
    protected static ?string $pluralModelLabel = 'الجوابات';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'إدارة المعرض';
    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return \App\Support\Access::allows(static::class, 'view');
    }
protected static function isSuperAdmin(): bool
{
    $user = auth()->user();

    return (bool) (
        $user
        && (
            static::fullAccess()
        )
    );
}
    protected static function adminCannotEditMoney(): bool
    {
        $user = auth()->user();

        return (bool) (
            $user
            && (
                ! static::fullAccess()
            )
        );
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
      Forms\Components\Section::make('بيانات المخزون')
    ->icon('heroicon-o-building-storefront')
    ->schema([
       Select::make('warehouse_id')
    ->label('المخزن اللي المكنة طالعة منه')
    ->options(fn () => Warehouse::pluck('name', 'id'))
    ->searchable()
    ->preload()
    ->native(false)
    ->required()
    ->live()
    ->afterStateHydrated(function ($state, Forms\Set $set, ?Letter $record) {
        if ($record?->inventory) {
            $set('warehouse_id', $record->inventory->warehouse_id);
        }
    })
    ->visible(fn () => static::isSuperAdmin())
    ->dehydrated(false), // مهم
        Select::make('inventory_id')
            ->label('اسم المكنة')
            ->options(function (Forms\Get $get) {
                return Inventory::query()
                    ->with(['product', 'warehouse'])
                    ->when($get('warehouse_id'), fn ($q, $warehouseId) => $q->where('warehouse_id', $warehouseId))
                    ->get()
                    ->mapWithKeys(fn ($item) => [
                        $item->id => ($item->product?->machine?->name ?? $item->product?->name ?? 'بدون اسم مكنة') . ' - ' . ($item->warehouse->name ?? 'بدون مخزن'),
                    ])
                    ->toArray();
            })
            ->searchable()
            ->preload()
            ->native(false)
            ->required()
            ->visible(fn () => static::isSuperAdmin())
            ->dehydrated(fn () => static::isSuperAdmin()),

        Forms\Components\Placeholder::make('warehouse_name')
            ->label('المخزن الحالي')
            ->content(fn (?Letter $record) =>
                $record?->warehouse?->name
                ?? $record?->inventory?->warehouse?->name
                ?? 'لا يوجد مخزن'
            )
            ->visible(fn () => ! static::isSuperAdmin()),

        Forms\Components\Placeholder::make('product_name')
            ->label('اسم المكنة الحالي')
            ->content(fn (?Letter $record) =>
                $record?->inventory?->product?->machine?->name ?? $record?->inventory?->product?->name ?? 'لا يوجد اسم مكنة'
            )
            ->visible(fn () => ! static::isSuperAdmin()),
    ])
    ->columns(2),
                Forms\Components\Section::make('صور وبيانات المكنة')
                    ->icon('heroicon-o-photo')
                    ->schema([
                        Forms\Components\FileUpload::make('chassis_image')
                            ->label('صورة الشاسيه')
                            ->image()
                            ->directory('chassis'),

                        Forms\Components\FileUpload::make('engine_image')
                            ->label('صورة الماتور')
                            ->image()
                            ->directory('engine'),

                        Forms\Components\TextInput::make('color')->label('اللون'),
                        Forms\Components\TextInput::make('chassis_number')->label('رقم الشاسيه'),
                        Forms\Components\TextInput::make('engine_number')->label('رقم الماتور'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('بيانات العميل')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Forms\Components\TextInput::make('customer_name')->label('اسم العميل'),
                        Forms\Components\TextInput::make('national_id')->label('الرقم القومي'),
                        Forms\Components\TextInput::make('phone')->label('رقم الهاتف'),

                        Forms\Components\Textarea::make('address')
                            ->label('العنوان')
                            ->columnSpanFull(),
           Forms\Components\FileUpload::make('customer_id_image')
            ->label('صورة البطاقة')
            ->image()
            ->directory('customer-id')
            ->imageEditor()
            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('البيانات المالية')
                    ->icon('heroicon-o-banknotes')
                    ->description(fn () => static::adminCannotEditMoney()
                        ? 'مش مسموح غير للسوبر ادمن بالتعديل علي البيانات المالية'
                        : null
                    )
                    ->schema([
                        Forms\Components\TextInput::make('cash')
                            ->label('كاش')
                            ->numeric()
                            ->disabled(fn () => static::adminCannotEditMoney())
                            ->dehydrated(fn () => ! static::adminCannotEditMoney()),

                        Forms\Components\TextInput::make('limit')
                            ->label('ليمت')
                            ->numeric()
                            ->disabled(fn () => static::adminCannotEditMoney())
                            ->dehydrated(fn () => ! static::adminCannotEditMoney()),

                        Forms\Components\TextInput::make('admin_fees')
                            ->label('مصاريف إدارية')
                            ->numeric()
                            ->disabled(fn () => static::adminCannotEditMoney())
                            ->dehydrated(fn () => ! static::adminCannotEditMoney()),

                        Forms\Components\TextInput::make('paid')
                            ->label('المدفوع')
                            ->numeric()
                            ->disabled(fn () => static::adminCannotEditMoney())
                            ->dehydrated(fn () => ! static::adminCannotEditMoney()),

                        Forms\Components\TextInput::make('remaining')
                            ->label('المتبقي')
                            ->numeric()
                            ->disabled(fn () => static::adminCannotEditMoney())
                            ->dehydrated(fn () => ! static::adminCannotEditMoney()),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('حالة الجواب')
                    ->icon('heroicon-o-check-badge')
                    ->schema([
                        Select::make('status')
                            ->label('الحالة')
                            ->options([
                                'لم يتم التنفيذ' => 'لم يتم التنفيذ',
                                'جاري التنفيذ' => 'جاري التنفيذ',
                                'تم التنفيذ' => 'تم التنفيذ',
                                'لم يتم التسليم' => 'لم يتم التسليم',
                                'تم التسليم للعميل' => 'تم التسليم للعميل',
                            ])
                            ->default('لم يتم التسليم')
                            ->required()
                            ->native(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder('ابحث باسم العميل، المكنة، أو المعرض...')
            ->emptyStateHeading('لا توجد جوابات حتى الآن')
            ->emptyStateDescription('ابدأ بإضافة جواب جديد وسيظهر هنا بشكل كارت منظم.')
            ->emptyStateIcon('heroicon-o-document-text')
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\TextColumn::make('status')
                        ->label('الحالة')
                        ->badge()
                        ->alignCenter()
                        ->icon(fn (?string $state): string => match ($state) {
                            'تم التسليم', 'تم التسليم للعميل' => 'heroicon-o-check-circle',
                            'تم التنفيذ' => 'heroicon-o-check-badge',
                            'جاري التنفيذ' => 'heroicon-o-clock',
                            'لم يتم التنفيذ' => 'heroicon-o-x-circle',
                            default => 'heroicon-o-minus-circle',
                        })
                        ->color(fn (?string $state): string => match ($state) {
                            'تم التسليم', 'تم التسليم للعميل' => 'success',
                            'تم التنفيذ' => 'info',
                            'جاري التنفيذ' => 'warning',
                            'لم يتم التنفيذ' => 'danger',
                            default => 'gray',
                        }),

                    Tables\Columns\TextColumn::make('inventory.product.name')
                        ->formatStateUsing(fn ($state, Letter $record) => $record->inventory?->product?->machine?->name ?? $state)
                        ->label('اسم المكنة')
                        ->icon('heroicon-o-cube')
                        ->weight(FontWeight::Bold)
                        ->size('lg')
                        ->searchable()
                        ->default('-'),

                    Tables\Columns\TextColumn::make('customer_name')
                        ->label('اسم العميل')
                        ->icon('heroicon-o-user-circle')
                        ->weight(FontWeight::SemiBold)
                        ->searchable()
                        ->default('-'),

Tables\Columns\TextColumn::make('inventory.warehouse.name')     
                   ->label('المعرض / المخزن')
                        ->icon('heroicon-o-building-storefront')
                        ->color('gray')
                        ->searchable()
                        ->default('-'),

                    Tables\Columns\TextColumn::make('phone')
                        ->label('رقم الهاتف')
                        ->icon('heroicon-o-phone')
                        ->color('gray')
                        ->copyable()
                        ->default('-'),

                    Tables\Columns\TextColumn::make('created_at')
                        ->label('تاريخ الإضافة')
                        ->icon('heroicon-o-calendar-days')
                        ->dateTime('Y-m-d h:i A')
                        ->color('gray')
                        ->sortable(),
                ])->space(3),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('print_sale')
                        ->label('طباعة المبايعة')
                        ->icon('heroicon-o-printer')
                        ->color('warning')
                        ->url(fn ($record) => route('letters.print-sale', $record))
                        ->openUrlInNewTab(),

                    Tables\Actions\EditAction::make()
                        ->label('تعديل')
                        ->icon('heroicon-o-pencil-square')
                        ->color('info'),
Tables\Actions\Action::make('print_raed')
    ->label('طباعة لرائد')
    ->icon('heroicon-o-printer')
    ->color('warning')
    ->url(fn (Letter $record): string => route('letters.print-raed', $record))
    ->openUrlInNewTab(),

                    Tables\Actions\DeleteAction::make()
                        ->label('حذف')
                        ->icon('heroicon-o-trash')
                        ->color('danger'),
                ])
                    ->label('الإجراءات')
                    ->icon('heroicon-o-ellipsis-horizontal')
                    ->button()
                    ->color('gray'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLetters::route('/'),
            'create' => Pages\CreateLetter::route('/create'),
            'edit' => Pages\EditLetter::route('/{record}/edit'),
        ];
    }
}
