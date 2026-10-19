<?php

namespace App\Filament\Resources;

use App\Domain\Conversations\DeliveryService;
use App\Domain\Forms\FormLinkService;
use App\Filament\Resources\FormLinkResource\Pages;
use App\Models\DynamicForm;
use App\Models\FormLink;
use App\Models\Machine;
use App\Models\WhatsappBot;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dynamic Links: every application link sent - who opened it, how far he got, what he wrote
 * even before sending (spec §4.8).
 */
class FormLinkResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;

    protected static ?string $model = FormLink::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'الطلبات والعملاء';

    protected static ?string $navigationLabel = 'روابط التقديم (Dynamic Links)';

    protected static ?string $modelLabel = 'رابط تقديم';

    protected static ?string $pluralModelLabel = 'روابط التقديم';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('purpose', ['apply', 'fix'])->with(['version.form', 'conversation', 'createdByStaff']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('30s')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('whatsapp_phone')->label('رقم الواتس')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('version.form.name')->label('الفورم')->toggleable(),
                Tables\Columns\TextColumn::make('purpose')->label('النوع')
                    ->formatStateUsing(fn ($state) => $state === 'fix' ? 'تعديل بيانات' : 'تقديم')->badge()
                    ->color(fn ($state) => $state === 'fix' ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn ($state) => FormLink::STATUS_LABELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'submitted' => 'success',
                        'in_progress' => 'warning',
                        'opened' => 'info',
                        'expired', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('progress')->label('اتملى')
                    ->state(fn (FormLink $record) => $record->status === 'submitted' ? '100%' : app(FormLinkService::class)->progress($record)['percent'].'%'),
                Tables\Columns\TextColumn::make('created_by_type')->label('عمله')
                    ->formatStateUsing(fn ($state, FormLink $record) => $state === 'bot' ? 'البوت' : ($record->createdByStaff?->name ?? 'موظف')),
                Tables\Columns\TextColumn::make('created_at')->label('اتبعت')->since()->sortable(),
                Tables\Columns\TextColumn::make('last_activity_at')->label('آخر حركة')->since()->placeholder('لسه مفتحش'),
                Tables\Columns\TextColumn::make('followups_sent')->label('متابعات'),
                Tables\Columns\TextColumn::make('receipt_status')->label('رسالة الاستلام')
                    ->formatStateUsing(fn ($state) => match ($state) { 'sent' => 'اتبعتت', 'failed' => 'فشلت ⚠️', default => '-' }),
                Tables\Columns\TextColumn::make('installment_request_id')->label('الطلب')
                    ->formatStateUsing(fn ($state) => $state ? '#'.$state : null)
                    ->url(fn (FormLink $record) => $record->installment_request_id ? DeliveryResource::getUrl('edit', ['record' => $record->installment_request_id]) : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(FormLink::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('created_by_type')->label('عمله')->options(['bot' => 'البوت', 'staff' => 'موظف']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('تفاصيل'),
            ]);
    }

    /** Staff send a link to a phone number from the dashboard. */
    public static function createAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('createLink')
            ->label('ابعت رابط لعميل')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn () => static::canCreate())
            ->form([
                Forms\Components\Select::make('bot_id')->label('من رقم البوت')
                    ->options(fn () => WhatsappBot::where('is_active', true)->pluck('name', 'id'))->required(),
                Forms\Components\TextInput::make('phone')->label('رقم واتس العميل')->tel()->required()->placeholder('01xxxxxxxxx'),
                Forms\Components\Select::make('form_id')->label('الفورم')
                    ->options(fn () => DynamicForm::where('is_active', true)->whereNotNull('published_version_id')->pluck('name', 'id'))
                    ->placeholder('فورم البوت'),
                Forms\Components\TextInput::make('customer_name')->label('اسم العميل (اختياري)'),
                Forms\Components\Select::make('machine_id')->label('المكنة (اختياري)')
                    ->options(fn () => Machine::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable(),
                Forms\Components\TextInput::make('months')->label('عدد الشهور (اختياري)')->numeric(),
            ])
            ->action(function (array $data) {
                $link = app(FormLinkService::class)->linkForPhone(
                    WhatsappBot::findOrFail($data['bot_id']),
                    (string) $data['phone'],
                    auth()->user(),
                    ['customer_name' => $data['customer_name'] ?? null, 'machine_id' => $data['machine_id'] ?? null, 'months' => $data['months'] ?? null],
                    filled($data['form_id'] ?? null) ? DynamicForm::find($data['form_id']) : null,
                );

                $problem = static::sendToCustomer($link, 'ده رابط طلب التقسيط، املاه وارفع الورق المطلوب من عليه 👇');

                $problem === null
                    ? Notification::make()->title('الرابط اتبعت')->body($link->url())->success()->send()
                    : Notification::make()->title('الرابط اتعمل بس متبعتش')->body($problem."\n".$link->url())->warning()->persistent()->send();
            });
    }

    /**
     * Sends the link on WhatsApp from the conversation's own number.
     *
     * @return string|null why it was not sent (in staff words), null when sent
     */
    public static function sendToCustomer(FormLink $link, string $intro): ?string
    {
        $conversation = $link->conversation;

        if (! $conversation) {
            return 'الرابط ده مش مربوط بمحادثة واتساب.';
        }

        // live 2026-10-19: "Delivery failed: 404 session not found" - a simulator chat, or a
        // number that is off / logged out, cannot send anything
        if (! static::canSendFrom($link)) {
            return 'رقم الواتساب بتاع المحادثة دي مش متوصل ('.($conversation->whatsappBot?->name ?? 'رقم مش معروف').'). انسخ الرابط وابعته بنفسك، أو شغّل الرقم من "بوتات الواتساب".';
        }

        try {
            app(DeliveryService::class)->deliverForConversation($conversation, ['messages' => [$intro, $link->url()]], 'agent', null, false);
        } catch (\Throwable $e) {
            return str_contains($e->getMessage(), 'session not found')
                ? 'رقم الواتساب ده مش متوصل دلوقتي (عامل logout). انسخ الرابط وابعته بنفسك، أو اربط الرقم تاني من "بوتات الواتساب".'
                : 'الرسالة موصلتش: '.mb_substr($e->getMessage(), 0, 160);
        }

        return null;
    }

    /** The chat's number is a live WhatsApp bot (not the simulator, not switched off). */
    public static function canSendFrom(FormLink $link): bool
    {
        $conversation = $link->conversation;

        return $conversation
            && $conversation->whatsappBot?->is_active
            && ! str_starts_with((string) $conversation->phone, 'sim-')
            && ! \App\Services\GeminiKeyManager::isTestPhone($conversation->phone);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFormLinks::route('/'),
            'view' => Pages\ViewFormLink::route('/{record}'),
        ];
    }
}
