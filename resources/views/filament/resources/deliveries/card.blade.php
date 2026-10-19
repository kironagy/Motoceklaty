{{-- The whole card is the link to the request (the table's record url): no links inside it.
     One request as a card (DeliveryResource, view=cards): the bike, the customer, where the request is on its way, who has it. --}}
@php
    use App\Filament\Resources\DeliveryResource as D;

    $r = $getRecord();
    $hex = ['primary' => '#f59e0b', 'info' => '#3b82f6', 'warning' => '#f97316', 'success' => '#10b981', 'danger' => '#ef4444', 'gray' => '#64748b'];
    $color = $hex[D::STATUS_COLORS[$r->status] ?? 'gray'] ?? '#64748b';
    $label = D::STATUS_LABELS[$r->status] ?? 'بدون حالة';

    // The way of a request: new -> inquiry -> approved -> got the bike. A stop (refused, canceled, paused) ends it in red/gray.
    $steps = ['جديد', 'استعلام', 'موافقة', 'استلم'];
    $at = match ($r->status) {
        'new', 'new_request' => 0,
        'pending', 'work_check', 'transferred' => 1,
        'approved' => 2,
        'delivered' => 3,
        default => 1,
    };
    $stopped = in_array($r->status, ['rejected', 'canceled', 'paused'], true);

    $name = trim((string) $r->applicant_name);
    $initials = $name !== '' ? collect(preg_split('/\s+/u', $name))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') : '؟';
    $hue = $name !== '' ? crc32($name) % 360 : 220;
    $bike = $r->machine;
    $image = $bike?->displayImageUrl();
    $flag = $r->form_link_id
        ? (\App\Models\ApplicationDocument::where('application_id', $r->application_id)->where('status', 'needs_review')->exists() ? 'warn' : 'form')
        : null;
@endphp

@once
<style>
    .dc { --dc-card: #fff; --dc-line: #eef0f4; --dc-soft: #f6f7fb; --dc-text: #0f172a; --dc-muted: #64748b; }
    .dark .dc { --dc-card: #182235; --dc-line: rgba(255,255,255,.07); --dc-soft: rgba(255,255,255,.04); --dc-text: #f1f5f9; --dc-muted: rgba(226,232,240,.6); }
    .dc { position: relative; display: grid; gap: .85rem; color: var(--dc-text); flex: 1; width: 100%; min-width: 0; }
    .dc-hero { position: relative; display: flex; align-items: stretch; gap: .8rem; padding: .85rem; border-radius: 18px; overflow: hidden;
               background: linear-gradient(135deg, color-mix(in srgb, var(--c) 22%, transparent), color-mix(in srgb, var(--c) 4%, transparent) 70%); }
    .dc-hero::after { content: attr(data-id); position: absolute; inset-inline-end: -.3rem; bottom: -1.1rem; font-size: 3.6rem; font-weight: 900;
                      letter-spacing: -.04em; color: var(--c); opacity: .1; pointer-events: none; direction: ltr; }
    .dc-bike { width: 5.6rem; height: 4.6rem; flex: none; border-radius: 14px; display: grid; place-items: center; overflow: hidden;
               background: var(--dc-card); box-shadow: 0 6px 18px color-mix(in srgb, var(--c) 25%, transparent); }
    .dc-bike img { width: 100%; height: 100%; object-fit: contain; padding: .2rem; }
    .dc-bike span { font-size: 2rem; }
    .dc-top { flex: 1; min-width: 0; display: grid; align-content: space-between; gap: .35rem; position: relative; z-index: 1; }
    .dc-row { display: flex; align-items: center; justify-content: space-between; gap: .4rem; }
    .dc-id { font-size: .75rem; font-weight: 800; color: var(--dc-muted); direction: ltr; }
    .dc-pill { display: inline-flex; align-items: center; gap: .35rem; padding: .25rem .65rem; border-radius: 999px; font-size: .72rem; font-weight: 800;
               color: #fff; background: var(--c); box-shadow: 0 4px 12px color-mix(in srgb, var(--c) 40%, transparent); white-space: nowrap; }
    .dc-pill i { width: .4rem; height: .4rem; border-radius: 50%; background: #fff; animation: dc-pulse 1.6s infinite; }
    @keyframes dc-pulse { 50% { opacity: .35; } }
    .dc-bikename { font-weight: 800; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dc-company { font-size: .72rem; color: var(--dc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dc-person { display: flex; align-items: center; gap: .7rem; padding: 0 .2rem; }
    .dc-avatar { width: 2.6rem; height: 2.6rem; flex: none; border-radius: 14px; display: grid; place-items: center; font-weight: 800; font-size: .95rem; color: #fff;
                 background: linear-gradient(135deg, hsl(var(--h) 70% 55%), hsl(calc(var(--h) + 40) 70% 45%)); }
    .dc-name { font-weight: 800; font-size: 1rem; line-height: 1.35; }
    .dc-phone { display: inline-flex; align-items: center; gap: .3rem; font-size: .8rem; color: var(--dc-muted); direction: ltr; }
    .dc-way { display: grid; grid-template-columns: repeat(4, 1fr); gap: .3rem; padding: 0 .2rem; }
    .dc-step { display: grid; gap: .3rem; font-size: .66rem; font-weight: 700; color: var(--dc-muted); text-align: center; }
    .dc-step b { height: .32rem; border-radius: 999px; background: var(--dc-soft); border: 1px solid var(--dc-line); }
    .dc-step.on { color: var(--dc-text); }
    .dc-step.on b { background: var(--c); border-color: transparent; }
    .dc-step.here b { box-shadow: 0 0 0 3px color-mix(in srgb, var(--c) 25%, transparent); }
    .dc-alert { font-size: .75rem; font-weight: 700; padding: .45rem .65rem; border-radius: 12px; }
    .dc-alert.red { color: #b91c1c; background: rgba(239,68,68,.1); } .dark .dc-alert.red { color: #fca5a5; }
    .dc-alert.amber { color: #b45309; background: rgba(245,158,11,.12); } .dark .dc-alert.amber { color: #fcd34d; }
    .dc-foot { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .55rem .2rem 0; border-top: 1px dashed var(--dc-line);
               font-size: .75rem; color: var(--dc-muted); }
    .dc-foot span { display: inline-flex; align-items: center; gap: .3rem; }
    .dc-staff i { width: 1.35rem; height: 1.35rem; border-radius: 50%; display: grid; place-items: center; font-style: normal; font-size: .65rem; font-weight: 800;
                  background: var(--dc-soft); border: 1px solid var(--dc-line); color: var(--dc-text); }
    .fi-ta-record.delivery-card { border-radius: 22px; transition: transform .18s ease, box-shadow .18s ease; }
    .fi-ta-record.delivery-card:hover { transform: translateY(-3px); box-shadow: 0 16px 34px rgba(15,23,42,.12); }
    .dark .fi-ta-record.delivery-card:hover { box-shadow: 0 16px 34px rgba(0,0,0,.4); }
</style>
@endonce

<div class="dc" dir="rtl" style="--c: {{ $color }}; --h: {{ $hue }}">
    <div class="dc-hero" data-id="#{{ $r->id }}">
        <div class="dc-bike">
            @if ($image)<img src="{{ $image }}" alt="" loading="lazy">@else<span>🏍️</span>@endif
        </div>
        <div class="dc-top">
            <div class="dc-row">
                <span class="dc-id">#{{ $r->id }} @if ($flag === 'warn')<span title="من الفورم - فيه مستند الـ AI نبّه عليه">⚠️</span>@elseif ($flag)<span title="اتقدم من الفورم">📝</span>@endif</span>
                <span class="dc-pill">@unless ($stopped || $r->status === 'delivered')<i></i>@endunless{{ $label }}</span>
            </div>
            <div>
                <div class="dc-bikename">{{ $bike?->name ?? 'لسه مختارش مكنة' }}</div>
                <div class="dc-company">🏦 {{ $r->installment_type ?: 'شركة التقسيط مش محددة' }}</div>
            </div>
        </div>
    </div>

    <div class="dc-person">
        <div class="dc-avatar">{{ $initials }}</div>
        <div style="min-width: 0">
            <div class="dc-name">{{ $name !== '' ? $name : 'بدون اسم' }}</div>
            @if ($r->applicant_phone)
                <span class="dc-phone">📞 {{ $r->applicant_phone }}</span>
            @endif
        </div>
    </div>

    <div class="dc-way">
        @foreach ($steps as $i => $step)
            <div @class(['dc-step', 'on' => $i <= $at && ! ($stopped && $i === $at), 'here' => $i === $at && ! $stopped])>
                <b @if ($stopped && $i === $at) style="background: {{ $color }}" @endif></b>
                {{ $stopped && $i === $at ? $label : $step }}
            </div>
        @endforeach
    </div>

    @if (D::isHitler() && $r->pendingStaff)
        <div class="dc-alert red">⏳ مستني موافقتك على التحويل لـ {{ $r->pendingStaff->name }} ({{ $r->transferRequester?->name ?? '—' }})</div>
    @endif
    @if ($r->trashed())
        <div class="dc-alert amber">🗑 اتحذف بواسطة {{ $r->deletedBy?->name ?? '—' }} · {{ $r->deleted_at?->format('d/m/Y H:i') }}</div>
    @endif

    <div class="dc-foot">
        <span class="dc-staff"><i>{{ $r->staff ? mb_substr($r->staff->name, 0, 1) : '—' }}</i>{{ $r->staff?->name ?? 'بدون موظف' }}</span>
        <span title="{{ $r->created_at?->format('d/m/Y H:i') }}">🕒 {{ $r->created_at?->locale('ar')->diffForHumans() }}</span>
    </div>
</div>
