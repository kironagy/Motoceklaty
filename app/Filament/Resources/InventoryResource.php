<?php

namespace App\Filament\Resources;

use App\Support\FinanceAccess;
use App\Filament\Resources\InventoryResource\Pages;
use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\Letter;
use App\Models\Warehouse;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class InventoryResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    protected static ?string $model = Inventory::class;

    protected static ?string $navigationLabel = 'المخزون';
    protected static ?string $navigationGroup = 'إدارة المعرض';
    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return FinanceAccess::hasAccess();
    }

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('warehouse_id')
                    ->label('المخزن')
                    ->relationship('warehouse', 'name')
                    ->required(),

                Select::make('product_id')
                    ->label('الصنف')
                    ->relationship('product', 'name')
                    ->required(),

                Repeater::make('items')
                    ->label('القطع')
                    ->columnSpanFull()
                    ->relationship()
                    ->schema([
                        Hidden::make('id'),

                        FileUpload::make('chassis_image')
                            ->label('صورة الشاسيه')
                            ->image()
                            ->directory('chassis')
                            ->required(),

                        FileUpload::make('engine_image')
                            ->label('صورة الماتور')
                            ->image()
                            ->directory('engine')
                            ->required(),

                        TextInput::make('color')
                            ->label('اللون')
                            ->columnSpanFull()
                            ->required(),

                        TextInput::make('customer_name')
                            ->label('اسم العميل')
                            ->visibleOn('edit'),

                        TextInput::make('national_id')
                            ->label('الرقم القومي')
                            ->visibleOn('edit'),

                        TextInput::make('phone')
                            ->label('رقم الهاتف')
                            ->tel()
                            ->visibleOn('edit'),

                        TextInput::make('address')
                            ->label('العنوان')
                            ->columnSpanFull()
                            ->visibleOn('edit'),
                       FileUpload::make('customer_id_image')
                             ->label('صورة البطاقة')
                             ->image()
                             ->directory('customer-id')
                             ->imageEditor()
                             ->visibleOn('edit'),
                        TextInput::make('cash')
                            ->label('كاش')
                            ->numeric()
                            ->visibleOn('edit'),

                        TextInput::make('limit')
                            ->label('ليمت')
                            ->numeric()
                            ->visibleOn('edit'),

                        TextInput::make('admin_fees')
                            ->label('مصاريف إدارية')
                            ->numeric()
                            ->visibleOn('edit'),

                        TextInput::make('paid')
                            ->label('المدفوع')
                            ->numeric()
                            ->visibleOn('edit'),

                        TextInput::make('remaining')
                            ->label('المتبقي')
                            ->numeric()
                            ->visibleOn('edit'),

                        TextInput::make('chassis_number')
                            ->label('رقم الشاسيه')
                            ->visibleOn('edit'),

                        TextInput::make('engine_number')
                            ->label('رقم الماتور')
                            ->visibleOn('edit'),

                        Actions::make([
                           
    Action::make('transfer_item')
        ->visible(fn () =>
            FinanceAccess::isSuper()
        )
        ->label('تحويل لمعرض آخر')
        ->icon('heroicon-o-arrow-path-rounded-square')
        ->color('warning')
                                ->modalHeading('تحويل المكنة لمعرض آخر')
                                ->modalDescription('اختار المعرض اللي عايز تحول له المكنة.')
                                ->modalSubmitActionLabel('تم التحويل')
                                ->form([
                                    Select::make('target_warehouse_id')
                                        ->label('تحويل إلى معرض')
                                        ->options(function ($get) {
                                            $itemId = $get('id');
                                            $item = $itemId ? InventoryItem::with('inventory')->find($itemId) : null;

                                            return Warehouse::query()
                                                ->when($item?->inventory?->warehouse_id, function ($query, $currentWarehouseId) {
                                                    $query->where('id', '!=', $currentWarehouseId);
                                                })
                                                ->pluck('name', 'id')
                                                ->toArray();
                                        })
                                        ->searchable()
                                        ->preload()
                                        ->required(),
                                ])
                                ->requiresConfirmation()
                                ->action(function (array $data, $get) {
                                    $itemId = $get('id');

                                    if (! $itemId) {
                                        Notification::make()
                                            ->title('لازم تحفظ القطعة الأول قبل التحويل')
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    $item = InventoryItem::with('inventory')->find($itemId);

                                    if (! $item || ! $item->inventory) {
                                        Notification::make()
                                            ->title('القطعة غير موجودة')
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    if ((int) $item->inventory->warehouse_id === (int) $data['target_warehouse_id']) {
                                        Notification::make()
                                            ->title('مينفعش تحول لنفس المعرض')
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    DB::transaction(function () use ($item, $data) {
                                        $targetInventory = Inventory::query()->firstOrCreate([
                                            'warehouse_id' => $data['target_warehouse_id'],
                                            'product_id' => $item->inventory->product_id,
                                        ]);

                                        $item->update([
                                            'inventory_id' => $targetInventory->id,
                                        ]);
                                    });

                                    Notification::make()
                                        ->title('تم تحويل المكنة للمعرض الجديد')
                                        ->success()
                                        ->send();

                                    redirect(request()->header('Referer'));
                                })
                                ,

                            Action::make('add_to_letters')
                                ->label('إضافة للجوابات')
                                ->icon('heroicon-o-document-plus')
                                ->color('success')
                                ->requiresConfirmation()
                                ->modalHeading('تأكيد خروج المكنة')
                                ->modalDescription('سيتم نقل هذه المكنة إلى الجوابات وحذفها من المخزون.')
                                ->action(function ($get) {
                                    $itemId = $get('id');

                                    if (! $itemId) {
                                        Notification::make()
                                            ->title('لازم تحفظ القطعة الأول')
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    $item = InventoryItem::find($itemId);

                                    if (! $item) {
                                        Notification::make()
                                            ->title('القطعة غير موجودة')
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    DB::transaction(function () use ($item) {
                                        Letter::create([
                                            'inventory_id' => $item->inventory_id,
                                            'inventory_item_id' => $item->id,

                                            'chassis_image' => $item->chassis_image,
                                            'engine_image' => $item->engine_image,
                                            'color' => $item->color,

                                            'customer_name' => $item->customer_name,
                                            'customer_id_image' => $item->customer_id_image,
                                            'national_id' => $item->national_id,
                                            'phone' => $item->phone,
                                            'address' => $item->address,

                                            'cash' => $item->cash,
                                            'limit' => $item->limit,
                                            'admin_fees' => $item->admin_fees,
                                            'paid' => $item->paid,
                                            'remaining' => $item->remaining,

                                            'chassis_number' => $item->chassis_number,
                                            'engine_number' => $item->engine_number,
                                        ]);

                                        $item->delete();
                                    });

                                    Notification::make()
                                        ->title('تم نقل المكنة إلى الجوابات')
                                        ->success()
                                        ->send();

                                    redirect(request()->header('Referer'));
                                }),
                        ])
                            ->columnSpanFull()
                            ->visibleOn('edit'),
                    ])
                    ->columns(2)
                    ->defaultItems(1)
                    ->addActionLabel('اضافة قطعة'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('المخزن'),

                TextColumn::make('product.name')
                    ->label('الصنف')
                    ->searchable(query: function ($query, $search) {
                        $query->whereHas('product', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('عدد القطع'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->label('فلترة بالمعرض')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\EditAction::make()
                    ->visible(fn () => FinanceAccess::canManage()),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => FinanceAccess::canManage()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventories::route('/'),
            'create' => Pages\CreateInventory::route('/create'),
            'view' => Pages\ViewInventory::route('/{record}'),
            'edit' => Pages\EditInventory::route('/{record}/edit'),
        ];
    }
}

