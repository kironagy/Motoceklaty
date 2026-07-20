<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Answer;
use App\Models\Order;
use App\Models\StockGroup;
use App\Models\StockItem;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        // ناخد State كامل من الفورم (مضمون)
        $state = $this->form->getState();

        return DB::transaction(function () use ($data, $state) {

            // أنشئ الطلبية
            /** @var Order $order */
            $order = Order::create([
                'name' => $data['name'],
                'trader_id' => $data['trader_id'],
                'stock_group_id' => $data['stock_group_id'],
                'type' => $data['type'],
            ]);

            $group = StockGroup::with('machine')->find($order->stock_group_id);

            if (!$group) {
                throw new \Exception('المجموعة غير موجودة');
            }

            // ✅ استيراد: أضف وحدات للمخزن
            if ($order->type === 'import') {

                $importItems = $state['import_items'] ?? [];
                if (!is_array($importItems) || count($importItems) === 0) {
                    throw new \Exception('لا يوجد مكن مضاف للاستيراد');
                }

                foreach ($importItems as $item) {
                    StockItem::create([
                        'stock_group_id' => $group->id,
                        'color' => $item['color'] ?? null,
                        'chassis_image' => $item['chassis_image'] ?? null,
                        'engine_image' => $item['engine_image'] ?? null,
                    ]);
                }

                // زوّد quantity + حدّث المتاح
                $group->updateQuietly([
                    'quantity' => $group->quantity + count($importItems),
                ]);
                $group->refreshAvailableQuantity();
            }

            // ✅ تصدير: احذف وحدات من المخزن
            if ($order->type === 'export') {

                $exportIds = $state['export_item_ids'] ?? [];

                if (!is_array($exportIds) || count($exportIds) === 0) {
                    throw new \Exception('اختار المكن اللي هيطلع');
                }

                $deleted = StockItem::query()
                    ->where('stock_group_id', $group->id)
                    ->whereIn('id', $exportIds)
                    ->delete();

                // قلل quantity بنفس العدد اللي اتحذف
                $group->updateQuietly([
                    'quantity' => max(0, $group->quantity - (int) $deleted),
                ]);
                $group->refreshAvailableQuantity();
            }

            Notification::make()
                ->title('تم إنشاء الطلبية وتحديث المخزن بنجاح ✅')
                ->success()
                ->send();

            return $order;
        });
    }

    // مهم: ما تحفظش الحقول الإضافية في جدول orders
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['import_items'], $data['export_item_ids']);
        return $data;
    }
}

