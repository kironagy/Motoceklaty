<?php

namespace App\Filament\Resources\BusinessMemoryResource\Pages;

use App\Filament\Resources\BusinessMemoryResource;
use App\Models\BusinessMemory;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateBusinessMemory extends CreateRecord
{
    protected static string $resource = BusinessMemoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['key'] ?? null)) {
            $data['key'] = BusinessMemoryResource::suggestKey((string) $data['title']);
        }

        $data['updated_by'] = auth()->id();

        return $data;
    }

    /**
     * DEC-23: no cap enforced until the owner sets
     * agent.context.pinned_memory_tokens.
     */
    protected function beforeCreate(): void
    {
        $data = $this->form->getState();

        if (! ($data['is_pinned'] ?? false)) {
            return;
        }

        $cap = config('agent.context.pinned_memory_tokens');

        if ($cap === null) {
            return;
        }

        $existing = BusinessMemory::where('is_pinned', true)->where('is_active', true)->get()
            ->sum(fn (BusinessMemory $m) => $m->estimatedTokens());

        $thisEstimate = (int) ceil(mb_strlen((string) ($data['content'] ?? '')) / 4);

        if ($existing + $thisEstimate > (int) $cap) {
            Notification::make()
                ->title('تخطيت حد توكنز المعرفة المثبتة المسموح به')
                ->body("الحالي: {$existing} + الجديد: {$thisEstimate} > الحد: {$cap}")
                ->danger()
                ->send();

            throw new Halt();
        }
    }
}
