<?php

namespace App\Filament\Resources\DynamicFormResource\Pages;

use App\Domain\Forms\FormPublisher;
use App\Filament\Resources\DynamicFormResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDynamicForm extends CreateRecord
{
    protected static string $resource = DynamicFormResource::class;

    /** Only the name here - the form itself is built in the builder right after. */
    public function form(\Filament\Forms\Form $form): \Filament\Forms\Form
    {
        return $form->schema([
            \Filament\Forms\Components\TextInput::make('name')->label('اسم الفورم')->required()->maxLength(120)
                ->placeholder('مثلاً: طلب تقسيط موتوسيكل'),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['is_active' => true, 'schema' => ['settings' => [], 'sections' => [
            ['key' => 's_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)), 'title' => 'بياناتك', 'items' => []],
        ]]];
    }

    protected function afterCreate(): void
    {
        app(FormPublisher::class)->keepOneBotDefault($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
