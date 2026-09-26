<?php

namespace App\Filament\Resources\BotLessonResource\Pages;

use App\Filament\Resources\BotLessonResource;
use Filament\Resources\Pages\EditRecord;

class EditBotLesson extends EditRecord
{
    protected static string $resource = BotLessonResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $data + ['revision' => $this->record->revision + 1];
    }
}
