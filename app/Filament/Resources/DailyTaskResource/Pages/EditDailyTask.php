<?php

namespace App\Filament\Resources\DailyTaskResource\Pages;

use App\Filament\Resources\DailyTaskResource;
use App\Models\DailyTaskFile;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDailyTask extends EditRecord
{
    protected static string $resource = DailyTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $files = (array) ($data['new_attachments'] ?? []);
        $names = (array) ($data['new_attachment_names'] ?? []);
        unset($data['new_attachments'], $data['new_attachment_names']);

        $record->update($data);
        DailyTaskResource::saveFiles($record, $files, $names, DailyTaskFile::ATTACHMENT);

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
