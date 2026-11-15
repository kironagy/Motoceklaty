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
        'restricted' => ['عليه قيد من واتساب', '#e11d48'],
        default => ['مش مربوط', '#64748b'],
    };
    $hue = ($bot->id * 53) % 360;
    // Owner 2026-11-15: WhatsApp's warning / temporary restriction on the number and when it ends
    $limit = $bot->activeLimit();
    [$limitLabel, $limitNote, $limitColor] = match ($limit) {
        'first_warning' => ['تحذير أول من واتساب', 'البوت بيرد بنص السرعة لحد ما التحذير يخلص', '#d97706'],
        'second_warning' => ['تحذير تاني من واتساب', 'البوت بيرد بربع السرعة وبس على العملاء الجداد اللي بيكلّموه دلوقتي', '#ea580c'],
        'capped' => ['وصل لحد المحادثات الجديدة', 'البوت واقف عن الإرسال لحد ما الحد يتجدد', '#e11d48'],
        'timelock' => ['قيد مؤقت من واتساب', 'واتساب مانع الرقم يبعت، والبوت واقف لحد ما القيد يتفك', '#e11d48'],
        default => [null, null, null],
    };
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

    @if ($limit)
        <div class="wb-limit" style="--c: {{ $limitColor }}">
            <div class="wb-limit-head">⚠️ {{ $limitLabel }}</div>
            <div class="wb-limit-note">{{ $limitNote }}</div>
            <div class="wb-limit-times">
                @if ($bot->wa_limit_since)
                    <span>من {{ $bot->wa_limit_since->timezone('Africa/Cairo')->format('m-d h:i A') }}</span>
                @endif
                @if ($bot->wa_limit_until)
                    <span>بيتفك {{ $bot->wa_limit_until->timezone('Africa/Cairo')->format('m-d h:i A') }}
                        (بعد {{ $bot->wa_limit_until->locale('ar')->diffForHumans(null, true) }})</span>
                    @if ($bot->wa_limit_since)
                        <span>المدة كلها {{ $bot->wa_limit_since->locale('ar')->diffForHumans($bot->wa_limit_until, true) }}</span>
                    @endif
                @else
                    <span>واتساب ماحددش ميعاد انتهاء</span>
                @endif
                @if (isset($bot->wa_limit_detail['used'], $bot->wa_limit_detail['total']))
                    <span>المحادثات الجديدة: {{ $bot->wa_limit_detail['used'] }} من {{ $bot->wa_limit_detail['total'] }}</span>
                @endif
            </div>
        </div>
    @endif

    @if ($bot->qr_code && $status === 'qr')
        <div class="wb-qr"><img src="{{ $bot->qr_code }}" alt="QR"><span>امسح الكود من واتساب ← الأجهزة المرتبطة</span></div>
    @endif
</div>
