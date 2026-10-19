<?php

namespace App\Filament\Resources\EmployeeAdvanceRequestResource\Pages;

use App\Filament\Resources\EmployeeAdvanceRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeAdvanceRequest extends CreateRecord
{
    protected static string $resource = EmployeeAdvanceRequestResource::class;

    /** Always the creator's own request, always waiting for review - whatever the form sent. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['reviewed_by'], $data['reviewed_at'], $data['admin_notes']);

        return [...$data, 'staff_id' => auth()->id(), 'status' => 'pending'];
    }

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'طلب سلفة جديد';
    }

    public function getBreadcrumb(): string
    {
        return 'طلب جديد';
    }

    protected function getCreateFormAction(): Actions\Action
    {
        return parent::getCreateFormAction()->label('ابعت الطلب')->icon('heroicon-o-paper-airplane');
    }

    protected function getCancelFormAction(): Actions\Action
    {
        return parent::getCancelFormAction()->label('إلغاء');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
