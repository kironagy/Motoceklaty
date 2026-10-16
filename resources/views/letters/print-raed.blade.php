<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ملف العميل والموتوسيكل #{{ $letter->id }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap');
        @page { size: A4; margin: 6mm; }
        :root { --ink: #111111; --muted: #626262; --line: #dedede; --brand: #f47b20; --soft-orange: #fff2e9; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #edf1f5; color: var(--ink); font-family: 'Cairo', Tahoma, Arial, sans-serif; }
        .sheet { width: 100%; max-width: 210mm; min-height: 285mm; padding: 6mm; margin: 0 auto; background: #fff; }
        .top { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0 0 8px; border-bottom: 3px solid var(--brand); }
        .brand { display: flex; align-items: center; gap: 13px; } .brand img { width: 145px; max-height: 64px; object-fit: contain; object-position: right center; }
        .brand-copy h1 { margin: 0 0 3px; font-size: 17px; } .brand-copy p { margin: 0; color: var(--muted); font-size: 9px; }
        .record { color: var(--muted); text-align: left; font-size: 9px; line-height: 1.45; }
        .print { margin-top: 4px; border: 0; border-radius: 5px; padding: 5px 10px; background: var(--brand); color: #111; cursor: pointer; font: inherit; font-size: 10px; font-weight: 800; }
        .section { margin-top: 12px; } .section-title { display: flex; align-items: center; gap: 6px; margin: 0 0 6px; color: #111; font-size: 12px; }
        .section-title::before { content: ''; width: 3px; height: 15px; background: var(--brand); border-radius: 99px; }
        .details { display: grid; grid-template-columns: repeat(3, 1fr); border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
        .detail { padding: 6px 8px; min-height: 42px; border-left: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .detail:nth-child(3n) { border-left: 0; } .detail:nth-last-child(-n+3) { border-bottom: 0; }
        .detail b { display: block; color: var(--muted); margin-bottom: 2px; font-size: 8px; font-weight: normal; } .detail span { display: block; font-size: 10px; overflow-wrap: anywhere; }
        .detail.wide { grid-column: span 3; border-left: 0; } .photos { display: grid; grid-template-columns: repeat(2, 1fr); gap: 7px; }
        .photo-card { overflow: hidden; border: 1px solid var(--line); border-radius: 8px; background: #fbfcfd; break-inside: avoid; } .photo-card.id-card { grid-column: span 2; }
        .photo-label { display: flex; justify-content: space-between; padding: 5px 8px; background: var(--soft-orange); color: #111; font-size: 9px; font-weight: bold; border-bottom: 2px solid var(--brand); } .photo-label small { color: var(--muted); font-weight: normal; }
        .photo-frame { height: 200px; padding: 5px; background: #fff; } .id-card .photo-frame { height: 190px; }
        .photo-frame img { display: block; width: 100%; height: 100%; object-fit: contain; object-position: center; }
        .empty-photo { display: grid; width: 100%; height: 100%; place-items: center; color: #98a2b3; border: 1px dashed var(--line); border-radius: 5px; font-size: 12px; }
        footer { display: flex; justify-content: space-between; gap: 10px; margin-top: 12px; padding-top: 5px; border-top: 1px solid var(--line); color: var(--muted); font-size: 8px; }
        @media print { body { background: #fff; } .sheet { max-width: none; min-height: 0; padding: 0; } .print { display: none; } .section { break-inside: avoid; page-break-inside: avoid; } }
        @media (max-width: 620px) { .top { align-items: flex-start; } .record { display: none; } .details, .photos { grid-template-columns: 1fr; } .detail, .detail:nth-child(3n), .detail:nth-last-child(-n+3) { border-left: 0; border-bottom: 1px solid var(--line); } .detail:last-child { border-bottom: 0; } .detail.wide, .photo-card.id-card { grid-column: span 1; } }
    </style>
</head>
<body>
    <main class="sheet">
        <header class="top">
            <div class="brand">
                <img src="{{ asset('images/motogate-logo.png') }}" alt="Moto Gate">
                <div class="brand-copy"><h1>ملف تسليم الموتوسيكل</h1><p>بيانات المكنة والعميل — للاستخدام الداخلي</p></div>
            </div>
            <div class="record"><div>رقم الجواب: <strong>#{{ $letter->id }}</strong></div><div>{{ now()->format('Y-m-d h:i A') }}</div><button class="print" onclick="window.print()">طباعة الملف</button></div>
        </header>

        <section class="section"><h2 class="section-title">بيانات الموتوسيكل</h2><div class="details">
            <div class="detail"><b>اسم المكنة</b><span>{{ data_get($letter, 'inventory.product.name', '—') }}</span></div><div class="detail"><b>المعرض / المخزن</b><span>{{ data_get($letter, 'inventory.warehouse.name', '—') }}</span></div><div class="detail"><b>اللون</b><span>{{ $letter->color ?: '—' }}</span></div>
            <div class="detail"><b>رقم الشاسيه</b><span>{{ $letter->chassis_number ?: '—' }}</span></div><div class="detail"><b>رقم الماتور</b><span>{{ $letter->engine_number ?: '—' }}</span></div><div class="detail"><b>حالة الجواب</b><span>{{ $letter->status ?: '—' }}</span></div>
        </div></section>

        <section class="section"><h2 class="section-title">صور إثبات المكنة</h2><div class="photos">
            @foreach ([['صورة الشاسيه', $letter->chassis_image], ['صورة الماتور', $letter->engine_image]] as [$label, $path])
                <article class="photo-card"><div class="photo-label"><span>{{ $label }}</span><small>صورة أصلية</small></div><div class="photo-frame">@if ($path)<img src="{{ \Illuminate\Support\Facades\Storage::url($path) }}" alt="{{ $label }}">@else<div class="empty-photo">لا توجد صورة مرفوعة</div>@endif</div></article>
            @endforeach
        </div></section>

        <section class="section"><h2 class="section-title">بيانات العميل</h2><div class="details">
            <div class="detail"><b>اسم العميل</b><span>{{ $letter->customer_name ?: '—' }}</span></div><div class="detail"><b>رقم الهاتف</b><span>{{ $letter->phone ?: '—' }}</span></div><div class="detail"><b>الرقم القومي</b><span>{{ $letter->national_id ?: '—' }}</span></div><div class="detail wide"><b>العنوان</b><span>{{ $letter->address ?: '—' }}</span></div>
        </div></section>

        <section class="section"><h2 class="section-title">صورة بطاقة العميل</h2><div class="photos"><article class="photo-card id-card"><div class="photo-label"><span>بطاقة الرقم القومي</span><small>صورة مرفوعة من ملف العميل</small></div><div class="photo-frame">@if ($letter->customer_id_image)<img src="{{ \Illuminate\Support\Facades\Storage::url($letter->customer_id_image) }}" alt="صورة بطاقة العميل">@else<div class="empty-photo">لا توجد صورة بطاقة مرفوعة</div>@endif</div></article></div></section>
        <footer><span>Motogate — ملف رائد</span><span>تم الإنشاء من النظام</span></footer>
    </main>
</body>
</html>