<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstallmentCalculatorResource\Pages;
use App\Models\Brand;
use App\Models\InstallmentCalculator;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InstallmentCalculatorResource extends Resource
{
    protected static ?string $model = InstallmentCalculator::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'احسب الأقساط';

    protected static ?string $modelLabel = 'حساب قسط';

    protected static ?string $pluralModelLabel = 'حسابات الأقساط';

    protected static ?string $navigationGroup = 'التقسيط';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\Select::make('brand_id')
                    ->label('ماركة المكنة')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Forms\Set $set) {
                        $set('machine_id', null);
                    }),

               Forms\Components\Select::make('machine_id')
    ->label('المكنة')
    ->options(function (Forms\Get $get) {

        $brandId = $get('brand_id');

        if (!$brandId) {
            return [];
        }

        return \App\Models\Machine::query()
            ->where('brand_id', $brandId)
            ->orderBy('name')
            ->pluck('name', 'id');
    })
    ->searchable()
    ->preload()
    ->required()
    ->live()
    ->afterStateUpdated(function ($state, Forms\Set $set) {

        $machine = \App\Models\Machine::find($state);

        $set(
            'machine_installment_price',
            $machine?->installment_price ?? 0
        );
    }),
                Forms\Components\Select::make('installment_system_id')
                    ->label('نظام التقسيط')
                    ->relationship('installmentSystem', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Forms\Set $set) {
                        $set('months', null);
                    }),

                Forms\Components\Select::make('months')
                    ->label('عدد الشهور')
                    ->options(function (Forms\Get $get) {

                        $systemId = $get('installment_system_id');

                        if (!$systemId) {
                            return [];
                        }

                        $system = InstallmentSystem::find($systemId);

                        if (!$system || !is_array($system->plans)) {
                            return [];
                        }

                        $options = [];

                        foreach ($system->plans as $plan) {

                            if (
                                isset($plan['months']) &&
                                isset($plan['interest'])
                            ) {
                                $options[$plan['months']] =
                                    $plan['months'] .
                                    ' شهر (' .
                                    $plan['interest'] .
                                    '% فايدة)';
                            }
                        }

                        return $options;
                    })
                    ->required()
                    ->live(),
