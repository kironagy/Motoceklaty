<?php

namespace App\Filament\Resources\EmployeeAdvanceRequestResource\Pages;

use App\Filament\Resources\EmployeeAdvanceRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeAdvanceRequest extends EditRecord
{
    protected static string $resource = EmployeeAdvanceRequestResource::class;

    public function getTitle(): string
    {
        return static::getResource()::getModelLabel().' #'.$this->record->id;
    }

    public function getBreadcrumb(): string
    {
        return 'عرض';
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()->label('حفظ التعديلات')->icon('heroicon-o-check');
    }

    protected function getCancelFormAction(): Actions\Action
    {
        return parent::getCancelFormAction()->label('رجوع');
    }

    protected function getHeaderActions(): array
    {
        return [
            ...EmployeeAdvanceRequestResource::decisionActions(Actions\Action::class),
            Actions\DeleteAction::make()->label('حذف')
                ->visible(fn () => EmployeeAdvanceRequestResource::fullAccess()
                    || ((int) $this->record->staff_id === (int) auth()->id() && $this->record->status === 'pending')),
        ];
    }

    /** Only the request's details are saved here; the status changes through approve / reject. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_unless(EmployeeAdvanceRequestResource::canChangeDetails($this->record), 403);

        return collect($data)->except(['staff_id', 'status', 'reviewed_by', 'reviewed_at', 'admin_notes'])->all();
    }

    protected function getFormActions(): array
    {
        return EmployeeAdvanceRequestResource::canChangeDetails($this->record) ? parent::getFormActions() : [];
    }
}
