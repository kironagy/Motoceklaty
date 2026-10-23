@extends('layouts.site')
@section('title', (string) site('tracking.title'))
@section('body_class', 'tracking-page interior-page source-design journey-page')
@section('design_css', 'pages')
@push('styles')
<link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/tracking.css') . '?v=' . filemtime(public_path('motogate-v2/assets/css/tracking.css')) }}">
@endpush
@section('content')
<main id="main" dir="rtl" class="journey-main">
    <header class="journey-heading">
        <span class="journey-kicker"><i aria-hidden="true"></i> MOTOGATE / YOUR NEXT RIDE</span>
        <h1>رحلتك بدأت.<br><em>تابعها خطوة بخطوة.</em></h1>
        <p>من أول طلب لحد مفتاح مكنتك في إيدك… اعرف وصلت لفين.</p>
    </header>
    <div class="journey-layout">
        <section class="journey-search" aria-labelledby="journeySearchTitle">
            <span class="journey-card-number" aria-hidden="true">01 / TRACK YOUR RIDE</span>
            <div class="journey-search-icon" aria-hidden="true">↗</div>
            <h2 id="journeySearchTitle">خطوتك الجاية تبدأ هنا</h2>
            <p>اكتب رقم طلبك أو كود المتابعة، وهنعرض لك آخر تحديث من فريق موتوجيت.</p>
            <form action="{{ route('tracking.index') }}" method="POST">
                @csrf
                <label for="orderNumber">رقم الطلب أو كود المتابعة</label>
                <input autocomplete="off" dir="ltr" id="orderNumber" maxlength="1024" name="code" placeholder="مثال: 4874" value="{{ request('code') }}" required aria-describedby="trackingMessage">
                <button class="button button-orange journey-submit" type="submit">تتبع حالة طلبك <span aria-hidden="true">←</span></button>
            </form>
            <p id="trackingMessage" class="journey-feedback" role="status">@if($notFound){{ site('tracking.not_found') }}@endif @error('code'){{ $message }}@enderror</p>
            <div class="journey-privacy"><span aria-hidden="true">◇</span> تحديثات من فريقنا، في كل خطوة من رحلتك.</div>
        </section>
        @if($status)
        @php
            $currentIndex = collect($timeline)->search(fn ($step) => $step['current']);
            $currentNumber = $currentIndex === false ? 1 : $currentIndex + 1;
        @endphp
        <section class="journey-board" aria-live="polite" id="trackingResult" aria-labelledby="trackedModel">
            <div class="journey-board-top"><span class="journey-kicker">YOUR RIDE / IN PROGRESS</span><span class="journey-status" id="trackedStatus"><i aria-hidden="true"></i>{{ $status }}</span></div>
            <div class="journey-result-heading"><div><span class="journey-eyebrow">مكنتك… أقرب كل يوم</span><h2 id="trackedModel">{{ $modelName ?: 'رحلتك مع موتوجيت' }}</h2><p id="trackedUpdate">{{ site('tracking.result_note') }}</p></div><div class="journey-counter"><strong>{{ str_pad($currentNumber, 2, '0', STR_PAD_LEFT) }}</strong><span>من {{ count($timeline) }} مراحل</span></div></div>
            <ol class="journey-stages" id="orderTimeline">
                @foreach($timeline as $step)
                <li class="{{ $step['current'] ? 'is-current' : ($step['done'] ? 'is-done' : '') }}" @if($step['current']) aria-current="step" @endif>
                    <span class="journey-stage-marker" aria-hidden="true">{{ $step['done'] ? '✓' : str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <div><strong>{{ $step['label'] }}</strong><small>{{ $step['current'] ? 'أنت هنا الآن' : ($step['done'] ? 'خطوة مكتملة' : 'الخطوة القادمة') }}</small></div>
                </li>
                @endforeach
            </ol>
            <div class="journey-board-note"><span aria-hidden="true">↗</span><div><strong>كل خطوة بتقرّبك من الطريق.</strong><p>لو محتاج تفاصيل أكتر عن طلبك، فريقنا موجود يساعدك.</p></div><a href="{{ route('contact') }}">تواصل معنا ←</a></div>
        </section>
        @else
        <section class="journey-preview" id="trackingIdle" aria-label="رحلتك مع موتوجيت">
            <div class="journey-preview-top"><span class="journey-kicker">FROM REQUEST TO THE ROAD</span><span class="journey-preview-tag">رحلتك تستاهل</span></div>
            <div class="journey-bike-scene"><span class="journey-orbit" aria-hidden="true"></span><span class="journey-big-word" aria-hidden="true">RIDE.</span><img src="{{ asset('motogate-v2/assets/images/motorcycle-rkz200.webp') }}" alt="دراجة موتوجيت جاهزة للطريق"><span class="journey-road-line" aria-hidden="true"></span></div>
            <div class="journey-preview-copy"><span class="journey-eyebrow">طلبك مش مجرد رقم</span><h2>من هنا…<em>لأول مشوار.</em></h2><p>استلام الطلب، المراجعة، الموافقة، وبعدها الاستلام.<br>تابع كل مرحلة في مكان واحد.</p></div>
            <div class="journey-mini-route" aria-hidden="true"><span>الطلب</span><i></i><span>المراجعة</span><i></i><span>الموافقة</span><i></i><span>الاستلام</span></div>
        </section>
        @endif
    </div>
    <footer class="journey-help"><span>الطريق أحلى وإحنا معاك.</span><p>عندك سؤال عن طلبك؟ خلّينا نساعدك.</p><a class="button button-outline" href="{{ route('contact') }}">تواصل مع فريق موتوجيت <span aria-hidden="true">↖</span></a></footer>
</main>
@endsection
