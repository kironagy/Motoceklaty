<?php

namespace App\Filament\Resources\DeliveryResource\Pages;

use App\Filament\Resources\DeliveryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDelivery extends CreateRecord
{
    protected static string $resource = DeliveryResource::class;

    public function mount(): void
    {
        parent::mount();

        $requestType = request()->query('request_type') === 'fake' && ! DeliveryResource::isCompanyEmployee()
            ? 'fake'
            : 'normal';

        // تحديد نوع الطلب مع الحفاظ على باقي قيم الفورم
        $this->data['request_type'] = $requestType;

        // تسجيل الموظف الذي قام بإنشاء الطلب تلقائيًا
        $this->data['staff_id'] = auth()->id();
    }

    protected function getRedirectUrl(): string
    {
        return DeliveryResource::getUrl('index', [
            'activeTab' => $this->record->request_type === 'fake'
                ? 'fake'
                : 'normal',
        ]);
    }
    protected function mutateFormDataBeforeCreate(array $data): array
{
    // Only the bot files bot requests, and an Aman employee never files a fake one.
    $data['request_type'] = ($data['request_type'] ?? null) === 'fake' && ! DeliveryResource::isCompanyEmployee()
        ? 'fake'
        : 'normal';

    if ($botRequest = DeliveryResource::botRequestFor($data['applicant_national_id'] ?? null)) {
        \Filament\Notifications\Notification::make()
            ->title('العميل مقدم قبل كده في طلبات البوت')
            ->body(DeliveryResource::botRequestMessage($botRequest))
            ->danger()
            ->persistent()
            ->send();

        $this->halt();
    }

    $fullAddress = DeliveryResource::buildFullAddressFromData($data);
    if ($fullAddress !== '') {
        $data['applicant_address'] = $fullAddress;
    }

    $workAddress = DeliveryResource::buildWorkAddressFromData($data);
    if ($workAddress !== '') {
        $data['work_address'] = $workAddress;
    }

    return $data;
}
}
