@php
    /** @var \App\Models\WhatsappBot $bot */
    $bot = $getRecord();
    $today = \App\Filament\Resources\WhatsappBotResource::todayTotals()[$bot->id] ?? ['conversations' => 0, 'messages' => 0];
    $status = $bot->session_status;
    [$statusLabel, $statusColor] = match ($status) {
        'connected' => ['متصل', '#16a34a'],
        'qr' => ['مستني QR', '#d97706'],
        'starting' => ['بيشتغل', '#d97706'],
        'disconnected' => ['مفصول', '#e11d48'],
        'logged_out' => ['خرج من واتساب', '#e11d48'],
        default => ['مش مربوط', '#64748b'],
    };
    $hue = ($bot->id * 53) % 360;
@endphp

<div class="wb-card {{ $bot->is_active ? '' : 'wb-off' }}" dir="rtl" style="--hue: {{ $hue }}">
    <div class="wb-top">
        <div class="wb-av">
            {{ mb_substr(trim($bot->name), 0, 2) }}
            <span class="wb-dot {{ $status === 'connected' ? 'on' : '' }}" style="background: {{ $statusColor }}"></span>
        </div>
        <div class="wb-id">
            <div class="wb-name">{{ $bot->name }}</div>
            <div class="wb-sub">
                @if ($bot->staff) <span>{{ $bot->staff->name }}</span> @endif
                @if ($bot->whatsapp_phone_number) <span dir="ltr">{{ $bot->whatsapp_phone_number }}</span> @endif
            </div>
        </div>
        <span class="wb-status" style="--c: {{ $statusColor }}">{{ $statusLabel }}</span>
    </div>

    <div class="wb-stats">
        <div class="wb-stat">
            <b>{{ number_format($today['conversations']) }}</b>
            <span>محادثة النهارده</span>
        </div>
        <div class="wb-stat">
            <b>{{ number_format($today['messages']) }}</b>
            <span>رسالة النهارده</span>
        </div>
        <div class="wb-stat">
            <b>{{ $today['conversations'] ? round($today['messages'] / $today['conversations'], 1) : 0 }}</b>
            <span>رسالة لكل محادثة</span>
        </div>
    </div>

    <div class="wb-foot">
        <span class="wb-tag">{{ $bot->mode === 'training' ? 'تعليمي' : 'لايف' }}</span>
        <span class="wb-tag {{ $bot->is_active ? 'wb-tag-on' : '' }}">{{ $bot->is_active ? 'نشط' : 'متوقف' }}</span>
        @if ($bot->connected_at && $status === 'connected')
            <span class="wb-since">متصل من {{ $bot->connected_at->locale('ar')->diffForHumans(null, true) }}</span>
        @endif
    </div>

    @if ($bot->qr_code && $status === 'qr')
        <div class="wb-qr"><img src="{{ $bot->qr_code }}" alt="QR"><span>امسح الكود من واتساب ← الأجهزة المرتبطة</span></div>
    @endif
</div>
