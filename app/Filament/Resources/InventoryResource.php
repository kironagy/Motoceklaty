<?php

namespace App\Filament\Resources;

use App\Domain\Inventory\InventoryStock;
use App\Filament\Resources\InventoryResource\Pages;
use App\Models\{InventoryItem, Machine, Warehouse};
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Components\{FileUpload, Select, TextInput};
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\{Builder, Model};

class InventoryResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use \App\Filament\Concerns\HasFullAccess;

    protected static ?string $model = Machine::class;
    protected static ?string $slug = 'inventories';
    protected static ?string $navigationLabel = 'المخزون';
    protected static ?string $modelLabel = 'مخزون موديل';
    protected static ?string $pluralModelLabel = 'المخزون';
    protected static ?string $navigationGroup = 'إدارة المعرض';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    public static function canViewAny(): bool { return Access::allows(static::class, 'view'); }
    public static function canEdit(Model $record): bool { return Access::allows(static::class, 'update'); }
    public static function canCreate(): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return app(InventoryStock::class)->modelQuery()->with('brand');
    }

    public static function table(Table $table): Table
    {
        return $table->heading('الموديلات المتاحة في المخزون')
            ->description('كل الموديلات من ريسورس المكن تلقائيًا، والعدد هو رصيد المعارض المسموح لك بالوصول إليها فقط.')
            ->columns([
                TextColumn::make('name')->label('اسم الموديل')->searchable()->sortable()->weight('bold')->icon('heroicon-o-cube'),
                TextColumn::make('brand.name')->label('الماركة')->searchable()->sortable()->placeholder('غير مسجلة'),
                TextColumn::make('available_items_count')->label('عدد القطع')->numeric()->sortable()->badge()
                    ->color(fn ($state) => (int) $state > 0 ? 'success' : 'gray')->suffix(' قطعة'),
            ])->defaultSort('name')->actions([
                Tables\Actions\Action::make('editStock')->label('تعديل')->icon('heroicon-o-pencil-square')->color('gray')->button()
                    ->url(fn (Machine $record) => static::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Machine $record) => static::canEdit($record)),
                static::saleAction(),
            ])->bulkActions([])->emptyStateHeading('أضف الموديلات من ريسورس المكن');
    }

    public static function saleAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('sell')->label('بيع')->icon('heroicon-o-shopping-bag')->color('success')->button()
            ->visible(fn (Machine $record) => static::canEdit($record) && LetterResource::canCreate())
            ->disabled(fn (Machine $record) => (int) $record->available_items_count === 0)
            ->modalHeading(fn (Machine $record) => 'بيع • ' . $record->name)
            ->modalDescription('اختر المعرض والقطعة، ثم أدخل بيانات العميل والحساب. إرسال الجواب يتم عند التأكيد فقط.')
            ->modalWidth('4xl')->modalSubmitActionLabel('تأكيد البيع وإرسال للجوابات')
            ->steps([
                Forms\Components\Wizard\Step::make('اختيار القطعة')->icon('heroicon-o-cube')->schema([
                    Select::make('warehouse_id')->label('المعرض')->required()->searchable()->preload()->live()
                        ->options(fn (Machine $record) => app(InventoryStock::class)->warehouses()->whereHas('inventories', fn ($q) => $q
                            ->whereHas('product', fn ($p) => $p->where('machine_id', $record->id))
                            ->whereHas('items', fn ($i) => $i->whereNull('sold_at')))->pluck('name', 'id'))
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('inventory_item_id', null)),
                    Select::make('inventory_item_id')->label('القطعة • الشاسيه / الماتور / اللون')->required()->searchable()->preload()->live()
                        ->options(fn (Machine $record, Forms\Get $get) => app(InventoryStock::class)->available($record->id)
                            ->whereHas('inventory', fn ($q) => $q->where('warehouse_id', $get('warehouse_id')))->get()
                            ->mapWithKeys(fn ($item) => [$item->id => 'شاسيه: ' . ($item->chassis_number ?: 'غير مسجل') . ' • ماتور: ' . ($item->engine_number ?: 'غير مسجل') . ' • ' . $item->color . ' • #' . $item->id]))
                        ->afterStateUpdated(function ($state, Machine $record, Forms\Set $set) {
                            $item = app(InventoryStock::class)->available($record->id)->find($state);
                            foreach (InventoryStock::SALE_FIELDS as $field) $set($field, $item?->{$field});
                        }),
                    TextInput::make('chassis_number')->label('رقم الشاسيه')->required()->maxLength(255),
                    TextInput::make('engine_number')->label('رقم الماتور')->required()->maxLength(255),
                ])->columns(2),
                Forms\Components\Wizard\Step::make('بيانات العميل')->icon('heroicon-o-user')->schema([
                    TextInput::make('customer_name')->label('اسم العميل')->required()->maxLength(255),
                    TextInput::make('phone')->label('رقم الهاتف')->tel()->required()->maxLength(30),
                    TextInput::make('national_id')->label('الرقم القومي')->nullable()->regex('/^[0-9]{14}$/')->maxLength(14),
                    TextInput::make('address')->label('العنوان')->maxLength(2000),
                    FileUpload::make('customer_id_image')->label('صورة البطاقة')->image()->disk('public')->visibility('public')->directory('customer-id')->columnSpanFull(),
                ])->columns(2),
                Forms\Components\Wizard\Step::make('الحساب والتأكيد')->icon('heroicon-o-banknotes')->schema([
                    ...collect(['cash' => 'كاش', 'limit' => 'ليمت', 'admin_fees' => 'المصاريف الإدارية', 'paid' => 'المدفوع', 'remaining' => 'المتبقي'])
                        ->map(fn ($label, $key) => TextInput::make($key)->label($label)->numeric()->minValue(0)->maxValue(99999999)->suffix('جنيه'))->values()->all(),
                    Forms\Components\Placeholder::make('sale_note')->hiddenLabel()->content('بالتأكيد: تتحول القطعة إلى مباعة ويُنشأ جواب بنفس بياناتها وبيانات العميل، بدون حذف سجل القطعة.')->columnSpanFull(),
                ])->columns(2),
            ])->action(function (Machine $record, array $data) {
                abort_unless(static::canEdit($record) && LetterResource::canCreate(), 403);
                $letter = app(InventoryStock::class)->sell($record, $data);
                Notification::make()->success()->title('تم البيع وإنشاء الجواب بنجاح')->send();
                if (LetterResource::canEdit($letter)) return redirect(LetterResource::getUrl('edit', ['record' => $letter]));
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInventories::route('/'), 'edit' => Pages\EditInventory::route('/{record}/edit')];
    }
}

