<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Domain\Inventory\InventoryStock;
use App\Filament\Resources\InventoryResource;
use App\Models\{Machine, Warehouse};
use Filament\Forms;
use Filament\Forms\Components\{FileUpload, Hidden, Repeater, Section, TextInput};
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class EditInventory extends Page implements HasForms
{
    use InteractsWithForms;
    use \Filament\Resources\Pages\Concerns\InteractsWithRecord;

    protected static string $resource = InventoryResource::class;
    protected static string $view = 'filament.resources.inventory.manage';

    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(InventoryResource::canEdit($this->record), 403);
        $this->form->fill(app(InventoryStock::class)->formState($this->record));
    }

    public function getTitle(): string { return 'مخزون • ' . $this->record->name; }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema(
            app(InventoryStock::class)->warehouses()->orderBy('name')->get()->map(fn ($warehouse) =>
                Section::make(fn (Forms\Get $get) => $warehouse->name . ' • ' . count($get('warehouses.' . $warehouse->id . '.items') ?? []) . ' قطعة')
                    ->icon('heroicon-o-building-storefront')->description('افتح المعرض لعرض الشاسيه والماتور واللون، أو إضافة قطعة جديدة.')
                    ->collapsible()->collapsed()
                    ->schema([
                        Repeater::make('warehouses.' . $warehouse->id . '.items')->hiddenLabel()
                            ->schema([
                                Hidden::make('id'), Hidden::make('version'),
                                TextInput::make('chassis_number')->label('رقم الشاسيه')->maxLength(255),
                                TextInput::make('engine_number')->label('رقم الماتور')->maxLength(255),
                                TextInput::make('color')->label('اللون')->required()->maxLength(255)->columnSpanFull(),
                                FileUpload::make('chassis_image')->label('صورة الشاسيه')->image()->disk('public')->visibility('public')->directory('chassis'),
                                FileUpload::make('engine_image')->label('صورة الماتور')->image()->disk('public')->visibility('public')->directory('engine'),
                            ])->columns(2)->defaultItems(0)->addActionLabel('إضافة قطعة لهذا المعرض')
                            ->deletable(false)->reorderable(false)->collapsible()
                            ->itemLabel(fn (array $state) => (($state['chassis_number'] ?? null) ?: 'قطعة جديدة / بدون رقم شاسيه') . ' • ' . ($state['color'] ?? 'اختر اللون')),
                    ])
            )->all()
        );
    }

    public function save(): void
    {
        abort_unless(InventoryResource::canEdit($this->record), 403);
        app(InventoryStock::class)->save($this->record, $this->form->getState());
        $this->form->fill(app(InventoryStock::class)->formState($this->record));
        Notification::make()->success()->title('تم حفظ قطع المخزون')->body('الأعداد اتحدثت لكل معرض.')->send();
    }

    public function getAvailableCount(): int
    {
        return app(InventoryStock::class)->available($this->record->id)->count();
    }
}
