<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TreasuryExpenseResource\Pages;
use App\Models\Letter;
use App\Models\TreasuryExpense;
use App\Models\Staff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class TreasuryExpenseResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use \App\Filament\Concerns\HasFullAccess;
    protected static ?string $model = TreasuryExpense::class;

    protected static ?string $navigationLabel = 'الخزنة';
    protected static ?string $modelLabel = 'صرف';
    protected static ?string $pluralModelLabel = 'مصروفات الخزنة';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'إدارة المعرض';
    protected static ?int $navigationSort = 3;

    protected static function isSuperAdmin($user = null): bool
    {
        return $user ? \App\Support\Access::full(static::class, $user) : static::fullAccess();
    }

    protected static function isItemLockedForCurrentUser($itemRecord = null): bool
    {
        $createdBy = is_array($itemRecord)
            ? ($itemRecord['created_by'] ?? null)
            : ($itemRecord?->created_by ?? null);

        if (! $createdBy || static::isSuperAdmin()) {
            return false;
        }

        $creator = Staff::find($createdBy);

        return $creator ? \App\Support\Access::full(static::class, $creator) : false;
    }

    protected static function canCurrentUserControlItem($itemRecord = null): bool
    {
        if (static::isSuperAdmin()) {
            return true;
        }

        if (static::isItemLockedForCurrentUser($itemRecord)) {
            return false;
        }

        $createdBy = is_array($itemRecord)
            ? ($itemRecord['created_by'] ?? null)
            : ($itemRecord?->created_by ?? null);

        return ! $createdBy || (int) $createdBy === (int) Auth::id();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return \App\Support\Access::allows(static::class, 'view');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        $query = parent::getEloquentQuery()->with(['warehouse', 'staff']);

        if (! static::isSuperAdmin($user)) {
            $query->where('warehouse_id', $user?->warehouse_id);
        }

        return $query;
    }

    protected static function moneyFormat(float $amount): string
    {
        return number_format($amount, 2) . ' ج';
    }

    protected static function dateFormat($date): string
    {
        if (! $date) {
            return '-';
        }

        try {
            return $date instanceof Carbon
                ? $date->format('Y-m-d')
                : Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable $e) {
            return (string) $date;
        }
    }

    protected static function dateTimeFormat($date): string
    {
        if (! $date) {
            return '-';
        }

        try {
            return $date instanceof Carbon
                ? $date->format('Y-m-d h:i A')
                : Carbon::parse($date)->format('Y-m-d h:i A');
        } catch (\Throwable $e) {
            return (string) $date;
        }
    }

    protected static function monthStart(?Carbon $month = null): Carbon
    {
        return ($month ?? now())->copy()->startOfMonth();
    }

    protected static function monthEnd(?Carbon $month = null): Carbon
    {
        return ($month ?? now())->copy()->endOfMonth();
    }

    protected static function monthTitle(Carbon $month): string
    {
        return 'شهر ' . $month->format('m') . ' / ' . $month->format('Y');
    }

    protected static function treasuryCss(): string
    {
        return '
            <style>
                .treasury-wrap{direction:rtl;display:flex;flex-direction:column;gap:18px}
                .treasury-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}
                .treasury-card{border-radius:18px;padding:18px;border:1px solid #dbe3ef;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,.07)}
                .treasury-card-title{font-size:14px;font-weight:900;margin-bottom:8px}
                .treasury-card-value{font-size:22px;font-weight:1000;line-height:1.5}
                .treasury-income{background:#ecfdf5;border-color:#bbf7d0}.treasury-income .treasury-card-title{color:#166534}.treasury-income .treasury-card-value{color:#15803d}
                .treasury-remain{background:#fff7ed;border-color:#fed7aa}.treasury-remain .treasury-card-title{color:#9a3412}.treasury-remain .treasury-card-value{color:#c2410c}
                .treasury-expense{background:#fef2f2;border-color:#fecaca}.treasury-expense .treasury-card-title{color:#991b1b}.treasury-expense .treasury-card-value{color:#dc2626}
                .treasury-balance{background:#f8fafc;border-color:#cbd5e1}.treasury-balance .treasury-card-title{color:#334155}

                .treasury-collapse{border:1px solid rgba(148,163,184,.25);border-radius:22px;overflow:hidden;background:#0f172a;margin-bottom:12px}
                .treasury-collapse summary{cursor:pointer;list-style:none;padding:18px;color:#fff;font-size:18px;font-weight:1000;background:#111827;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
                .treasury-collapse summary::-webkit-details-marker{display:none}
                .treasury-collapse-body{padding:16px}
                .treasury-summary-total{background:#020617;border:1px solid rgba(148,163,184,.25);border-radius:14px;padding:10px 14px;color:#fff;font-size:14px;font-weight:1000}

                .treasury-month-head{background:#14233b;border:1px solid rgba(148,163,184,.28);border-radius:22px;padding:16px 18px;color:#fff;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}
                .treasury-month-title{font-size:21px;font-weight:1000;color:#fff}
                .treasury-month-note{font-size:13px;font-weight:900;color:#94a3b8;margin-top:5px}

                .treasury-table-box{background:#fff;border:1px solid #e2e8f0;border-radius:24px;overflow:hidden;box-shadow:0 16px 34px rgba(15,23,42,.08)}
                .treasury-table-title{padding:18px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:18px;font-weight:1000;color:#0f172a}
                .treasury-scroll{overflow-x:auto}
                .treasury-table{width:100%;border-collapse:collapse;min-width:950px}
                .treasury-table thead tr{background:#eef2f7;color:#334155}
                .treasury-table th{padding:14px;text-align:right;font-size:13px;font-weight:1000}
                .treasury-table td{padding:14px;border-bottom:1px solid #e2e8f0;color:#334155;font-weight:800}
                .treasury-table .green{color:#16a34a;font-weight:1000}
                .treasury-table .red{color:#dc2626;font-weight:1000}
                .treasury-pill{padding:6px 13px;border-radius:999px;font-size:13px;font-weight:1000;display:inline-flex}

                .treasury-day{background:#fff;border:1px solid #dbe3ef;border-radius:28px;padding:22px;margin-bottom:18px;box-shadow:0 16px 34px rgba(15,23,42,.08)}
                .treasury-day-head{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
                .treasury-day-title{color:#0f172a;font-size:23px;font-weight:1000}
                .treasury-day-user{color:#64748b;font-size:14px;font-weight:900;margin-top:7px}
                .treasury-edit-btn{text-decoration:none;background:#f59e0b;color:#111827;border-radius:14px;padding:10px 15px;font-size:13px;font-weight:1000}
                .treasury-day-total{background:#f8fafc;color:#f59e0b;border:1px solid #cbd5e1;border-radius:18px;padding:12px 18px;font-size:20px;font-weight:1000}
                .treasury-item{background:#f8fafc;border:1px solid #dbe3ef;border-radius:22px;padding:18px;margin-top:14px}
                .treasury-muted{color:#64748b;font-size:13px;font-weight:900;margin-bottom:7px}
                .treasury-item-name{color:#0f172a;font-size:20px;font-weight:1000}
                .treasury-item-amount{color:#f59e0b;font-size:24px;font-weight:1000}
                .treasury-chip{background:#fff;color:#334155;border:1px solid #cbd5e1;padding:8px 13px;border-radius:999px;font-size:12px;font-weight:900;display:inline-flex}
                .return-btn{

background:#dc2626;

color:white;

border:none;

padding:8px 14px;

border-radius:10px;

cursor:pointer;

font-weight:bold;

}

.return-btn:hover{

background:#b91c1c;

}
                html.dark .treasury-table-box{background:#0f172a;border-color:#334155}
                html.dark .treasury-table-title{background:#111827;border-color:#334155;color:#f8fafc}
                html.dark .treasury-table thead tr{background:#1e293b;color:#cbd5e1}
                html.dark .treasury-table td{border-color:rgba(148,163,184,.22);color:#cbd5e1}
                html.dark .treasury-day{background:linear-gradient(135deg,#0f172a,#111827);border-color:rgba(148,163,184,.30)}
                html.dark .treasury-day-title{color:#fff}
                html.dark .treasury-day-user{color:#94a3b8}
                html.dark .treasury-day-total{background:rgba(30,41,59,.95);border-color:rgba(148,163,184,.35);color:#fbbf24}
                html.dark .treasury-item{background:linear-gradient(135deg,rgba(15,23,42,.96),rgba(30,41,59,.96));border-color:rgba(148,163,184,.26)}
                html.dark .treasury-muted{color:#94a3b8}
                html.dark .treasury-item-name{color:#fff}
                html.dark .treasury-item-amount{color:#fbbf24}
                html.dark .treasury-chip{background:rgba(30,41,59,.95);color:#e2e8f0;border-color:rgba(148,163,184,.35)}
            </style>
        ';
    }

    protected static function lettersQueryForMonth(int $warehouseId, Carbon $month): Builder
    {
        return Letter::query()
            ->whereHas('inventory', fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->whereBetween('created_at', [
                static::monthStart($month),
                static::monthEnd($month),
            ]);
    }

    protected static function expensesQueryForMonth(int $warehouseId, Carbon $month): Builder
    {
        return TreasuryExpense::query()
            ->where('warehouse_id', $warehouseId)
            ->whereBetween('expense_date', [
                static::monthStart($month)->toDateString(),
                static::monthEnd($month)->toDateString(),
            ]);
    }

    protected static function getWarehouseIncome(int $warehouseId, ?Carbon $month = null): float
    {
        $month ??= now();

        return static::lettersQueryForMonth($warehouseId, $month)
            ->get()
            ->sum(function (Letter $letter) {
                $paid = (float) ($letter->paid ?? 0);
                $adminFees = (float) ($letter->admin_fees ?? 0);
                $cash = (float) ($letter->cash ?? 0);

                return $cash > 0 ? $paid + $adminFees : $paid;
            });
    }

    protected static function getWarehouseRemaining(int $warehouseId, ?Carbon $month = null): float
    {
        $month ??= now();

        return (float) static::lettersQueryForMonth($warehouseId, $month)->sum('remaining');
    }

    protected static function getWarehouseExpenses(int $warehouseId, ?Carbon $month = null): float
    {
        $month ??= now();

        return (float) static::expensesQueryForMonth($warehouseId, $month)->sum('total_amount');
    }

    protected static function getWarehouseItemsCount(int $warehouseId, ?Carbon $month = null): int
    {
        $month ??= now();

        return (int) static::expensesQueryForMonth($warehouseId, $month)
            ->withCount('items')
            ->get()
            ->sum('items_count');
    }

    protected static function getMonthSummaryHtml(int $warehouseId, Carbon $month): string
    {
        $incomeTotal = static::getWarehouseIncome($warehouseId, $month);
        $remainingTotal = static::getWarehouseRemaining($warehouseId, $month);
        $expensesTotal = static::getWarehouseExpenses($warehouseId, $month);
        $balance = $incomeTotal - $expensesTotal;
        $balanceColor = $balance < 0 ? '#ef4444' : '#22c55e';

        return '
            <div class="treasury-month-head">
                <div>
                    <div class="treasury-month-title">بيانات ' . e(static::monthTitle($month)) . '</div>
                    <div class="treasury-month-note">كل المدخولات والصرف والتفاصيل الخاصة بالشهر ده فقط</div>
                </div>
            </div>

            <div class="treasury-grid">
                <div class="treasury-card treasury-income">
                    <div class="treasury-card-title">مدخولات ' . e(static::monthTitle($month)) . '</div>
                    <div class="treasury-card-value">' . static::moneyFormat($incomeTotal) . '</div>
                </div>

                <div class="treasury-card treasury-remain">
                    <div class="treasury-card-title">متبقي ' . e(static::monthTitle($month)) . '</div>
                    <div class="treasury-card-value">' . static::moneyFormat($remainingTotal) . '</div>
                </div>

                <div class="treasury-card treasury-expense">
                    <div class="treasury-card-title">صرف ' . e(static::monthTitle($month)) . '</div>
                    <div class="treasury-card-value">' . static::moneyFormat($expensesTotal) . '</div>
                </div>

                <div class="treasury-card treasury-balance">
                    <div class="treasury-card-title">رصيد ' . e(static::monthTitle($month)) . '</div>
                    <div class="treasury-card-value" style="color:' . $balanceColor . ';">' . static::moneyFormat($balance) . '</div>
                </div>
            </div>
        ';
    }

    protected static function getBalanceDetailsHtml(int $warehouseId, ?Carbon $month = null): string
    {
        $month ??= now();

        $letters = static::lettersQueryForMonth($warehouseId, $month)
            ->with(['inventory.product'])
            ->latest()
            ->get();

        $rows = '';
$isSuperAdmin = static::isSuperAdmin();

$returnOnSubmit = $isSuperAdmin
    ? "return confirm('هل أنت متأكد من عمل مرتجع؟');"
    : "if (confirm('هل أنت متأكد من عمل مرتجع؟')) { alert('لا يمكن تنفيذ المرتجع. السوبر أدمن فقط هو المسموح له بذلك.'); } return false;";
        foreach ($letters as $letter) {
            $paid = (float) ($letter->paid ?? 0);
            $adminFees = (float) ($letter->admin_fees ?? 0);
            $cash = (float) ($letter->cash ?? 0);
            $remaining = (float) ($letter->remaining ?? 0);
            $income = $cash > 0 ? $paid + $adminFees : $paid;
            $type = $cash > 0 ? 'كاش' : 'قسط';

            $typeStyle = $cash > 0
                ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;'
                : 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;';

            $calc = $cash > 0
                ? static::moneyFormat($paid) . ' + ' . static::moneyFormat($adminFees)
                : static::moneyFormat($paid);

            $rows .= '
                <tr>
                    <td>' . e($letter->inventory?->product?->name ?? '-') . '</td>
                    <td>' . e($letter->customer_name ?? '-') . '</td>
                    <td><span class="treasury-pill" style="' . $typeStyle . '">' . $type . '</span></td>
                    <td>' . static::moneyFormat($paid) . '</td>
                    <td>' . static::moneyFormat($adminFees) . '</td>
                    <td>' . $calc . '</td>
                    <td class="green">' . static::moneyFormat($income) . '</td>
                    <td class="red">' . static::moneyFormat($remaining) . '</td>
                   <td>

<form method="POST"
      action="' . route('letters.return', $letter) . '"
      onsubmit="' . $returnOnSubmit . '">

    ' . csrf_field() . '

    <button
        type="submit"
        style="
            background:#dc2626;
            color:#fff;
            padding:8px 16px;
            border:none;
            border-radius:10px;
            cursor:pointer;
            font-weight:bold;
        "
    >
        مرتجع
    </button>
</form>
</td>
                </tr>
            ';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="8" style="padding:22px;text-align:center;color:#94a3b8;font-weight:900;">لا توجد مدخولات في هذا الشهر</td></tr>';
        }

        return '
            <div class="treasury-table-box">
                <div class="treasury-table-title">تفصيل المدخولات من الجوابات - ' . e(static::monthTitle($month)) . '</div>
                <div class="treasury-scroll">
                    <table class="treasury-table">
                        <thead>
                            <tr>
                                <th>المكنة</th>
                                <th>العميل</th>
                                <th>النوع</th>
                                <th>المدفوع</th>
                                <th>مصاريف إدارية</th>
                                <th>الحسبة</th>
                                <th>داخل الخزنة</th>
                                <th>المتبقي</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>' . $rows . '</tbody>
                    </table>
                </div>
            </div>
        ';
    }

    protected static function renderExpenseDaysHtml(int $warehouseId, Carbon $month): string
    {
        $expenses = static::expensesQueryForMonth($warehouseId, $month)
            ->with(['items', 'staff'])
            ->whereHas('items')
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get();
            
        $creatorIds = $expenses->flatMap(fn ($expense) => $expense->items->pluck('created_by'))->filter()->unique()->values();
        $creators = Staff::query()->whereIn('id', $creatorIds)->get()->keyBy('id');

        $html = '';

        foreach ($expenses as $expense) {
                if ($expense->items->isEmpty()) {
                  continue;
                }
            $itemsHtml = '';

            foreach ($expense->items as $item) {
            
         
                $creator = $creators->get($item->created_by);
                $creatorName = e($creator?->name ?? $expense->staff?->name ?? '-');
                $createdAt = static::dateTimeFormat($item->created_at);
                $canControl = static::canCurrentUserControlItem($item);
                $editUrl = static::getUrl('edit', ['record' => $expense->getKey()]);

                $buttons = $canControl
                    ? '<a href="' . e($editUrl) . '" class="treasury-edit-btn">تعديل</a>'
                    : '<span style="background:rgba(239,68,68,.14);color:#ef4444;border:1px solid rgba(239,68,68,.35);padding:8px 13px;border-radius:999px;font-size:12px;font-weight:900;">مقفول بواسطة الحاوي</span>';

                $badge = $canControl
                    ? '<span style="background:rgba(34,197,94,.14);color:#22c55e;border:1px solid rgba(34,197,94,.35);padding:8px 13px;border-radius:999px;font-size:12px;font-weight:900;">قابل للتعديل والمسح</span>'
                    : '';

                $itemsHtml .= '
                    <div class="treasury-item">
                        <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center;">
                            <div>
                                <div class="treasury-muted">بند الصرف</div>
                                <div class="treasury-item-name">' . e($item->expense_item) . '</div>
                            </div>

                            <div style="text-align:left;">
                                <div class="treasury-muted">المبلغ</div>
                                <div class="treasury-item-amount">' . static::moneyFormat((float) $item->amount) . '</div>
                            </div>
                        </div>

                        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;align-items:center;">
                            <span class="treasury-chip">أضافه: ' . $creatorName . '</span>
                            <span class="treasury-chip">الوقت: ' . $createdAt . '</span>
                            ' . $badge . '
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-right:auto;">' . $buttons . '</div>
                        </div>
                    </div>
                ';
            }

            $html .= '
                <div class="treasury-day">
                    <div class="treasury-day-head">
                        <div>
                            <div class="treasury-day-title">يوم ' . e(static::dateFormat($expense->expense_date)) . '</div>
                            <div class="treasury-day-user">المستخدم: ' . e($expense->staff?->name ?? '-') . '</div>
                        </div>

                        <div class="treasury-day-total">' . static::moneyFormat((float) $expense->total_amount) . '</div>
                    </div>

                    ' . $itemsHtml . '
                </div>
            ';
        }

        return $html ?: '<div class="treasury-day" style="text-align:center;color:#94a3b8;font-weight:900;">لا توجد مصروفات في هذا الشهر</div>';
    }

    protected static function getWarehouseExpenseHistoryHtml(int $warehouseId): HtmlString
    {
        $months = TreasuryExpense::query()
            ->where('warehouse_id', $warehouseId)
            ->selectRaw('YEAR(expense_date) as year_number, MONTH(expense_date) as month_number')
            ->groupBy('year_number', 'month_number')
            ->orderByDesc('year_number')
            ->orderByDesc('month_number')
            ->get();

        $html = '';

        foreach ($months as $monthRow) {
            $month = Carbon::create((int) $monthRow->year_number, (int) $monthRow->month_number, 1)->startOfMonth();

            $content = static::getMonthSummaryHtml($warehouseId, $month)
                . static::getBalanceDetailsHtml($warehouseId, $month)
                . static::renderExpenseDaysHtml($warehouseId, $month);

            $html .= '
                <details class="treasury-collapse">
                    <summary>
                        <span>أرشيف ' . e(static::monthTitle($month)) . '</span>
                        <span class="treasury-summary-total">' . static::moneyFormat(static::getWarehouseExpenses($warehouseId, $month)) . '</span>
                    </summary>

                    <div class="treasury-collapse-body">
                        <div class="treasury-wrap">
                            ' . $content . '
                        </div>
                    </div>
                </details>
            ';
        }

        if ($html === '') {
            $html = '<div class="treasury-day" style="text-align:center;color:#94a3b8;font-weight:900;">لا توجد مصروفات لهذا المخزن</div>';
        }

        return new HtmlString(static::treasuryCss() . '<div class="treasury-wrap">' . $html . '</div>');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('staff_id')
                ->default(fn () => Auth::id()),

            Forms\Components\Select::make('warehouse_id')
                ->label('المخزن')
                ->relationship('warehouse', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->default(fn () => Auth::user()?->warehouse_id)
                ->disabled(fn () => ! static::isSuperAdmin())
                ->dehydrated(true),

            Forms\Components\DatePicker::make('expense_date')
                ->label('تاريخ الصرف')
                ->default(now())
                ->required(),

            Forms\Components\Section::make('أرشيف الشهور وتفاصيل الصرف')
                ->schema([
                    Forms\Components\Placeholder::make('expense_history')
                        ->label('')
                        ->content(fn (?TreasuryExpense $record) => $record?->warehouse_id
                            ? static::getWarehouseExpenseHistoryHtml((int) $record->warehouse_id)
                            : '-')
                        ->columnSpanFull(),
                ])
                ->visible(fn (?TreasuryExpense $record) => filled($record))
                ->columnSpanFull(),

            Forms\Components\Repeater::make('items')
                ->relationship()
                ->label('بنود الصرف الخاصة بالعملية الحالية')
                ->schema([
                    Forms\Components\Hidden::make('created_by')
                        ->default(fn () => Auth::id())
                        ->dehydrated(true),

                    Forms\Components\Placeholder::make('created_at_display')
                        ->label('وقت إضافة البند')
                        ->content(fn ($record) => $record?->created_at
                            ? static::dateTimeFormat($record->created_at)
                            : 'هيظهر بعد الحفظ')
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('locked_notice')
                        ->label('')
                        ->content(new HtmlString('
                            <div style="background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.35);color:#ef4444;padding:10px 14px;border-radius:14px;font-weight:900;">
                                لا يمكن تعديله أو حذفه إلا بواسطة الحاوي
                            </div>
                        '))
                        ->visible(fn ($record) => static::isItemLockedForCurrentUser($record))
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('expense_item')
                        ->label('بند الصرف')
                        ->required()
                        ->disabled(fn ($record) => ! static::canCurrentUserControlItem($record))
                        ->dehydrated(true),

                    Forms\Components\TextInput::make('amount')
                        ->label('المبلغ')
                        ->numeric()
                        ->prefix('ج')
                        ->required()
                        ->minValue(1)
                        ->disabled(fn ($record) => ! static::canCurrentUserControlItem($record))
                        ->dehydrated(true),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->reorderable(false)
                ->collapsible()
                ->cloneable(false)
                ->addActionLabel('إضافة بند')
                ->deleteAction(
                    fn (Action $action) => $action
                        ->requiresConfirmation()
                        ->hidden(function (array $arguments, Forms\Components\Repeater $component): bool {
                            $itemKey = $arguments['item'] ?? null;

                            if (! $itemKey) {
                                return false;
                            }

                            try {
                                $state = $component->getItemState($itemKey);
                            } catch (\Throwable $e) {
                                return false;
                            }

                            return ! static::canCurrentUserControlItem($state);
                        })
                )
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $user = Auth::user();

                // One row per showroom: its latest day. env limited this to the current month, so
                // the page went empty at the start of every month until someone added an expense.
                $latestIds = TreasuryExpense::query()
                    ->selectRaw('MAX(id) as id')
                    ->groupBy('warehouse_id');

                if (! static::isSuperAdmin($user)) {
                    $latestIds->where('warehouse_id', $user?->warehouse_id);
                }

                return $query->whereIn('id', $latestIds);
            })
            ->columns([
                Tables\Columns\TextColumn::make('warehouse.name')
                    ->label('المخزن')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('month_title')
                    ->label('الشهر')
                    ->state(fn () => static::monthTitle(now()))
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('current_balance')
                    ->label('رصيد الشهر الحالي')
                    ->state(fn (TreasuryExpense $record) => static::moneyFormat(
                        static::getWarehouseIncome((int) $record->warehouse_id, now())
                        - static::getWarehouseExpenses((int) $record->warehouse_id, now())
                    ))
                    ->color(function (TreasuryExpense $record) {
                        $balance = static::getWarehouseIncome((int) $record->warehouse_id, now())
                            - static::getWarehouseExpenses((int) $record->warehouse_id, now());

                        return $balance < 0 ? 'danger' : 'success';
                    })
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('remaining_total')
                    ->label('متبقي الشهر')
                    ->state(fn (TreasuryExpense $record) => static::moneyFormat(
                        static::getWarehouseRemaining((int) $record->warehouse_id, now())
                    ))
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('income_total')
                    ->label('مدخولات الشهر')
                    ->state(fn (TreasuryExpense $record) => static::moneyFormat(
                        static::getWarehouseIncome((int) $record->warehouse_id, now())
                    ))
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('expenses_total')
                    ->label('صرف الشهر')
                    ->state(fn (TreasuryExpense $record) => static::moneyFormat(
                        static::getWarehouseExpenses((int) $record->warehouse_id, now())
                    ))
                    ->color('danger')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('عدد بنود الشهر')
                    ->state(fn (TreasuryExpense $record) => static::getWarehouseItemsCount((int) $record->warehouse_id, now()))
                    ->badge(),

                Tables\Columns\TextColumn::make('staff.name')
                    ->label('آخر مستخدم')
                    ->searchable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('عرض التفاصيل'),

                Tables\Actions\EditAction::make()
                    ->label('تعديل السجل المعروض'),

                TableAction::make('edit_today')
                    ->label('إضافة / تعديل اليوم')
                    ->icon('heroicon-o-plus-circle')
                    ->color('warning')
                    ->url(function (TreasuryExpense $record) {
                        $todayRecord = TreasuryExpense::firstOrCreate(
                            [
                                'warehouse_id' => $record->warehouse_id,
                                'expense_date' => now()->toDateString(),
                            ],
                            [
                                'staff_id' => Auth::id(),
                                'total_amount' => 0,
                            ]
                        );

                        return static::getUrl('edit', ['record' => $todayRecord->getKey()]);
                    }),
            ]);
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();

        $data['staff_id'] = Auth::id();

        if (! static::isSuperAdmin($user)) {
            $data['warehouse_id'] = $user?->warehouse_id;
        }

        $data['total_amount'] = 0;

        return $data;
    }

    public static function mutateFormDataBeforeSave(array $data): array
    {
        $user = Auth::user();

        if (! static::isSuperAdmin($user)) {
            $data['warehouse_id'] = $user?->warehouse_id;
        }

        unset($data['items']);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTreasuryExpenses::route('/'),
            'create' => Pages\CreateTreasuryExpense::route('/create'),
            'view' => Pages\ViewTreasuryExpense::route('/{record}'),
            'edit' => Pages\EditTreasuryExpense::route('/{record}/edit'),
        ];
    }
}
