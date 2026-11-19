<?php

namespace App\Filament\Resources\LetterResource\Pages;

use App\Filament\Resources\LetterResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLetter extends EditRecord
{
    protected static string $resource = LetterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    protected function getFormActions(): array
{
    return [
        ...parent::getFormActions(),

        \Filament\Actions\Action::make('print_sale')
            ->label('طباعة المبايعة')
            ->icon('heroicon-o-printer')
            ->color('warning')
            ->url(fn () => route('letters.print-sale', $this->record))
            ->openUrlInNewTab(),

        // Owner 2026-11-19: download the chassis / engine photo.
        $this->downloadImage('download_chassis', 'تحميل الشاسيه', 'chassis_image', 'شاسيه'),
        $this->downloadImage('download_engine', 'تحميل الماتور', 'engine_image', 'ماتور'),
    ];
}

    private function downloadImage(string $name, string $label, string $field, string $prefix): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn () => filled($this->record->{$field}))
            ->action(function () use ($field, $prefix) {
                $path = $this->record->{$field};
                $disk = \Illuminate\Support\Facades\Storage::disk(config('filament.default_filesystem_disk'));

                if (blank($path) || ! $disk->exists($path)) {
                    \Filament\Notifications\Notification::make()->title('الصورة مش موجودة')->danger()->send();

                    return null;
                }

                $name = $prefix.'-'.($this->record->chassis_number ?: $this->record->getKey()).'.'.pathinfo($path, PATHINFO_EXTENSION);

                return $disk->download($path, $name);
            });
    }
}
