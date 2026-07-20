<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Models\Brand;

class ListBrands extends ListRecords
{
    protected static string $resource = BrandResource::class;

protected function getHeaderActions(): array
{
    return [
        // زرار إنشاء شركة جديدة
        Actions\CreateAction::make(),

        // زرار التصدير
        Actions\Action::make('export_brands')
            ->label('تصدير الشركات')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('warning')
            ->action(function () {
                $brands = Brand::all(['name', 'image']);
        
                $filename = 'brands_export_' . now()->format('Y-m-d_H-i-s') . '.csv';
                $filepath = storage_path("app/$filename");
                $handle = fopen($filepath, 'w');
        
                // إضافة BOM للغة العربية
                fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
                // الهيدر
                fputcsv($handle, ['Name', 'Image URL']);
        
                foreach ($brands as $brand) {
                    fputcsv($handle, [
                        $brand->name,
                        asset('storage/' . $brand->image),
                    ]);
                }
        
                fclose($handle);
        
                return response()->download($filepath)->deleteFileAfterSend();
            }),
    ];
}

}

