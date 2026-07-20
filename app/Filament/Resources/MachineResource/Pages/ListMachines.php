<?php

namespace App\Filament\Resources\MachineResource\Pages;

use App\Filament\Resources\MachineResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Models\Machine;

class ListMachines extends ListRecords
{
    protected static string $resource = MachineResource::class;

 protected function getHeaderActions(): array
{
    return [
        Actions\CreateAction::make(),

     Actions\Action::make('export_machines')
    ->label('تصدير المكن بالكامل')
    ->icon('heroicon-o-arrow-down-tray')
    ->color('warning')
    ->action(function () {

        $machines = Machine::with(['brand'])->get();

        $filename = 'machines_export_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $filepath = storage_path("app/$filename");
        $handle = fopen($filepath, 'w');

        // BOM UTF-8
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Header
        fputcsv($handle, [
            'name',
            'brand',
            'type',
            'cash_price',
            'installment_price',
            'old_price',
            'new_price',
            'display_image',
            'features',
            'colors',
            'color_display',
            'images',
        ]);

        foreach ($machines as $m) {

            // 🟦 المميزات
            $features = collect($m->features ?? [])
                ->pluck('title')
                ->implode(' | ');

            // 🟧 ألوان HEX
            $colors = collect($m->colors ?? [])
                ->pluck('color')
                ->implode(' | ');

            // 🟥 كل صور color_display لكل الألوان
            $colorDisplays = collect($m->colors ?? [])
                ->pluck('color_display')     // قد تكون قيمة واحدة أو Array
                ->flatten()                  // مهم جدًا
                ->filter()
                ->map(fn($img) => $img ? asset('storage/'.$img) : '')
                ->implode(' | ');

            // 🟩 صور الجاليري
            $gallery = collect($m->colors ?? [])
                ->pluck('images')            // دايمًا array
                ->flatten()
                ->filter()
                ->map(fn($img) => asset('storage/'.$img))
                ->implode(' | ');

            // 🟪 صورة العرض الأساسية
            $displayImage = $m->display_image
                ? asset('storage/'.$m->display_image)
                : '';

            fputcsv($handle, [
                $m->name,
                $m->brand?->name,
                $m->type,
                $m->cash_price,
                $m->installment_price,
                $m->old_price,
                $m->new_price,
                $displayImage,
                $features,
                $colors,
                $colorDisplays,
                $gallery,
            ]);
        }

        fclose($handle);

        return response()->download($filepath)->deleteFileAfterSend();
    }),

    ];
}

}
