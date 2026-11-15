<?php

namespace App\Filament\Resources\AwaitingAgentConversationResource\Pages;

use App\Filament\Resources\AwaitingAgentConversationResource;
use App\Models\WhatsappConversation;
use App\Support\CustomerNumbersExport;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAwaitingAgentConversations extends ListRecords
{
    protected static string $resource = AwaitingAgentConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Owner 2026-11-15: the waiting customers' numbers as Excel, over as many files as asked.
            // Follows the filter chosen on the table.
            Actions\Action::make('export_numbers')
                ->label('استخراج الأرقام Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->modalHeading('استخراج أرقام العملاء')
                ->modalDescription('الأرقام اللي ظاهرة في الجدول بالفلتر الحالي. لو العدد مايتقسمش بالظبط، أول ملفات بياخدوا رقم زيادة.')
                ->modalSubmitActionLabel('استخراج')
                ->form([
                    Forms\Components\TextInput::make('files')
                        ->label('عدد الملفات')
                        ->numeric()->integer()->minValue(1)->maxValue(100)
                        ->default(1)->required(),
                ])
                ->action(function (array $data) {
                    $rows = $this->getFilteredTableQuery()->with('customer')->reorder()->orderByDesc('last_inbound_at')->get()
                        ->map(function (WhatsappConversation $c) {
                            $phone = collect([$c->real_phone, $c->customer?->phone, $c->phone, $c->customer?->jid])
                                ->map(fn ($p) => CustomerNumbersExport::phone($p))->first(fn ($p) => $p !== null);

                            return $phone ? [
                                'phone' => $phone,
                                'name' => $c->customer?->push_name,
                                'reason' => $c->status === 'awaiting_agent' ? 'محوّل لموظف' : 'أقدم من 24 ساعة',
                                'last' => $c->last_inbound_at?->timezone('Africa/Cairo')->format('Y-m-d H:i'),
                            ] : null;
                        });

                    $hidden = $rows->filter(fn ($r) => $r === null)->count();
                    $rows = $rows->filter()->unique('phone')->values();

                    if ($rows->isEmpty()) {
                        Notification::make()->title('مفيش أرقام حقيقية تتستخرج')
                            ->body($hidden ? "{$hidden} عميل رقمهم مخفي من واتساب" : null)->warning()->send();

                        return null;
                    }

                    $path = CustomerNumbersExport::build($rows, (int) $data['files']);

                    Notification::make()->title('اتستخرج '.number_format($rows->count()).' رقم')
                        ->body($hidden ? "{$hidden} عميل رقمهم مخفي من واتساب، فمش في الملف" : null)->success()->send();

                    return response()->download($path, basename($path))->deleteFileAfterSend();
                }),
        ];
    }
}
