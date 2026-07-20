<?php

namespace App\Filament\Resources\DeliveryResource\Pages;

use App\Filament\Resources\DeliveryResource;
use App\Models\InstallmentRequest;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDeliveries extends ListRecords
{
    protected static string $resource = DeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('new_installment_request')
                ->label('إضافة طلب جديد')
                ->icon('heroicon-o-plus')
                ->modalHeading('إضافة طلب جديد')
                ->modalDescription('اكتب الرقم القومي للعميل للتأكد أنه غير مسجل قبل ذلك.')
                ->modalSubmitActionLabel('متابعة')
                ->form([
                    \Filament\Forms\Components\TextInput::make('applicant_national_id')
                        ->label('الرقم القومي')
                        ->required()
                        ->helperText('لازم 14 رقم (أرقام إنجليزية فقط).')
                        ->rule('regex:/^\d{14}$/')
                        ->validationMessages([
                            'required' => 'الرقم القومي مطلوب.',
                            'regex'    => 'الرقم القومي يجب أن يكون 14 رقمًا بالضبط.',
                        ]),
                ])
                ->action(function (array $data) {
                    // Normalize: ارقام انجليزي + أرقام فقط + 14 رقم
                    $nid = $data['applicant_national_id'] ?? '';
                    $nid = str_replace(['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'], $nid);
                    $nid = preg_replace('/\D+/', '', $nid ?? '');
                    $nid = substr($nid, 0, 14);

                    $existing = InstallmentRequest::where('applicant_national_id', $nid)->first();

                    if ($existing) {
                        Notification::make()
                            ->title('الطلب موجود بالفعل')
                            ->body("لا يمكن إنشاء طلب جديد. هذا الرقم القومي مسجل سابقًا برقم طلب: #{$existing->id}")
                            ->danger()
                            ->send();

                        return; // يقفل المودال ومش يروح للإنشاء
                    }

                    // لو مش موجود: حوّله على صفحة الإنشاء ومعاه الرقم القومي
                    $this->redirect(DeliveryResource::getUrl('create', [
                        'nid' => $nid,
                    ]));
                }),
        ];
    }
}

