<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@200..1000&display=swap" rel="stylesheet">
<title>مبايعة موتوسيكل</title>

<style>
@page { size: A4 portrait; margin: 0; }

* {
    box-sizing: border-box;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    font-family: "Cairo", sans-serif;
}

html, body {
    margin: 0;
    padding: 0;
    width: 210mm;
    height: 297mm;
    background: #fff;
    color: #000;
}

.print-btn {
    position: fixed;
    top: 15px;
    left: 15px;
    z-index: 9999;
    background: #ff6a00;
    color: #fff;
    border: 0;
    padding: 10px 22px;
    border-radius: 10px;
    font-weight: bold;
    cursor: pointer;
}

.page {
    width: 210mm;
    height: 297mm;
    padding: 8mm 10mm;
    background: #fff;
    overflow: hidden;
}

.paper {
    height: 281mm;
    position: relative;
    overflow: hidden;
}

.paper::before {
    content: "";
    position: absolute;
    width: 70mm;
    height: 70mm;
    border-radius: 50%;
    background: rgba(255,106,0,.08);
    top: -28mm;
    left: -20mm;
}

.content { position: relative; z-index: 2; }

.logo {
    text-align: center;
    margin-top: 3mm;
}

.logo img {
    width: 270px;
    height: auto;
}

.title {
    text-align: center;
    font-size: 24px;
    font-weight: 900;
    margin-bottom: 5mm;
}

.print-date {
    text-align:center;
    margin-bottom:4mm;
    font-weight:700;
    font-size: 12px;
}

.section-title {
    background: #111 !important;
    color: #fff !important;
    border-radius: 8px;
    padding: 3mm 5mm;
    font-size: 16px;
    font-weight: 900;
    margin: 4mm 0 3mm;
    text-align:center;
}

.grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 3mm 5mm;
}

.field {
    border: 1px solid #d9d9d9;
    border-radius: 8px;
    padding: 2.5mm 4mm;
    min-height: 15mm;
    background: #fff !important;
}

.field.full { grid-column: 1 / -1; }

.label {
    font-size: 11px;
    color: #777 !important;
    font-weight: 700;
    margin-bottom: 1.5mm;
}

.value {
    font-size: 13.5px;
    font-weight: 900;
    line-height: 1.4;
}

.bottom {
    margin-top: 7mm;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 9mm;
    align-items: end;
}

.ack-title {
    color: #ff6a00 !important;
    font-size: 16px;
    font-weight: 900;
    margin-bottom: 2mm;
}

.ack {
    font-size: 12px;
    line-height: 1.9;
    font-weight: 700;
}

.signature {
    margin-top: 6mm;
    border-top: 1.5px solid #111;
    padding-top: 2mm;
    width: 48mm;
    font-weight: 900;
    font-size: 12px;
}

.stamp-box { text-align: center; }

.stamp-title {
    font-size: 14px;
    font-weight: 900;
    margin-bottom: 3mm;
}

.stamp {
    width: 52mm;
    height: 31mm;
    border: 1.5px dashed #999;
    border-radius: 8px;
    margin: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #777 !important;
    font-size: 11px;
}

.footer {
    position: absolute;
    bottom: 4mm;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 9px;
    color: #777 !important;
}

