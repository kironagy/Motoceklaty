<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BotOfferResource\Pages;
use App\Models\BotOffer;
use App\Models\Brand;
use App\Models\CustomerType;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Owner 2026-11-19: "عروض البوت" - the owner adds an offer, the bot knows it and works with it
 * until it is switched off (or its end date passes). Three kinds: a motorcycle that exists only
 * here (كسر زيرو), a lower interest (also in the dashboard calculator), or plain words.
 */
class BotOfferResource extends Resource
{
    protected static ?string $model = BotOffer::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';
    protected static ?string $navigationGroup = 'البوت الذكي';
    protected static ?string $navigationLabel = 'عروض البوت';
    protected static ?string $modelLabel = 'عرض';
    protected static ?string $pluralModelLabel = 'عروض البوت';

    public static function canAccess(): bool
    {
        return (bool) (Auth::user()?->is_hitler ?? false);
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit($record): bool
    {
        return static::canAccess();
    }

    public static function canDelete($record): bool
    {
        return static::canAccess();
    }

    public static function form(Form $form): Form
    {
        $is = fn (string ...$kinds) => fn (Get $get) => in_array($get('kind'), $kinds, true);

        return $form->schema([
            Forms\Components\Section::make('العرض')->schema([
                Forms\Components\Select::make('kind')->label('نوع العرض')->options(BotOffer::KINDS)
                    ->required()->live()->native(false),
                Forms\Components\TextInput::make('title')->label('اسم العرض')->required()->maxLength(255)
                    ->placeholder('مثال: هوندا كسر زيرو / فايدة 20% للموظفين'),
                Forms\Components\Textarea::make('details')->label('تفاصيل العرض للبوت')->rows(4)->columnSpanFull()
                    ->helperText('اكتب العرض بكلامك: البوت بيفهم منه ويقوله للعميل. مثال: "الهدية خوذة مع أي مكنة" أو "المكنة ماشية 3000 كم وعليها ضمان 6 شهور".'),
                Forms\Components\Toggle::make('is_active')->label('شغال')->default(true)->inline(false),
                Forms\Components\TextInput::make('priority')->label('الأولوية')->numeric()->default(0)
                    ->helperText('الأعلى يتقال الأول لو فيه أكتر من عرض.'),
                Forms\Components\DateTimePicker::make('starts_at')->label('يبدأ من')->native(false)->seconds(false),
                Forms\Components\DateTimePicker::make('ends_at')->label('يخلص في')->native(false)->seconds(false)
                    ->helperText('اختياري - بعده العرض يقف لوحده.'),
            ])->columns(2),

            Forms\Components\Section::make('المكنة')->visible($is('machine'))->schema([
                Forms\Components\Select::make('brand_id')->label('الماركة')->options(fn () => Brand::orderBy('name')->pluck('name', 'id'))
                    ->searchable()->required($is('machine')),
                Forms\Components\TextInput::make('machine_name')->label('اسم المكنة')->required($is('machine'))->placeholder('مثال: هوندا ديو 110'),
                Forms\Components\Select::make('condition')->label('الحالة')->options(BotOffer::CONDITIONS)->default('كسر زيرو'),
                Forms\Components\TextInput::make('stock')->label('عدد القطع المتاحة')->numeric()->minValue(0)
                    ->helperText('اختياري - لو وصل صفر العرض يقف لوحده.'),
                Forms\Components\TextInput::make('cash_price')->label('سعر الكاش')->numeric()->prefix('جنيه')->required($is('machine')),
                Forms\Components\TextInput::make('old_price')->label('السعر قبل العرض')->numeric()->prefix('جنيه'),
                Forms\Components\TextInput::make('installment_price')->label('سعر التقسيط')->numeric()->prefix('جنيه')
                    ->helperText('لو فاضي = سعر الكاش.'),
                Forms\Components\TagsInput::make('aliases')->label('أسماء تانية للبحث')
                    ->helperText('أي اسم ممكن العميل يكتبه بيه.'),
                Forms\Components\FileUpload::make('images')->label('صور المكنة')->image()->multiple()->reorderable()
                    ->disk('public')->directory('machines/offers')->maxFiles(8)->columnSpanFull()
                    ->helperText('أول صورة هي الصورة الأساسية - البوت بيبعت الصور دي.'),
                Forms\Components\KeyValue::make('specifications')->label('المواصفات')->keyLabel('الخاصية')->valueLabel('القيمة')->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('الفايدة')->visible($is('interest'))->schema([
                Forms\Components\TextInput::make('yearly_rate')->label('الفايدة في السنة')->numeric()->suffix('%')
                    ->required($is('interest'))->minValue(0)->maxValue(100)
                    ->helperText('زي ما الأنظمة بتحسب: 20% في السنة = 20% على 12 شهر، 30% على 18، 40% على 24. البوت والحاسبة بيحسبوا بيها لوحدهم.'),
                Forms\Components\CheckboxList::make('customer_type_ids')->label('لمين')
                    ->options(fn () => CustomerType::where('is_active', true)->orderBy('sort')->pluck('label', 'id'))
                    ->helperText('مفيش اختيار = كل العملاء.')->columns(2),
                Forms\Components\CheckboxList::make('months')->label('على أنهي مدة')
                    ->options([12 => '12 شهر', 18 => '18 شهر', 24 => '24 شهر', 36 => '36 شهر'])
                    ->helperText('مفيش اختيار = كل المدد.')->columns(4),
                Forms\Components\Select::make('machine_ids')->label('على مكن معينة')->multiple()->searchable()
                    ->options(fn () => Machine::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->helperText('فاضي = كل المكن.'),
            ])->columns(2),

            Forms\Components\Section::make('أنظمة التقسيط')->visible($is('machine', 'interest'))->schema([
                Forms\Components\CheckboxList::make('installment_system_ids')->label('')
                    ->options(fn () => InstallmentSystem::pluck('name', 'id')->map(fn ($n) => trim($n)))
                    ->helperText(fn (Get $get) => $get('kind') === 'machine'
                        ? 'الأنظمة اللي المكنة دي تتقسط عليها.'
                        : 'العرض على أنهي نظام - مفيش اختيار = كل الأنظمة.')
                    ->columns(3)->bulkToggleable(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العرض')->searchable()->weight('bold')->wrap(),
                Tables\Columns\TextColumn::make('kind')->label('النوع')->badge()
                    ->formatStateUsing(fn ($state) => BotOffer::KINDS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'machine' => 'info', 'interest' => 'success', default => 'gray' }),
                Tables\Columns\TextColumn::make('summary')->label('التفاصيل')
                    ->state(fn (BotOffer $r) => match ($r->kind) {
                        'machine' => trim($r->machine_name.' '.$r->condition).' - '.number_format((float) $r->cash_price).' جنيه'.($r->stock !== null ? " - فاضل {$r->stock}" : ''),
                        'interest' => rtrim(rtrim(number_format((float) $r->yearly_rate, 2, '.', ''), '0'), '.').'% في السنة',
                        default => \Illuminate\Support\Str::limit((string) $r->details, 60),
                    })->wrap(),
                Tables\Columns\TextColumn::make('state')->label('الحالة')->badge()
                    ->state(fn (BotOffer $r) => match (true) {
                        $r->isLive() => 'شغال',
                        ! $r->is_active => 'متوقف',
                        $r->starts_at && $r->starts_at->isFuture() => 'لسه مابدأش',
                        $r->ends_at && $r->ends_at->isPast() => 'خلص',
                        default => 'خلصت القطع',
                    })
                    ->color(fn ($state) => $state === 'شغال' ? 'success' : ($state === 'لسه مابدأش' ? 'warning' : 'danger')),
                Tables\Columns\TextColumn::make('ends_at')->label('يخلص')->dateTime('d/m/Y h:i A')->placeholder('مفتوح')->sortable(),
                Tables\Columns\ToggleColumn::make('is_active')->label('شغال')->onColor('success')->offColor('danger'),
            ])
            ->defaultSort('priority', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBotOffers::route('/'),
            'create' => Pages\CreateBotOffer::route('/create'),
            'edit' => Pages\EditBotOffer::route('/{record}/edit'),
        ];
    }
}
