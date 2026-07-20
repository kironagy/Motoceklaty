<?php

namespace App\Filament\Resources;

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
    protected static ?string $model = WhatsappBot::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'الإعدادات';
    protected static ?string $navigationLabel = 'بوتات واتساب';
    protected static ?string $modelLabel = 'بوت واتساب';
    protected static ?string $pluralModelLabel = 'بوتات واتساب';

    protected static string $nodeUrl = 'http://127.0.0.1:3010';

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
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم البوت')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('staff.name')
                    ->label('الموظف المرتبط')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('whatsapp_phone_number')
                    ->label('رقم الواتساب')
                    ->searchable()
                    ->toggleable(),
                    Tables\Columns\TextColumn::make('mode')
    ->label('نوع البوت')
    ->badge()
    ->formatStateUsing(fn ($state) => match ($state) {
        'training' => 'تعليمي',
        'live' => 'لايف',
        default => 'غير محدد',
    })
    ->color(fn ($state) => match ($state) {
        'training' => 'warning',
        'live' => 'success',
        default => 'gray',
    }),
                Tables\Columns\TextColumn::make('session_status')
                    ->label('حالة الربط')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'connected' => 'متصل',
                        'qr' => 'في انتظار QR',
                        'starting' => 'جاري التشغيل',
                        'disconnected' => 'مفصول',
                        'logged_out' => 'تم تسجيل الخروج',
                        default => 'غير مربوط',
                    })
                    ->color(fn ($state) => match ($state) {
                        'connected' => 'success',
                        'qr', 'starting' => 'warning',
                        'disconnected', 'logged_out' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('qr_code')
                    ->label('QR')
                    ->html()
                    ->formatStateUsing(function ($state) {
                        if (!$state) {
                            return '—';
                        }

                        return '<img src="' . e($state) . '" style="width:90px;height:90px;border-radius:12px;border:1px solid #ddd;padding:4px;background:white;" />';
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),

                Tables\Columns\TextColumn::make('connected_at')
                    ->label('وقت الاتصال')
                    ->dateTime('d/m/Y h:i A')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime('d/m/Y h:i A')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('الحالة')
                    ->trueLabel('النشط فقط')
                    ->falseLabel('غير النشط فقط')
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\Action::make('generate_qr')
    ->label('توليد QR')
    ->icon('heroicon-o-qr-code')
    ->color('warning')
    ->action(function (WhatsappBot $record) {
        $token = env('BOT_TOKEN');
        $botId = (string) $record->id;

        Http::timeout(15)
            ->withHeaders([
                'X-BOT-TOKEN' => $token,
            ])
            ->post(static::$nodeUrl . '/sessions/start', [
                'bot_id' => $botId,
            ]);

        $data = null;

        for ($i = 0; $i < 10; $i++) {
            sleep(1);

            $response = Http::timeout(15)
                ->withHeaders([
                    'X-BOT-TOKEN' => $token,
                ])
                ->get(static::$nodeUrl . "/sessions/{$botId}/qr");

            if (!$response->successful()) {
                continue;
            }

            $data = $response->json();

            if (!empty($data['qr']) || (($data['status'] ?? null) === 'connected')) {
                break;
            }
        }

        if (!$data) {
            Notification::make()
                ->title('فشل توليد QR')
                ->body('لم يتم الوصول لخدمة الواتساب')
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
        $response = Http::timeout(15)
            ->withHeaders([
                'X-BOT-TOKEN' => env('BOT_TOKEN'),
            ])
            ->get(static::$nodeUrl . "/sessions/{$record->id}/qr");

        if (!$response->successful()) {
            return new HtmlString('<div style="text-align:center;padding:20px;">فشل جلب QR من السيرفر.</div>');
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
    }),
                Tables\Actions\Action::make('check_status')
                    ->label('فحص الحالة')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function (WhatsappBot $record) {
                        $response = Http::timeout(15)
                            ->withHeaders([
                                'X-BOT-TOKEN' => env('BOT_TOKEN'),
                            ])
                            ->get(static::$nodeUrl . "/sessions/{$record->id}/status");

                        if (!$response->successful()) {
                            Notification::make()
                                ->title('فشل فحص الحالة')
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
                    }),

                Tables\Actions\Action::make('logout')
                    ->label('فصل الواتساب')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (WhatsappBot $record) {
                        Http::timeout(15)
                            ->withHeaders([
                                'X-BOT-TOKEN' => env('BOT_TOKEN'),
                            ])
                            ->post(static::$nodeUrl . "/sessions/{$record->id}/logout");

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
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('staff');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWhatsappBots::route('/'),
            'create' => Pages\CreateWhatsappBot::route('/create'),
            'edit' => Pages\EditWhatsappBot::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->is_super_admin ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->is_super_admin ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->is_super_admin ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->is_super_admin ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->is_super_admin ?? false;
    }
}

