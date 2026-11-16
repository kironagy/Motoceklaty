<?php

namespace App\Filament\Resources\AmanUploadResource\Pages;

use App\Filament\Resources\AmanUploadResource;
use App\Models\AmanUpload;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListAmanUploads extends ListRecords
{
    protected static string $resource = AmanUploadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sheet')
                ->label('تحميل الشيت')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function () {
                    $rows = $this->getFilteredTableQuery()->reorder('finished_at')->get();

                    return response()->streamDownload(function () use ($rows) {
                        $out = fopen('php://output', 'w');
                        fwrite($out, "\xEF\xBB\xBF");
                        fputcsv($out, ['الوقت', 'رقم الطلب في موتوجيت', 'العميل', 'الرقم القومي', 'النوع', 'النتيجة', 'رقم الطلب في أمان', 'الموظف', 'ملاحظات']);
                        foreach ($rows as $r) {
                            /** @var AmanUpload $r */
                            fputcsv($out, [
                                $r->finished_at?->format('Y-m-d H:i'), $r->installment_request_id, $r->request?->applicant_name,
                                $r->request?->applicant_national_id ? "\u{200E}".$r->request->applicant_national_id : '',
                                config('aman_extension.types')[$r->type] ?? $r->type,
                                AmanUploadResource::STATUS[$r->status] ?? $r->status, $r->aman_ref, $r->staff?->name, $r->message,
                            ]);
                        }
                        fclose($out);
                    }, 'aman-report-'.now()->format('Y-m-d-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
        ];
    }
}
