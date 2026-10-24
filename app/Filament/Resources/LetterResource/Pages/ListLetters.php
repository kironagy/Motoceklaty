<?php

namespace App\Filament\Resources\LetterResource\Pages;

use App\Filament\Resources\LetterResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLetters extends ListRecords
{
    protected static string $resource = LetterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('remaining_report')->label('استخراج تقرير بالمتبقي')
                ->icon('heroicon-o-arrow-down-tray')->color('success')
                ->visible(fn () => \App\Support\Access::full(LetterResource::class))
                ->action(function () {
                    abort_unless(LetterResource::canViewAny() && \App\Support\Access::full(LetterResource::class), 403);
                    return response()->streamDownload(function () {
                        $file = fopen('php://output', 'w');
                        fwrite($file, "\xEF\xBB\xBF");
                        fputcsv($file, ['رقم الجواب', 'اسم العميل', 'نوع المكنة', 'المعرض', 'المبلغ المتبقي']);
                        \App\Models\Letter::query()->where('remaining', '>', 0)
                            ->with(['inventory.product.machine', 'inventory.warehouse', 'warehouse'])
                            ->orderBy('id')->chunkById(200, function ($letters) use ($file) {
                                foreach ($letters as $letter) {
                                    $safe = fn ($value) => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'" . $value : $value;
                                    fputcsv($file, [$letter->id, $safe($letter->customer_name),
                                        $safe($letter->inventory?->product?->machine?->name ?? $letter->inventory?->product?->name ?? 'غير مسجل'),
                                        $safe($letter->warehouse?->name ?? $letter->inventory?->warehouse?->name ?? 'غير مسجل'), $letter->remaining]);
                                }
                            });
                        fclose($file);
                    }, 'letters-remaining-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
            Actions\CreateAction::make(),
        ];
    }
}
