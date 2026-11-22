<?php

namespace App\Filament\Resources\MerchantResource\Pages;

use App\Filament\Resources\MerchantResource;
use App\Support\Merchants\MerchantTenancy;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMerchant extends EditRecord
{
    protected static string $resource = MerchantResource::class;

    protected ?string $previousEmail = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('open')->label('افتح لوحته')->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn () => $this->record->url(), true),
            Actions\DeleteAction::make()
                ->modalDescription('الدومين بتاعه هيقفل. بيانات معرضه بتفضل محفوظة على السيرفر.'),
        ];
    }

    protected function beforeSave(): void
    {
        $this->previousEmail = $this->record->getOriginal('email');
    }

    /** Keeps the showroom's login (name, email, password, pages) in step with this form. */
    protected function afterSave(): void
    {
        MerchantTenancy::provision($this->record, $this->data['password'] ?? null, $this->previousEmail);
    }
}
