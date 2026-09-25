<?php

namespace App\Filament\Resources\DeliveryResource\Pages;

use App\Filament\Resources\DeliveryResource;
use App\Models\InstallmentRequest;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListDeliveries extends ListRecords
{
    protected static string $resource = DeliveryResource::class;

    /**
     * ============================================================
     * TABS
     * ============================================================
     */
   public function getTabs(): array
{
    $tabs = [

        /*
        |--------------------------------------------------------------------------
        | الطلبات الأساسية
        |--------------------------------------------------------------------------
        */
        'normal' => Tab::make('الطلبات الأساسية')
            ->icon('heroicon-o-clipboard-document-list')
            ->badge(
                fn (): int => $this->getRequestTypeCount('normal')
            )
            ->modifyQueryUsing(
                fn (Builder $query): Builder =>
                    $query->where('request_type', 'normal')
            ),

        /*
        |--------------------------------------------------------------------------
        | طلبات فيك
        |--------------------------------------------------------------------------
        */
        'fake' => Tab::make('طلبات فيك')
            ->icon('heroicon-o-document-duplicate')
            ->badge(
                fn (): int => $this->getRequestTypeCount('fake')
            )
            ->modifyQueryUsing(
                fn (Builder $query): Builder =>
                    $query->where('request_type', 'fake')
            ),

        /*
        |--------------------------------------------------------------------------
        | طلبات البوت - اللي اتقدمت من بوت الواتساب
        |--------------------------------------------------------------------------
        */
        'bot' => Tab::make('طلبات بوت')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->badge(
                fn (): int => $this->getRequestTypeCount('bot')
            )
            ->modifyQueryUsing(
                fn (Builder $query): Builder =>
                    $query->where('request_type', 'bot')
            ),
    ];

    /*
    |--------------------------------------------------------------------------
    | الطلبات المحذوفة - هتلر فقط
    |--------------------------------------------------------------------------
    */
    if ($this->isHitler()) {

        $tabs['deleted'] = Tab::make('الطلبات المحذوفة')
            ->icon('heroicon-o-trash')
            ->badge(
                fn (): int => $this->getDeletedRequestsCount()
            )
            ->modifyQueryUsing(
                fn (Builder $query): Builder =>
                    $query
                        ->withTrashed()
                        ->whereNotNull('installment_requests.deleted_at')
            );
    }

    return $tabs;
}



protected function isHitler(): bool
{
    return (bool) (
        auth()->user()?->is_hitler
    );
}




protected function getDeletedRequestsCount(): int
{
    return DeliveryResource::getEloquentQuery()
        ->withTrashed()
        ->whereNotNull('installment_requests.deleted_at')
        ->count();
}



    /**
     * ============================================================
     * DEFAULT TAB
     * ============================================================
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'normal';
    }

    /**
     * ============================================================
     * COUNT
     * ============================================================
     */
    protected function getRequestTypeCount(string $type): int
    {
        return DeliveryResource::getEloquentQuery()
            ->where('request_type', $type)
            ->count();
    }

    /**
     * ============================================================
     * HEADER ACTIONS
     * ============================================================
     */
    protected function getHeaderActions(): array
    {
        return [

            Actions\Action::make('new_installment_request')

                // طلبات البوت بتتعمل من البوت نفسه بس
                ->hidden(fn (): bool => $this->activeTab === 'bot')

                /*
                |--------------------------------------------------------------------------
                | Button Label
                |--------------------------------------------------------------------------
                */
                ->label(
                    fn (): string =>
                        $this->isFakeTab()
                            ? 'إضافة طلب فيك'
                            : 'إضافة طلب جديد'
                )

                ->icon('heroicon-o-plus')

                /*
                |--------------------------------------------------------------------------
                | Modal Heading
                |--------------------------------------------------------------------------
                */
                ->modalHeading(
                    fn (): string =>
                        $this->isFakeTab()
                            ? 'إضافة طلب فيك'
                            : 'إضافة طلب جديد'
                )

                /*
                |--------------------------------------------------------------------------
                | Modal Description
                |--------------------------------------------------------------------------
                */
                ->modalDescription(
                    fn (): string =>
                        $this->isFakeTab()
                            ? 'اكتب الرقم القومي للعميل لإنشاء طلب فيك.'
                            : 'اكتب الرقم القومي للعميل للتأكد أنه غير مسجل قبل ذلك.'
                )

                ->modalSubmitActionLabel('متابعة')

                /*
                |--------------------------------------------------------------------------
                | FORM
                |--------------------------------------------------------------------------
                */
                ->form([

                    TextInput::make('applicant_national_id')
                        ->label('الرقم القومي')
                        ->required()
                        ->minLength(14)
                        ->maxLength(14)
                        ->placeholder('مثال: 29001011234567')
                        ->helperText('لازم 14 رقم.')

                        ->rule('regex:/^\d{14}$/')

                        ->validationMessages([
                            'required' => 'الرقم القومي مطلوب.',
                            'regex' => 'الرقم القومي يجب أن يكون 14 رقمًا بالضبط.',
                            'min' => 'الرقم القومي يجب أن يكون 14 رقمًا.',
                            'max' => 'الرقم القومي يجب أن يكون 14 رقمًا.',
                        ]),
                ])

                /*
                |--------------------------------------------------------------------------
                | ACTION
                |--------------------------------------------------------------------------
                */
                ->action(function (array $data): void {

                    /*
                    |--------------------------------------------------------------------------
                    | مهم جدًا
                    |--------------------------------------------------------------------------
                    |
                    | Filament عندك بيستخدم:
                    |
                    | ?activeTab=fake
                    |
                    |--------------------------------------------------------------------------
                    */
                    $isFake = $this->isFakeTab();

                    /*
                    |--------------------------------------------------------------------------
                    | NATIONAL ID
                    |--------------------------------------------------------------------------
                    */

                    $nid = $data['applicant_national_id'] ?? '';

                    /*
                    | تحويل الأرقام العربية والهندية
                    */
                    $nid = str_replace(
                        [
                            '٠',
                            '١',
                            '٢',
                            '٣',
                            '٤',
                            '٥',
                            '٦',
                            '٧',
                            '٨',
                            '٩',

                            '۰',
                            '۱',
                            '۲',
                            '۳',
                            '۴',
                            '۵',
                            '۶',
                            '۷',
                            '۸',
                            '۹',
                        ],
                        [
                            '0',
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                            '6',
                            '7',
                            '8',
                            '9',

                            '0',
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                            '6',
                            '7',
                            '8',
                            '9',
                        ],
                        $nid
                    );

                    /*
                    | إزالة أي حروف
                    */
                    $nid = preg_replace('/\D+/', '', $nid ?? '');

                    /*
                    | أول 14 رقم
                    */
                    $nid = substr($nid, 0, 14);

                    /*
                    |--------------------------------------------------------------------------
                    | VALIDATE NID
                    |--------------------------------------------------------------------------
                    */

                    if (strlen($nid) !== 14) {

                        Notification::make()
                            ->title('الرقم القومي غير صحيح')
                            ->body('الرقم القومي يجب أن يكون 14 رقمًا بالضبط.')
                            ->danger()
                            ->send();

                        return;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | NORMAL
                    |--------------------------------------------------------------------------
                    */

                    if (!$isFake) {

                        $existingNormal = InstallmentRequest::query()
                            ->where('applicant_national_id', $nid)
                            ->where('request_type', 'normal')
                            ->first();

                        if ($existingNormal) {

                            Notification::make()
                                ->title('الطلب موجود بالفعل')
                                ->body(
                                    'لا يمكن إنشاء طلب جديد. '
                                    . 'هذا الرقم القومي مسجل سابقًا '
                                    . "برقم طلب: #{$existingNormal->id}"
                                )
                                ->danger()
                                ->send();

                            return;
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | FAKE
                    |--------------------------------------------------------------------------
                    */

                    if ($isFake) {

                        $existingFake = InstallmentRequest::query()
                            ->where('applicant_national_id', $nid)
                            ->where('request_type', 'fake')
                            ->first();

                        if ($existingFake) {

                            Notification::make()
                                ->title('الطلب موجود بالفعل في طلبات فيك')
                                ->body(
                                    'هذا الرقم القومي مسجل بالفعل '
                                    . 'في طلبات فيك '
                                    . "برقم طلب: #{$existingFake->id}"
                                )
                                ->danger()
                                ->send();

                            return;
                        }
                    }

/*
|--------------------------------------------------------------------------
| REQUEST TYPE
|--------------------------------------------------------------------------
*/

$isFake = $this->isFakeTab();

$requestType = $isFake
    ? 'fake'
    : 'normal';


/*
|--------------------------------------------------------------------------
| REDIRECT
|--------------------------------------------------------------------------
*/

$this->redirect(
    DeliveryResource::getUrl('create', [
        'nid' => $nid,
        'request_type' => $requestType,
    ])
);
                }),
        ];
    }

    /**
     * ============================================================
     * CHECK ACTIVE TAB
     * ============================================================
     */
protected function isFakeTab(): bool
{
    return $this->activeTab === 'fake';
}
}
