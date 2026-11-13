{{-- Owner 2026-11-14: Automoon linking - polls until the phone scans the QR. --}}
<div wire:poll.4s style="text-align:center;padding:16px;">
    @if ($link['connected'])
        <div style="font-size:48px;">✅</div>
        <p style="font-weight:bold;font-size:18px;margin-top:8px;">الرقم متصل{{ $link['phone'] ? ' - '.$link['phone'] : '' }}</p>
        <p style="color:#6b7280;margin-top:4px;">تقدر تقفل النافذة دي.</p>
    @elseif ($link['qr'])
        <img src="{{ $link['qr'] }}" alt="QR" style="width:300px;height:300px;margin:0 auto;border-radius:16px;border:1px solid #ddd;padding:8px;background:white;" />
        <p style="margin-top:12px;font-weight:bold;">افتح واتساب على الموبايل ← الأجهزة المرتبطة ← ربط جهاز، وامسح الكود</p>
        <p style="color:#6b7280;margin-top:4px;font-size:13px;">الكود بيتجدد لوحده كل كام ثانية.</p>
    @else
        <p style="font-weight:bold;">جاري تجهيز كود الربط... ({{ $link['state'] ?: 'انتظر' }})</p>
        <p style="color:#6b7280;margin-top:4px;font-size:13px;">الكود هيظهر هنا لوحده خلال ثواني.</p>
    @endif
</div>
