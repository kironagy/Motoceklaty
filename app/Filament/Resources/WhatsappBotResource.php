<?php

namespace App\Filament\Resources;

use App\Filament\Support\HitlerOnly;
use App\Filament\Resources\WhatsappBotResource\Pages;
use App\Models\Staff;
use App\Models\WhatsappBot;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;

class WhatsappBotResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use HitlerOnly;

    protected static ?string $model = WhatsappBot::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?int $navigationSort = 0;
    protected static ?string $navigationGroup = 'البوت الذكي';
    protected static ?string $navigationLabel = 'بوتات واتساب';
    protected static ?string $modelLabel = 'بوت واتساب';
    protected static ?string $pluralModelLabel = 'بوتات واتساب';

    /**
     * Owner 2026-11-15: hands the bot's proxy to the worker, which tries it and reconnects the number
     * through it. Shows the address WhatsApp now sees, or why the proxy did not work.
     */
    public static function pushProxy(WhatsappBot $bot): void
    {
        try {
            $response = Http::timeout(30)->withHeaders(['X-BOT-TOKEN' => config('services.whatsapp.bot_token'), 'Accept' => 'application/json'])
                ->post(static::getNodeUrl().'/sessions/'.$bot->id.'/proxy', ['proxy_url' => (string) $bot->proxy_url]);
        } catch (\Throwable $e) {
            \Filament\Notifications\Notification::make()->title('سيرفر الواتساب مش بيرد، البروكسي هيتطبق مع أول تشغيل للرقم')->warning()->send();

            return;
        }

        if (! $response->json('ok')) {
            \Filament\Notifications\Notification::make()->title('البروكسي مش شغال')->body((string) $response->json('error'))->danger()->persistent()->send();

            return;
        }

        \Filament\Notifications\Notification::make()
            ->title($response->json('proxy') ? 'البروكسي اشتغل' : 'البروكسي اتشال، الرقم بيطلع من السيرفر مباشرة')
            ->body($response->json('public_ip') ? 'العنوان اللي واتساب شايفه دلوقتي: '.$response->json('public_ip') : null)
            ->success()->send();
    }

    protected static function getNodeUrl(): string
    {
        return (string) config('services.whatsapp.worker_url');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('بيانات البوت')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('اسم البوت')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('staff_id')
                        ->label('الموظف المرتبط')
                        ->options(function (?WhatsappBot $record) {
                            return Staff::query()
                                ->where('is_bot', true)
                                ->where(function ($query) use ($record) {
                                    $query->whereDoesntHave('whatsappBot');

                                    if ($record?->staff_id) {
                                        $query->orWhere('id', $record->staff_id);
                                    }
                                })
                                ->pluck('name', 'id');
                        })
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('هيظهر هنا الموظفين اللي متعلمين كبوت فقط، واللي مش مربوطين ببوت تاني'),

                    Forms\Components\TextInput::make('whatsapp_phone_number')
                        ->label('رقم الواتساب')
                        ->tel()
                        ->nullable()
                        ->maxLength(50)
                        ->helperText('مثال: 201001234567'),

                    Forms\Components\TextInput::make('whatsapp_phone_number_id')
                        ->label('Session / Bot Key')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->helperText('اكتب أي كود مميز للبوت، مثال: bot_1 أو sales_1'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('نشط')
                        ->default(true),
                    Forms\Components\ToggleButtons::make('mode')
                        ->label('نوع البوت')
                        ->options([
                            'training' => 'تعليمي',
                            'live' => 'لايف',
                        ])
                        ->icons([
                            'training' => 'heroicon-o-academic-cap',
                            'live' => 'heroicon-o-bolt',
                        ])
                        ->colors([
                            'training' => 'warning',
                            'live' => 'success',
                        ])
                        ->default('live')
                        ->inline()
                        ->required()
                        ->helperText('التعليمي يتعلم ويخزن الردود فقط، اللايف يرد على العملاء باستخدام الذاكرة.'),

                    // Owner 2026-11-15: the number reaches WhatsApp through its own proxy (one per number)
                    Forms\Components\TextInput::make('proxy_url')
                        ->label('البروكسي')
                        ->password()
                        ->revealable()
                        ->nullable()
                        ->placeholder('http://user:pass@host:port')
                        ->rule('regex:~^(https?|socks5h?)://([^:@/\s]+(:[^@/\s]*)?@)?[A-Za-z0-9.\-]+:\d{2,5}/?$~')
                        ->validationMessages(['regex' => 'الصيغة: http://اسم_المستخدم:الباسورد@العنوان:البورت'])
                        ->helperText('عنوان مصري ثابت مخصص للرقم ده. الأفضل صيغة http. بعد الحفظ الرقم بيعيد الاتصال من خلاله، وسيبه فاضي عشان يطلع من السيرفر مباشرة.')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('notes')
                        ->label('ملاحظات')
                        ->rows(4)
                        ->nullable()
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->recordUrl(fn (WhatsappBot $record) => static::getUrl('activity', ['record' => $record]))
            ->paginated([12, 24, 48])
            ->searchPlaceholder('ابحث باسم البوت أو الرقم')
            ->columns([
                Tables\Columns\Layout\View::make('filament.resources.whatsapp-bots.card'),
                // Hidden, only so search and sort keep working with the card layout.
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->hidden(),
                Tables\Columns\TextColumn::make('whatsapp_phone_number')->searchable()->hidden(),
                Tables\Columns\TextColumn::make('staff.name')->searchable()->hidden(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('الحالة')
                    ->trueLabel('النشط فقط')
                    ->falseLabel('غير النشط فقط')
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\Action::make('activity')
                    ->label('التفاصيل والمحادثات')
                    ->icon('heroicon-o-chart-bar-square')
                    ->color('primary')
                    ->button()
                    ->url(fn (WhatsappBot $record) => static::getUrl('activity', ['record' => $record])),

                Tables\Actions\ActionGroup::make([
                Tables\Actions\Action::make('proxy_test')
                    ->label('اختبار البروكسي')
                    ->icon('heroicon-o-globe-alt')
                    ->visible(fn (WhatsappBot $record) => filled($record->proxy_url))
                    ->action(function (WhatsappBot $record) {
                        try {
                            $response = Http::timeout(30)->withHeaders(['X-BOT-TOKEN' => config('services.whatsapp.bot_token'), 'Accept' => 'application/json'])
                                ->get(static::getNodeUrl().'/sessions/'.$record->id.'/proxy-test');
                        } catch (\Throwable $e) {
                            Notification::make()->title('سيرفر الواتساب مش بيرد')->danger()->send();

                            return;
                        }

                        $response->json('ok')
                            ? Notification::make()->title('البروكسي شغال')->body('العنوان اللي واتساب شايفه: '.$response->json('public_ip').' ('.$response->json('proxy').')')->success()->send()
                            : Notification::make()->title('البروكسي مش شغال')->body((string) $response->json('error'))->danger()->persistent()->send();
                    }),

                Tables\Actions\Action::make('generate_qr')
                    ->label('توليد QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('warning')
                    ->action(function (WhatsappBot $record) {
                        $token = config('services.whatsapp.bot_token');
                        $botId = (string) $record->id;

                        try {
                            Http::timeout(10)
                                ->withHeaders([
                                    'X-BOT-TOKEN' => $token,
                                ])
                                ->post(static::getNodeUrl() . '/sessions/start', [
                                    'bot_id' => $botId,
                                    // Owner 2026-11-15: the number connects through its own proxy
                                    'proxy_url' => (string) $record->proxy_url,
                                ]);

                            $data = null;

                            for ($i = 0; $i < 10; $i++) {
                                sleep(1);

                                $response = Http::timeout(10)
                                    ->withHeaders([
                                        'X-BOT-TOKEN' => $token,
                                    ])
                                    ->get(static::getNodeUrl() . "/sessions/{$botId}/qr");

                                if (!$response->successful()) {
                                    continue;
                                }

                                $data = $response->json();

                                if (!empty($data['qr']) || (($data['status'] ?? null) === 'connected')) {
                                    break;
                                }
                            }
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('خدمة الواتساب معطلة حالياً')
                                ->body('تعذر الاتصال بسيرفر خدمة الواتساب. يرجى التأكد من تشغيل الخدمة والمحاولة مرة أخرى.')
                                ->danger()
                                ->send();

                            return;
                        }

                        if (!$data) {
                            Notification::make()
                                ->title('فشل توليد QR')
                                ->body('لم يتم الوصول لخدمة الواتساب أو استغرق السيرفر وقتاً طويلاً.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update([
                            'qr_code' => $data['qr'] ?? null,
                            'session_status' => $data['status'] ?? 'starting',
                            'connected_at' => ($data['status'] ?? null) === 'connected' ? now() : $record->connected_at,
                        ]);

                        if (!empty($data['qr'])) {
                            Notification::make()
                                ->title('تم توليد QR')
                                ->body('اضغط عرض QR أو امسحه من الجدول')
                                ->success()
                                ->send();

                            return;
                        }

                        if (($data['status'] ?? null) === 'connected') {
                            Notification::make()
                                ->title('البوت متصل بالفعل')
                                ->body('لا يوجد QR لأن الجلسة متصلة')
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('QR لم يظهر بعد')
                            ->body('اضغط عرض QR بعد ثواني أو جرّب فحص الحالة')
                            ->warning()
                            ->send();
                    }),
                Tables\Actions\Action::make('show_qr')
                    ->label('عرض QR')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading('QR Code')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    ->modalContent(function (WhatsappBot $record) {
                        try {
                            $response = Http::timeout(10)
                                ->withHeaders([
                                    'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                                ])
                                ->get(static::getNodeUrl() . "/sessions/{$record->id}/qr");

                            if (!$response->successful()) {
                                return new HtmlString('<div style="text-align:center;padding:20px;color:#dc2626;font-weight:bold;">فشل جلب QR من السيرفر.</div>');
                            }

                            $data = $response->json();
                            $qr = $data['qr'] ?? null;

                            $record->update([
                                'qr_code' => $qr,
                                'session_status' => $data['status'] ?? $record->session_status,
                            ]);

                            if (!$qr) {
                                return new HtmlString('<div style="text-align:center;padding:20px;">لا يوجد QR حاليًا. اضغط توليد QR وانتظر ثانيتين.</div>');
                            }

                            return new HtmlString(
                                '<div style="text-align:center;padding:20px;">
                    <img src="' . e($qr) . '" style="width:320px;height:320px;border-radius:16px;border:1px solid #ddd;padding:8px;background:white;" />
                    <p style="margin-top:12px;font-weight:bold;">افتح واتساب &gt; Linked Devices &gt; Link a device</p>
                </div>'
                            );
                        } catch (\Throwable $e) {
                            return new HtmlString('<div style="text-align:center;padding:20px;color:#dc2626;font-weight:bold;">⚠️ خدمة الواتساب معطلة حالياً أو لا يمكن الاتصال بها. يرجى التأكد من تشغيل الخدمة.</div>');
                        }
                    }),
                Tables\Actions\Action::make('check_status')
                    ->label('فحص الحالة')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function (WhatsappBot $record) {
                        try {
                            $response = Http::timeout(10)
                                ->withHeaders([
                                    'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                                ])
                                ->get(static::getNodeUrl() . "/sessions/{$record->id}/status");

                            if (!$response->successful()) {
                                Notification::make()
                                    ->title('فشل فحص الحالة')
                                    ->body('سيرفر الواتساب لم يستجب بنجاح.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $data = $response->json();

                            $record->update([
                                'session_status' => $data['status'] ?? null,
                                'connected_at' => ($data['status'] ?? null) === 'connected' ? now() : $record->connected_at,
                                'qr_code' => ($data['status'] ?? null) === 'connected' ? null : $record->qr_code,
                            ]);

                            Notification::make()
                                ->title('تم تحديث الحالة')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('خدمة الواتساب معطلة حالياً')
                                ->body('تعذر الاتصال بسيرفر خدمة الواتساب.')
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('logout')
                    ->label('فصل الواتساب')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (WhatsappBot $record) {
                        try {
                            Http::timeout(10)
                                ->withHeaders([
                                    'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                                ])
                                ->post(static::getNodeUrl() . "/sessions/{$record->id}/logout");
                        } catch (\Throwable $e) {
                            $record->update([
                                'qr_code' => null,
                                'session_status' => 'logged_out',
                                'connected_at' => null,
                            ]);

                            Notification::make()
                                ->title('تنبيه: خدمة الواتساب معطلة حالياً')
                                ->body('تم تغيير حالة البوت محلياً ولكن تعذر الاتصال بسيرفر الواتساب.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $record->update([
                            'qr_code' => null,
                            'session_status' => 'logged_out',
                            'connected_at' => null,
                        ]);

                        Notification::make()
                            ->title('تم فصل الواتساب')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                // A bot with customers can only be deactivated: deleting it
                // cascades to every customer, application and document.
                // Owner 2026-11-22: "مسح البوت" for every bot - one with customers is switched off and
                // hidden (WhatsappBot::remove), its customers, conversations and requests stay.
                Tables\Actions\Action::make('remove')
                    ->label('مسح البوت')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (WhatsappBot $record) => static::canDelete($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (WhatsappBot $record) => 'مسح البوت '.$record->name.'؟')
                    ->modalDescription(fn (WhatsappBot $record) => $record->hasCustomerData()
                        ? 'الواتساب هيتفصل والبوت هيقف ويختفي من القايمة. عملاؤه ومحادثاته وطلباته هيفضلوا زي ما هم.'
                        : 'الواتساب هيتفصل والبوت هيتمسح نهائي.')
                    ->modalSubmitActionLabel('امسح')
                    ->action(function (WhatsappBot $record) {
                        $deleted = $record->remove();
                        Notification::make()->title($deleted ? 'البوت اتمسح' : 'البوت اتقفل واختفى، وعملاؤه فضلوا')->success()->send();
                    }),
                ])->label('إدارة')->icon('heroicon-m-ellipsis-vertical')->color('gray')->button(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('delete_empty_bots')
                    ->label('حذف البوتات اللي مفيهاش عملاء')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records) {
                        [$kept, $deletable] = $records->partition(fn (WhatsappBot $bot) => $bot->hasCustomerData());
                        $deletable->each->delete();

                        Notification::make()
                            ->title("اتحذف {$deletable->count()} بوت")
                            ->body($kept->isEmpty() ? null : 'ما اتحذفش: '.$kept->pluck('name')->implode('، ').' - عليهم عملاء ومحادثات، اقفلهم (غير نشط) بدل الحذف.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('archived_at')->with('staff')->orderByDesc('is_active');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWhatsappBots::route('/'),
            'create' => Pages\CreateWhatsappBot::route('/create'),
            'edit' => Pages\EditWhatsappBot::route('/{record}/edit'),
            'activity' => Pages\BotActivityPage::route('/{record}/activity'),
        ];
    }

    public static function getWidgets(): array
    {
        return [WhatsappBotResource\Widgets\BotsTodayOverview::class];
    }

    /** Today's chats and messages per bot, computed once per request for all the cards. */
    public static function todayTotals(): array
    {
        return once(fn () => app(\App\Domain\Bots\BotActivity::class)->todayTotals());
    }

    /**
     * Owner 2026-11-15: how much of its send caps each number used - different customers answered in
     * the last hour / 24 hours, the same count ProcessWhatsappMessageJobs holds a number back on.
     *
     * @return array<int, array{hour:int, day:int}>
     */
    public static function capUsage(): array
    {
        return once(function () {
            $count = fn ($since) => \Illuminate\Support\Facades\DB::table('whatsapp_messages')->where('direction', 'outgoing')
                ->where('delivery_status', 'sent')->where('created_at', '>=', $since)
                ->groupBy('whatsapp_bot_id')->selectRaw('whatsapp_bot_id, count(distinct whatsapp_conversation_id) n')
                ->pluck('n', 'whatsapp_bot_id');
            $hour = $count(now()->subHour());
            $day = $count(now()->subDay());

            return $day->keys()->merge($hour->keys())->unique()
                ->mapWithKeys(fn ($id) => [(int) $id => ['hour' => (int) ($hour[$id] ?? 0), 'day' => (int) ($day[$id] ?? 0)]])->all();
        });
    }

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return static::isHitler();
    }

    public static function canCreate(): bool
    {
        if (($allowed = static::roleAllows('create')) !== null) {
            return $allowed;
        }

        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        if (($allowed = static::roleAllows('update')) !== null) {
            return $allowed;
        }

        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        if (($allowed = static::roleAllows('delete')) !== null) {
            return $allowed;
        }

        return static::canViewAny();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }
}