// Display only: the value itself is stored by the Hidden field below - a
// second input on the same state rendered the field twice.
Forms\Components\Placeholder::make('machine_installment_price_display')
    ->label('سعر التقسيط')
    ->content(function (Forms\Get $get) {
        $price = $get('machine_id') ? \App\Models\Machine::find($get('machine_id'))?->installment_price : null;

        return number_format((float) ($price ?? 0)).' جنيه';
    }),
                Forms\Components\TextInput::make('down_payment')
                    ->label('المقدم')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->prefix('جنيه')
                    ->required(),

                Forms\Components\Placeholder::make('calculation_result')
                    ->label('نتيجة الحساب')
                    ->content(function (Forms\Get $get) {

                        $machineId = $get('machine_id');
                        $systemId = $get('installment_system_id');
                        $months = $get('months');
                        $downPayment = (float) ($get('down_payment') ?? 0);

                        if (!$machineId || !$systemId || !$months) {
                            return 'اختر الماركة والمكنة ونظام التقسيط وعدد الشهور لحساب القسط.';
                        }

                        $machine = Machine::find($machineId);
                        $system = InstallmentSystem::find($systemId);

                        if (!$machine || !$system) {
                            return 'بيانات المكنة أو نظام التقسيط غير صحيحة.';
                        }

                        $cashPrice = (float) ($machine->cash_price ?? 0);

                        $installmentPrice =
                            (float) ($machine->installment_price ?? 0);

                        $interest = 0;

                        foreach ($system->plans ?? [] as $plan) {

                            if (
                                isset($plan['months']) &&
                                (int) $plan['months'] === (int) $months
                            ) {
                                $interest = (float) ($plan['interest'] ?? 0);
                                break;
                            }
                        }

                        $adminFeesPercent =
                            (float) ($system->administrative_fees ?? 0);

                        if ($downPayment >= $cashPrice && $cashPrice > 0) {
                            return '⚠️ المقدم لا يمكن أن يكون أكبر من أو يساوي سعر المكنة الكاش.';
                        }

                        $remaining = 0;
                        $totalWithInterest = 0;
                        $monthly = 0;
                        $adminFeesAmount = 0;

                        if (
                            str_contains(
                                mb_strtolower($system->name),
                                'زيرو مصاريف'
                            )
                        ) {

                            $remaining = $cashPrice - $downPayment;

                            $plus75 = $remaining * 1.075;

                            $totalWithInterest =
                                $plus75 * (1 + $interest / 100);

                            $monthly =
                                $totalWithInterest / $months;

                            $adminFeesAmount = 0;

                        } else {

                            $remaining =
                                $installmentPrice - $downPayment;

                            $totalWithInterest =
                                $remaining +
                                ($remaining * $interest / 100);

                            $monthly =
                                $totalWithInterest / $months;

                            $adminFeesAmount =
                                $remaining *
                                ($adminFeesPercent / 100);
                        }

  return new \Illuminate\Support\HtmlString('

<div dir="rtl" style="
    position:relative;
    overflow:hidden;
    width:100%;
    border-radius:26px;

    background:
        linear-gradient(
            135deg,
            rgba(17,24,39,.94),
            rgba(15,23,42,.88)
        );

    border:1px solid rgba(255,255,255,.10);

    box-shadow:
        0 25px 60px rgba(0,0,0,.28),
        inset 0 1px 0 rgba(255,255,255,.06);

    backdrop-filter:blur(24px);
    -webkit-backdrop-filter:blur(24px);

    padding:28px;
">

    <!-- ================================= -->
    <!-- BACKGROUND GLOW -->
    <!-- ================================= -->

    <div style="
        position:absolute;
        width:320px;
        height:320px;
        border-radius:50%;
        top:-180px;
        left:-100px;

        background:rgba(0,188,212,.13);

        filter:blur(80px);

        pointer-events:none;
    "></div>

    <div style="
        position:absolute;
        width:260px;
        height:260px;
        border-radius:50%;
        bottom:-180px;
        right:-80px;

        background:rgba(99,102,241,.09);

        filter:blur(80px);

        pointer-events:none;
    "></div>


    <!-- ================================= -->
    <!-- HEADER -->
    <!-- ================================= -->

    <div style="
        position:relative;
        display:flex;
        align-items:center;
        justify-content:space-between;

        margin-bottom:26px;
    ">

        <div>

            <div style="
                display:flex;
                align-items:center;
                gap:8px;

                font-size:12px;
                font-weight:600;

                color:#fff;

                margin-bottom:7px;
            ">

                <span style="
                    width:7px;
                    height:7px;
                    border-radius:50%;

                    background:#06b6d4;

                    box-shadow:
                        0 0 12px rgba(6,182,212,.8);
                "></span>

                نتيجة حساب التقسيط

            </div>


            <div style="
                font-size:25px;
                font-weight:900;

                letter-spacing:-.6px;

                color:#f8fafc;
            ">

                تفاصيل التمويل

            </div>

        </div>


        <!-- Calculator Icon -->

        <div style="
            width:48px;
            height:48px;

            display:flex;
            align-items:center;
            justify-content:center;

            border-radius:15px;

            background:
                linear-gradient(
                    135deg,
                    rgba(6,182,212,.16),
                    rgba(6,182,212,.04)
                );

            border:1px solid rgba(6,182,212,.20);

            color:#22d3ee;

            box-shadow:
                0 8px 25px rgba(6,182,212,.08);
        ">

            <svg
                xmlns="http://www.w3.org/2000/svg"
                width="23"
                height="23"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.8"
            >
                <rect
                    x="4"
                    y="3"
                    width="16"
                    height="18"
                    rx="3"
                />

                <path d="M8 7h8"/>

                <path d="M8 11h.01"/>
                <path d="M12 11h.01"/>
                <path d="M16 11h.01"/>

                <path d="M8 15h.01"/>
                <path d="M12 15h.01"/>
                <path d="M16 15h.01"/>

                <path d="M8 18h.01"/>
                <path d="M12 18h4"/>
            </svg>

        </div>

    </div>



    <!-- ================================= -->
    <!-- HERO MONTHLY PAYMENT -->
    <!-- ================================= -->

    <div style="
        position:relative;

        padding:28px 30px;

        margin-bottom:20px;

        border-radius:22px;

        background:
            linear-gradient(
                135deg,
                rgba(6,182,212,.13),
                rgba(15,23,42,.45)
            );

        border:1px solid rgba(34,211,238,.20);

        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.05),
            0 15px 40px rgba(0,0,0,.14);

        overflow:hidden;
    ">


        <!-- Hero Glow -->

        <div style="
            position:absolute;

            width:220px;
            height:220px;

            border-radius:50%;

            top:-130px;
            right:-80px;

            background:rgba(34,211,238,.12);

            filter:blur(55px);

            pointer-events:none;
        "></div>


        <div style="
            position:relative;

            display:flex;

            align-items:flex-end;

            justify-content:space-between;

            gap:20px;

            flex-wrap:wrap;
        ">


            <div>

                <div style="
                    font-size:13px;
                    font-weight:600;

                    color:#94a3b8;

                    margin-bottom:10px;
                ">
                    القسط الشهري
                </div>


                <div style="
                    display:flex;
                    align-items:baseline;

                    gap:9px;
                ">

                    <span style="
                        font-size:44px;
                        line-height:1;

                        font-weight:900;

                        letter-spacing:-1.5px;

                        color:#22d3ee;

                        text-shadow:
                            0 0 30px rgba(34,211,238,.18);
                    ">
                        ' . number_format(round($monthly)) . '
                    </span>


                    <span style="
                        font-size:14px;
                        font-weight:600;

                        color:#fff;
                    ">
                        جنيه / شهر
                    </span>

                </div>

            </div>


            <!-- Months Badge -->

            <div style="
                display:flex;
                align-items:center;

                gap:9px;

                padding:10px 14px;

                border-radius:13px;

                background:rgba(255,255,255,.045);

                border:1px solid rgba(255,255,255,.07);

                color:#cbd5e1;

                font-size:12px;
                font-weight:600;
            ">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="16"
                    height="16"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.8"
                >
                    <rect
                        x="3"
                        y="4"
                        width="18"
                        height="17"
                        rx="3"
                    />

                    <path d="M8 2v4"/>
                    <path d="M16 2v4"/>
                    <path d="M3 9h18"/>
                </svg>

                لمدة ' . $months . ' شهر

            </div>

        </div>

    </div>



    <!-- ================================= -->
    <!-- FINANCING DETAILS -->
    <!-- ================================= -->

    <div style="
        position:relative;

        display:grid;

        grid-template-columns:
            repeat(auto-fit,minmax(190px,1fr));

        gap:12px;
    ">


        <!-- CASH PRICE -->

        <div style="
            padding:18px;

            border-radius:17px;

            background:rgba(255,255,255,.035);

            border:1px solid rgba(255,255,255,.07);

            transition:.2s;
        ">

            <div style="
                font-size:12px;
                color:#fff;

                margin-bottom:9px;
            ">
                سعر الكاش
            </div>

            <div style="
                font-size:19px;
                font-weight:800;

                color:#e2e8f0;
            ">

                ' . number_format($cashPrice) . '

                <span style="
                    font-size:11px;
                    color:#fff;
                    font-weight:500;
                ">
                    جنيه
                </span>

            </div>

        </div>





        <!-- DOWN PAYMENT -->

        <div style="
            padding:18px;

            border-radius:17px;

            background:rgba(255,255,255,.035);

            border:1px solid rgba(255,255,255,.07);
        ">

            <div style="
                font-size:12px;
                color:#fff;

                margin-bottom:9px;
            ">
                المقدم
            </div>

            <div style="
                font-size:19px;
                font-weight:800;

                color:#e2e8f0;
            ">

                ' . number_format($downPayment) . '

                <span style="
                    font-size:11px;
                    color:#fff;
                    font-weight:500;
                ">
                    جنيه
                </span>

            </div>

        </div>



        <!-- INTEREST -->

        <div style="
            padding:18px;

            border-radius:17px;

            background:rgba(255,255,255,.035);

            border:1px solid rgba(255,255,255,.07);
        ">

            <div style="
                font-size:12px;
                color:#fff;

                margin-bottom:9px;
            ">
                نسبة الفائدة
            </div>

            <div style="
                font-size:19px;
                font-weight:800;

                color:#e2e8f0;
            ">

                ' . $interest . '

                <span style="
                    font-size:12px;
                    color:#fff;
                ">
                    %
                </span>

            </div>

        </div>



        <!-- ADMIN FEES -->

        <div style="
            padding:18px;

            border-radius:17px;

            background:rgba(255,255,255,.035);

            border:1px solid rgba(255,255,255,.07);
        ">

            <div style="
                font-size:12px;
                color:#fff;

                margin-bottom:9px;
            ">
                المصاريف الإدارية
            </div>

            <div style="
                font-size:19px;
                font-weight:800;

                color:#e2e8f0;
            ">

                ' . number_format($adminFeesAmount) . '

                <span style="
                    font-size:11px;
                    color:#fff;
                    font-weight:500;
                ">
                    جنيه
                </span>

            </div>

        </div>



        <!-- TOTAL -->

        <div style="
            padding:18px;

            border-radius:17px;

            background:
                linear-gradient(
                    135deg,
                    rgba(99,102,241,.08),
                    rgba(255,255,255,.025)
                );

            border:1px solid rgba(129,140,248,.14);
        ">

            <div style="
                font-size:12px;
                color:#fff;

                margin-bottom:9px;
            ">
                إجمالي المبلغ بعد الفائدة
            </div>

            <div style="
                font-size:19px;
                font-weight:800;

                color:#c7d2fe;
            ">

                ' . number_format(round($totalWithInterest)) . '

                <span style="
                    font-size:11px;
                    color:#fff;
                    font-weight:500;
                ">
                    جنيه
                </span>

            </div>

        </div>

    </div>



    <!-- ================================= -->
    <!-- ADMIN FEES NOTICE -->
    <!-- ================================= -->

    <div style="
        position:relative;

        display:flex;

        align-items:center;

        gap:12px;

        margin-top:18px;

        padding:13px 15px;

        border-radius:15px;

        background:rgba(245,158,11,.055);

        border:1px solid rgba(245,158,11,.13);
    ">


        <div style="
            width:30px;
            height:30px;

            min-width:30px;

            display:flex;
            align-items:center;
            justify-content:center;

            border-radius:9px;

            background:rgba(245,158,11,.10);

            color:#f59e0b;

            font-size:13px;
            font-weight:800;
        ">
            !
        </div>


        <div style="
            font-size:12px;

            color:#a16207;

            line-height:1.7;
        ">

            المصاريف الإدارية بتدفع مرة واحدة عند استلام المكنة.

        </div>

    </div>

</div>

');
                    })
                    ->columnSpanFull(),

                Forms\Components\Hidden::make('machine_cash_price'),
                Forms\Components\Hidden::make('machine_installment_price'),
                Forms\Components\Hidden::make('interest_percent'),
                Forms\Components\Hidden::make('admin_fees_percent'),
                Forms\Components\Hidden::make('admin_fees_amount'),
                Forms\Components\Hidden::make('total_with_interest'),
                Forms\Components\Hidden::make('monthly_installment'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('brand.name')
                    ->label('الماركة')
                    ->searchable(),

                Tables\Columns\TextColumn::make('machine.name')
                    ->label('المكنة')
                    ->searchable(),

                Tables\Columns\TextColumn::make('installmentSystem.name')
                    ->label('نظام التقسيط'),

                Tables\Columns\TextColumn::make('months')
                    ->label('الشهور')
                    ->suffix(' شهر'),

                Tables\Columns\TextColumn::make('down_payment')
                    ->label('المقدم')
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('monthly_installment')
                    ->label('القسط الشهري')
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('admin_fees_amount')
                    ->label('المصاريف الإدارية')
                    ->money('EGP'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الحساب')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstallmentCalculators::route('/'),
            'create' => Pages\CreateInstallmentCalculator::route('/create'),
            'edit' => Pages\EditInstallmentCalculator::route('/{record}/edit'),
        ];
    }
}
