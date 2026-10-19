{{-- Live preview of an advance request, beside the form: the amount against the month's salary. --}}
@include('filament.resources.staff-requests._style')
@php
    $amount = max(0, (float) $amount);
    $after = $monthApproved + $amount;
    $pctOld = $salary > 0 ? min(100, $monthApproved * 100 / $salary) : 0;
    $pctNew = $salary > 0 ? min(100 - $pctOld, $amount * 100 / $salary) : 0;
    $share = $salary > 0 ? round($after * 100 / $salary) : null;
    $left = $salary - $after;
    $money = fn ($v) => number_format($v).' ج';
@endphp
<div class="sx sx-adv" dir="rtl">
    <div class="sx-card">
        <div class="sx-ticket">
            <div class="sx-line" style="align-items:center">
                <span class="sx-label">كارت السلفة · {{ $month }}</span>
                <span class="sx-chip" style="--c: #10b981">💸 سلفة</span>
            </div>

            <div class="sx-big">{{ number_format($amount) }}<small>جنيه</small></div>

            @if ($editable)
                <div class="sx-quick">
                    @foreach ([250, 500, 1000, 1500, 2000] as $v)
                        <button type="button" wire:click="$set('data.amount', {{ $v }})">{{ number_format($v) }}</button>
                    @endforeach
                </div>
            @endif

            <div class="sx-cut"></div>

            @if ($salary > 0)
                <div class="sx-line"><span>من المرتب ({{ $money($salary) }})</span><b>{{ $share }}%</b></div>
                <div class="sx-bar">
                    <i style="width: {{ $pctOld }}%; background: #94a3b8"></i>
                    <i style="width: {{ $pctNew }}%; background: linear-gradient(90deg, #10b981, #0d9488)"></i>
                </div>
                <div class="sx-line"><span>سلف معتمدة الشهر ده</span><b>{{ $money($monthApproved) }}</b></div>
                <div class="sx-line"><span>هيفضل من المرتب</span><b @if ($left < 0) style="color:#ef4444" @endif>{{ $money($left) }}</b></div>
                @if ($share !== null && $share > 50)
                    <div class="sx-tip warn">⚠️ السلف كده أكتر من نص المرتب، الإدارة ممكن تسأل عن السبب.</div>
                @endif
            @else
                <div class="sx-line"><span>سلف معتمدة الشهر ده</span><b>{{ $money($monthApproved) }}</b></div>
                <div class="sx-tip">المرتب الشهري مش متسجل، فمش هنقدر نحسب النسبة.</div>
            @endif
        </div>
    </div>
</div>
