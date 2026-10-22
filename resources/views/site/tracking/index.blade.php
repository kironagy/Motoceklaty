@extends('layouts.site')
@section('title', (string) site('tracking.title'))
@section('body_class', 'tracking-page interior-page source-design')
@section('design_css', 'pages')
@section('content')<main id="main" dir="rtl"><div class="page-heading"><span class="mini-label">{{ site('tracking.design.page_heading.text_01') }}</span><h1>{{ site('tracking.design.page_heading.text_02') }} <em>{{ site('tracking.design.page_heading.text_03') }}</em></h1><p>{{ site('tracking.design.page_heading.text_04') }}</p></div><div class="tracking-layout"><section class="surface tracking-search"><span class="route-number">01 — 05</span><h2>{{ site('tracking.design.tracking_layout.text_01') }}</h2><form action="{{ route('tracking.index') }}" method="POST">@csrf<label for="orderNumber">{{ site('tracking.code_label') }}</label><div class="input-action"><input autocomplete="off" dir="ltr" id="orderNumber" maxlength="1024" name="code" placeholder="{{ site('tracking.code_label') }}" required=""/><button class="button button-orange" type="submit">{{ site('tracking.design.tracking_layout.text_02') }}</button></div></form><p id="trackingMessage" role="status">@if($notFound){{ site('tracking.not_found') }}
@endif
 @error('code'){{ $message }}@enderror</p></section>@if($status)<section aria-live="polite" class="surface tracking-result" id="trackingResult"><div class="status-top"><span dir="ltr" id="trackedNumber">{{ site('general.site_name') }}</span><span class="status-badge" id="trackedStatus">{{ $status }}</span></div><h2 id="trackedModel">{{ $modelName }}</h2><p id="trackedUpdate">{{ site('tracking.result_note') }}</p><ol class="order-timeline" id="orderTimeline">@foreach($timeline as $step)<li class="{{ $step['active'] ? 'is-complete' : '' }}"><span>{{ $step['label'] }}</span></li>
@endforeach
</ol></section>
@endif
@unless($status)<div class="tracking-idle" id="trackingIdle"><img alt="دراجة موتوجيت" src="{{ site_url(site('tracking.design.tracking_layout.image_05')) }}"/><span>{{ site('tracking.design.tracking_layout.text_03') }}<br/><b>{{ site('tracking.design.tracking_layout.text_04') }}</b></span></div>
@endunless
</div><section class="help-strip"><div><h2>{{ site('tracking.design.help_strip.text_01') }}</h2><p>{{ site('tracking.design.help_strip.text_02') }}</p></div><a class="button button-outline" href="{{ route('contact') }}">{{ site('tracking.design.help_strip.text_03') }}</a></section></main>
@endsection
