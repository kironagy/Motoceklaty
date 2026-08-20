<?php

namespace App\Filament\Resources\DeliveryResource\Pages;

use App\Filament\Resources\DeliveryResource;
use App\Models\Notification;
use App\Models\Staff;
use App\Services\PushNotificationService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditDelivery extends EditRecord
{
    protected static string $resource = DeliveryResource::class;

    public function getTitle(): string
    {
        return 'Edit Installment Request #' . $this->record->id;
    }

    private function normalizeSingleFile($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->normalizeSingleFile($decoded);
            }

            return $value;
        }

        if (is_array($value)) {
            if (isset($value['id'])) {
                return $this->normalizeSingleFile($value['id']);
            }

            if (isset($value['path'])) {
                return $this->normalizeSingleFile($value['path']);
            }

            if (isset($value['name'])) {
                return $this->normalizeSingleFile($value['name']);
            }

            return $this->normalizeSingleFile(reset($value));
        }

        return null;
    }

    public function mount($record): void
    {
        parent::mount($record);

        Notification::where('user_id', Auth::id())
            ->where('message', 'LIKE', '%' . $record . '%')
            ->update(['is_read' => true]);

        $this->dispatch('refresh-bell');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = Auth::user();
        $record = $this->record;

        $singleFileFields = [
            'price_offer_image',
            'applicant_id_image',
            'applicant_id_back_image',
            'medical_card_image',
            'selfie_image',
            'guarantor_id_image',
            'guarantor_id_back_image',
            'salary_slip_file',
            'pension_statement_file',
            'commercial_reg_file',
            'tax_card_file',
        ];

        foreach ($singleFileFields as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->normalizeSingleFile($data[$field]);
            }
        }

        // لو أدمن (مش سوبر أدمن) غيّر الموظف، يبعت طلب تحويل للمسؤولين فقط.
        if (
            $user->is_admin &&
            ! $user->is_super_admin &&
            $data['staff_id'] != $record->staff_id
        ) {
            $record->update([
                'pending_staff_id' => $data['staff_id'],
                'transfer_requested_by' => $user->id,
                'transfer_requested_at' => now(),
            ]);

            $newStaff = Staff::find($data['staff_id']);
            $superAdmins = Staff::where('is_super_admin', 1)->get();
            $push = app(PushNotificationService::class);
            $notificationUrl = DeliveryResource::getUrl('edit', [
                'record' => $record,
            ]);

            foreach ($superAdmins as $admin) {
                $title = 'طلب تحويل جديد';
                $message = "{$user->name} عايز يحول الطلب رقم {$record->id} من {$record->staff->name} إلى {$newStaff->name}";

                Notification::create([
                    'user_id' => $admin->id,
                    'title' => $title,
                    'message' => $message,
                    'type' => 'transfer_request',
                    'data' => json_encode([
                        'request_id' => $record->id,
                    ]),
                    'is_read' => false,
                ]);

                $push->sendToUser(
                    userId: $admin->id,
                    title: $title,
                    body: $message,
                    url: $notificationUrl,
                    tag: 'transfer-request-' . $record->id,
                );
            }

            // يرجع الموظف القديم لكي لا يتم التحويل إلا بعد الموافقة.
            $data['staff_id'] = $record->staff_id;

            \Filament\Notifications\Notification::make()
                ->title('تم إرسال طلب التحويل للتيم ليدر')
                ->info()
                ->send();
        }

        return $data;
    }

    protected function beforeSave(): void
    {
        $old = $this->record->getOriginal();
        $new = $this->data;
        $changes = [];

        $clean = function ($value) {
            if (is_null($value)) {
                return null;
            }

            if (is_array($value)) {
                if (isset($value['id'])) {
                    return trim($value['id']);
                }

                if (isset($value['name'])) {
                    return trim($value['name']);
                }

                if (isset($value['path'])) {
                    return trim($value['path']);
                }

                ksort($value);

                return trim(json_encode($value));
            }

            if (is_string($value)) {
                return trim(preg_replace('/\s+/u', '', $value));
            }

            if (is_numeric($value)) {
                return (string) $value;
            }

            return $value;
        };

        $normalizeFile = function ($value) {
            if (! $value) {
                return null;
            }

            if (is_array($value)) {
                if (isset($value['name'])) {
                    return 'installments/price_offers/' . $value['name'];
                }

                if (isset($value['id'])) {
                    return $value['id'];
                }
            }

            return $value;
        };

        $oldPrice = $normalizeFile($old['price_offer_image'] ?? null);
        $newPrice = $normalizeFile($new['price_offer_image'] ?? null);

        $this->data['price_offer_image'] = $newPrice;

        $priceChanged = false;

        if ($clean($oldPrice) === $clean($newPrice)) {
            $priceChanged = false;
        } elseif (! $newPrice && $oldPrice) {
            $priceChanged = true;
        } elseif (
            isset($new['price_offer_image']) &&
            is_array($new['price_offer_image']) &&
            isset($new['price_offer_image']['name']) &&
            $new['price_offer_image']['name'] !== ''
        ) {
            $priceChanged = true;
        }

        if ($priceChanged) {
            $changes[] = 'عرض السعر';
        }

        $fieldNames = [
            'installment_type' => 'نوع النظام',
            'months' => 'عدد الشهور',
            'applicant_name' => 'اسم العميل',
            'applicant_phone' => 'رقم العميل',
            'applicant_address' => 'العنوان',
            'applicant_national_id' => 'رقم بطاقة العميل',
            'deposit' => 'المقدم',
            'guarantor_name' => 'اسم الضامن',
            'guarantor_phone' => 'رقم هاتف الضامن',
            'work_status' => 'الحالة الوظيفية',
            'work_address' => 'عنوان العمل',
            'salary_amount' => 'المرتب',
            'pension_amount' => 'المعاش',
            'machine_id' => 'المكنة',
            'edit_reason' => 'سبب التعديل',
        ];

        foreach ($fieldNames as $field => $label) {
            $oldValue = $clean($old[$field] ?? null);
            $newValue = $clean($new[$field] ?? ($this->record->{$field} ?? null));

            if ($oldValue !== $newValue) {
                $changes[] = $label;
            }
        }

        $newStatus = $new['status'] ?? $this->record->status;
        $newReason = $new['checks_report'] ?? $this->record->checks_report;

        $statusChanged = $clean($old['status'] ?? null) !== $clean($newStatus);
        $reasonChanged = $clean($old['checks_report'] ?? null) !== $clean($newReason);

        if ($statusChanged) {
            $changes[] = 'حالة الطلب';
            $this->record->status = $newStatus;
        }

        if ($reasonChanged) {
            $changes[] = 'سبب الطلب';
            $this->record->checks_report = $newReason;
        }

        $changes = array_filter(array_unique($changes));

        if (empty($changes)) {
            return;
        }

        $this->record->status_updated_at = now();
        $this->record->status_updated_by = Auth::id();

        // حافظ على صور الضامن لو لم يتم رفع صور جديدة.
        foreach ([
            'guarantor_id_image',
            'guarantor_id_back_image',
        ] as $fileField) {
            if (! array_key_exists($fileField, $this->data) || blank($this->data[$fileField])) {
                $this->data[$fileField] = $this->record->{$fileField};
            } else {
                $this->data[$fileField] = $this->normalizeSingleFile($this->data[$fileField]);
            }
        }

        $changesList = implode(' + ', $changes);
        $authId = Auth::id();
        $authName = Auth::user()->name;
        $push = app(PushNotificationService::class);
        $notificationUrl = DeliveryResource::getUrl('edit', [
            'record' => $this->record,
        ]);

        $admins = Staff::where('is_admin', true)
            ->where('id', '!=', $authId)
            ->pluck('id');

        // الموظف المسؤول عن الطلب.
        if ($this->record->staff_id && $this->record->staff_id != $authId) {
            $title = "تعديل على الطلب رقم {$this->record->id}";
            $message = "تم تعديل: {$changesList} في الطلب رقم {$this->record->id}.";

            Notification::create([
                'user_id' => $this->record->staff_id,
                'title' => $title,
                'message' => $message,
                'is_read' => false,
            ]);

            $push->sendToUser(
                userId: $this->record->staff_id,
                title: $title,
                body: $message,
                url: $notificationUrl,
                tag: 'delivery-' . $this->record->id,
            );
        }

        // الأدمن.
        foreach ($admins as $adminId) {
            $title = "تعديل على الطلب رقم {$this->record->id}";
            $message = "تم تعديل: {$changesList} في الطلب رقم {$this->record->id} من قبل {$authName}.";

            Notification::create([
                'user_id' => $adminId,
                'title' => $title,
                'message' => $message,
                'is_read' => false,
            ]);

            $push->sendToUser(
                userId: $adminId,
                title: $title,
                body: $message,
                url: $notificationUrl,
                tag: 'delivery-' . $this->record->id,
            );
        }

        // الشخص الذي قام بالتعديل.
        $selfTitle = "قمت بتعديل الطلب رقم {$this->record->id}";
        $selfMessage = "لقد قمت بتعديل: {$changesList} في الطلب رقم {$this->record->id}.";

        Notification::create([
            'user_id' => $authId,
            'title' => $selfTitle,
            'message' => $selfMessage,
            'is_read' => false,
        ]);

        $push->sendToUser(
            userId: $authId,
            title: $selfTitle,
            body: $selfMessage,
            url: $notificationUrl,
            tag: 'delivery-' . $this->record->id,
        );
    }
}
