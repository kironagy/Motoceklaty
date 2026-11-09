<x-filament-widgets::widget>
    @include('filament.resources.whatsapp-bots.list-styles')
    <style>
        .wbo { direction: rtl; display: grid; grid-template-columns: 1.4fr repeat(3, minmax(0, 1fr)); gap: 14px; }
        @media (max-width: 1100px) { .wbo { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 560px) { .wbo { grid-template-columns: 1fr; } }
        .wbo-box { --c: #6366f1; position: relative; overflow: hidden; padding: 16px 18px; border-radius: 22px; background: #fff; border: 1px solid rgba(148, 163, 184, .22); box-shadow: 0 16px 36px -28px rgba(15, 23, 42, .5); }
        .dark .wbo-box { background: #111827; border-color: rgba(148, 163, 184, .14); }
        .wbo-box::after { content: ""; position: absolute; width: 110px; height: 110px; left: -30px; bottom: -45px; border-radius: 50%; background: color-mix(in srgb, var(--c) 13%, transparent); }
        .wbo-label { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 800; color: #64748b; }
        .wbo-label svg { width: 18px; height: 18px; color: var(--c); }
        .wbo-value { margin-top: 8px; font-size: 32px; line-height: 1; font-weight: 900; color: #0f172a; font-variant-numeric: tabular-nums; }
        .dark .wbo-value { color: #f8fafc; }
        .wbo-value small { font-size: 14px; color: #94a3b8; font-weight: 700; }
        .wbo-hero { color: #fff; border: 0; background: radial-gradient(400px 160px at 100% 0, rgba(255, 255, 255, .22), transparent 60%), linear-gradient(135deg, #4f46e5, #0891b2 70%, #10b981); }
        .dark .wbo-hero { background: radial-gradient(400px 160px at 100% 0, rgba(255, 255, 255, .14), transparent 60%), linear-gradient(135deg, #3730a3, #0e7490 70%, #047857); }
        .wbo-hero::after { background: rgba(255, 255, 255, .12); }
        .wbo-hero .wbo-label, .wbo-hero .wbo-value, .wbo-hero .wbo-value small { color: #fff; }
        .wbo-hero .wbo-label svg { color: #fff; }
        .wbo-hero a { position: relative; z-index: 1; display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; padding: 5px 12px; border-radius: 99px; font-size: 12px; font-weight: 800; color: #fff; background: rgba(255, 255, 255, .18); }
        .wbo-hero a:hover { background: rgba(255, 255, 255, .28); }
    </style>

    <div class="wbo">
        <div class="wbo-box wbo-hero">
            <div class="wbo-label"><x-filament::icon icon="heroicon-o-sparkles" /> البوتات ردت النهارده على</div>
            <div class="wbo-value">{{ number_format($conversations) }} <small>محادثة · {{ number_format($messages) }} رسالة</small></div>
            @if ($busiest)
                <a href="{{ $busiest['url'] }}">الأنشط: {{ $busiest['name'] }} ({{ $busiest['conversations'] }} محادثة) ←</a>
            @endif
        </div>
        <div class="wbo-box" style="--c: #16a34a">
            <div class="wbo-label"><x-filament::icon icon="heroicon-o-signal" /> متصلة بواتساب</div>
            <div class="wbo-value">{{ $connected }} <small>من {{ $total }}</small></div>
        </div>
        <div class="wbo-box" style="--c: #6366f1">
            <div class="wbo-label"><x-filament::icon icon="heroicon-o-bolt" /> بوتات نشطة</div>
            <div class="wbo-value">{{ $active }}</div>
        </div>
        <div class="wbo-box" style="--c: #f59e0b">
            <div class="wbo-label"><x-filament::icon icon="heroicon-o-chat-bubble-left-ellipsis" /> متوسط رسايل المحادثة</div>
            <div class="wbo-value">{{ $conversations ? round($messages / $conversations, 1) : 0 }}</div>
        </div>
    </div>
</x-filament-widgets::widget>