@media print {
    .print-btn { display: none !important; }
    body { background: #fff !important; }
    .page { page-break-after: avoid; }
}
</style>
</head>

<body>

@php
    $machineName =
        $letter->machine_name
        ?? $letter->product_name
        ?? $letter->item_name
        ?? $letter->motorcycle_name
        ?? $letter->bike_name
        ?? $letter->inventoryItem?->name
        ?? $letter->inventoryItem?->product_name
        ?? $letter->inventoryItem?->machine_name
        ?? $letter->inventoryItem?->product?->name
        ?? $letter->inventory_item?->name
        ?? $letter->inventory_item?->product_name
        ?? $letter->inventory_item?->machine_name
        ?? $letter->inventory_item?->product?->name
        ?? $letter->product?->name
        ?? $letter->machine?->name
        ?? $letter->inventory?->product?->name
        ?? $letter->inventory?->name
        ?? null;

    $cashValue = $letter->cash ?? $letter->cash_amount ?? null;
    $limitValue = $letter->limit ?? $letter->limit_amount ?? null;

    $paymentTypeText =
        $letter->payment_type
        ?? $letter->sale_type
        ?? $letter->type
        ?? null;

    if (!$paymentTypeText) {
        if (!empty($cashValue) && empty($limitValue)) {
            $paymentTypeText = 'كاش';
        } elseif (!empty($limitValue)) {
            $paymentTypeText = 'قسط';
        }
    }

    $adminExpenses =
        $letter->admin_fees
        ?? $letter->admin_expenses
        ?? $letter->administrative_expenses
        ?? $letter->expenses_admin
        ?? $letter->fees
        ?? null;

    $isCash = in_array($paymentTypeText, ['كاش', 'cash', 'Cash', 'CASH'], true);
    $isLimit = in_array($paymentTypeText, ['قسط', 'limit', 'Limit', 'LIMIT'], true);
@endphp

<button class="print-btn" onclick="window.print()">طباعة</button>

<div class="page">
    <div class="paper">
        <div class="content">

            <div class="logo">
                <img src="{{ asset('images/motogate-logo.png') }}">
            </div>

            <div class="title">مبايعة</div>

            <div class="print-date">
                التاريخ: {{ now()->format('Y-m-d') }} |
                الوقت: {{ now()->format('H:i') }}
            </div>

            <div class="section-title">بيانات العميل</div>

            <div class="grid">
                <div class="field">
                    <div class="label">اسم العميل</div>
                    <div class="value">{{ $letter->customer_name ?? $letter->applicant_name ?? '................' }}</div>
                </div>

                <div class="field">
                    <div class="label">رقم الهاتف</div>
                    <div class="value">{{ $letter->phone ?? $letter->applicant_phone ?? '................' }}</div>
                </div>

                <div class="field full">
                    <div class="label">العنوان</div>
                    <div class="value">{{ $letter->address ?? '................' }}</div>
                </div>

                <div class="field">
                    <div class="label">الرقم القومي</div>
                    <div class="value">{{ $letter->national_id ?? $letter->applicant_national_id ?? '................' }}</div>
                </div>

                <div class="field">
                    <div class="label">اللون</div>
                    <div class="value">{{ $letter->color ?? '................' }}</div>
                </div>
            </div>

            <div class="section-title">بيانات الموتوسيكل والماليات</div>

            <div class="grid">
                <div class="field">
                    <div class="label">النوع</div>
                    <div class="value">{{ $machineName ?: '................' }}</div>
                </div>

                <div class="field">
                    <div class="label">نوع البيع</div>
                    <div class="value">{{ $paymentTypeText ?: '................' }}</div>
                </div>

                <div class="field">
                    <div class="label">رقم الشاسيه</div>
                    <div class="value">{{ $letter->chassis_number ?? '................' }}</div>
                </div>

                <div class="field">
                    <div class="label">رقم الموتور</div>
                    <div class="value">{{ $letter->engine_number ?? '................' }}</div>
                </div>

                <div class="field">
                    <div class="label">المدفوع</div>
                    <div class="value">{{ $letter->paid_amount ?? $letter->paid ?? '................' }}</div>
                </div>

                <div class="field">
                    <div class="label">المتبقي</div>
                    <div class="value">{{ $letter->remaining_amount ?? $letter->remaining ?? '................' }}</div>
                </div>

                @if(($isCash || $isLimit) && !empty($adminExpenses))
                    <div class="field full">
                        <div class="label">المصاريف الإدارية</div>
                        <div class="value">{{ $adminExpenses }}</div>
                    </div>
                @endif
            </div>

            <div class="bottom">
                <div class="stamp-box">
                    <div class="stamp-title">ختم المعرض</div>
                    <div class="stamp">مكان الختم</div>
                </div>

                <div class="ack">
                    <div class="ack-title">أقر أنا:</div>
                    • الموقع أدناه بأني استلمت المنتج بحالته السليمة وعاينتها معاينة كافية ومسؤول عنه مسؤولية كاملة، والمعرض غير مسؤول عن عيوب الصناعة، في حالة ظهور أي عيب صناعة يتم الرجوع إلى التوكيل.
                    <div class="signature">توقيع العميل</div>
                </div>
            </div>

        </div>

        <div class="footer">تم إنشاء هذه المبايعة من نظام MotoGate</div>
    </div>
</div>

<script>
    window.onload = () => {
        // window.print();
    };
</script>

</body>
</html>
