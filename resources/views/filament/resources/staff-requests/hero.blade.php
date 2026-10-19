{{-- Top of a new advance / vacation request. --}}
@include('filament.resources.staff-requests._style')
@php
    $first = explode(' ', trim(auth()->user()?->name ?? ''))[0] ?? '';
    $hero = $kind === 'advance'
        ? ['sx-adv', '💸', "محتاج سلفة يا {$first}؟", 'اكتب المبلغ وهتشوف على طول هيفضل لك كام من المرتب.', ['① المبلغ', '② السبب', '③ الإدارة بتراجع', '④ بيوصلك إشعار']]
        : ['sx-vac', '🏖️', "إجازة سعيدة يا {$first}", 'اختار النوع والأيام، والكارت اللي جنب الفورم بيوريك إجازتك قبل ما تبعت.', ['① النوع', '② الأيام', '③ الإدارة بتراجع', '④ بتتسجل في الحضور']];
@endphp
<div class="sx" dir="rtl">
    <div class="sx-hero {{ $hero[0] }}">
        <div class="sx-hero-art">{{ $hero[1] }}</div>
        <div class="sx-hero-txt">
            <h2>{{ $hero[2] }}</h2>
            <p>{{ $hero[3] }}</p>
            <div class="sx-steps">@foreach ($hero[4] as $s)<span>{{ $s }}</span>@endforeach</div>
        </div>
    </div>
</div>
