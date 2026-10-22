@extends('layouts.site')
@section('title', (string) ($selected ? trim(($selected->brand?->name ?? '') . ' ' . $selected->name) . ' | ' . site('general.site_name') : site('showroom.seo.title')))
@section('body_class', 'gallery-page source-design')
@section('design_css', 'base')
@section('design_shell', 'gallery-shell')
@section('content')
@php
$featured = $selected ?? $machines->first();
@endphp
@include('site.partials.site-header')
<section aria-label="اختيار الماركة" class="brands-bar">
<a class="button all-brands" href="{{ route('machines.index') }}">
<img alt="" aria-hidden="true" class="icon bi bi-chevron-left" height="24" src="{{ asset('motogate-v2/assets/images/icons/chevron-left.svg') }}" width="24"/>{{ site('showroom.design.brands_bar.text_01') }}<img alt="" aria-hidden="true" class="icon bi bi-chevron-right" height="24" src="{{ asset('motogate-v2/assets/images/icons/chevron-right.svg') }}" width="24"/>
</a>
<div class="brand-list">@foreach ($brands as $brand)<a class="brand-filter" href="{{ route('machines.index', array_filter(['brand' => $brand->id, 'q' => $search, 'category' => $category])) }}" @if($brandId === $brand->id) aria-current="true"
@endif
>@if($brand->image)<img src="{{ site_url($brand->image) }}" alt="{{ trim($brand->name) }}" height="40" width="100">@else<span>{{ trim($brand->name) }}</span>
@endif
</a>
@endforeach
</div>
<button aria-label="الماركات التالية" class="brand-next" type="button">
<img alt="" aria-hidden="true" class="icon bi bi-chevron-right" height="24" src="{{ asset('motogate-v2/assets/images/icons/chevron-right.svg') }}" width="24"/>
</button>
</section>
<main id="main">
@if($featured)<section class="gallery-feature">
<div class="gallery-scene">
<img alt="" aria-hidden="true" class="showroom-floor" height="802" src="{{ asset('motogate-v2/assets/images/الباكجراوند.png') }}" width="1961"/>
<img alt="" aria-hidden="true" class="showroom-ceiling" height="55" src="{{ asset('motogate-v2/assets/images/warehouse-ceiling.webp') }}" width="608"/>
<div class="showroom-sign">
<img alt="MotoGate" class="" height="116" src="{{ site_url(site('general.logo')) }}" width="376"/><span>PERFORMANCE<br/>GALLERY</span>
</div>
<div aria-hidden="true" class="motorcycle-platform"></div>
<img alt="{{ $featured->name }}" class="showroom-motorcycle" height="693" src="{{ $featured->displayImageUrl() }}" width="799"/>
<ul aria-label="مواصفات الدراجة" class="scene-specs">
<li class="spec-capacity">
<img alt="" aria-hidden="true" class="icon bi bi-engine" height="24" src="{{ asset('motogate-v2/assets/images/icons/engine.png') }}" width="24"/>
<div>
<strong dir="ltr">{{ $featured->specificationRows()[0]['value'] ?? '—' }}</strong><span dir="rtl">{{ $featured->specificationRows()[0]['label'] ?? '' }}</span>
</div>
</li>
<li class="spec-stroke">
<img alt="" aria-hidden="true" class="icon bi bi-stroke" height="24" src="{{ asset('motogate-v2/assets/images/icons/stroke.png') }}" width="24"/>
<div>
<strong dir="ltr">{{ $featured->specificationRows()[1]['value'] ?? '—' }}</strong><span dir="rtl">{{ $featured->specificationRows()[1]['label'] ?? '' }}</span>
</div>
</li>
<li class="spec-cooling">
<img alt="" aria-hidden="true" class="icon bi bi-radiator" height="24" src="{{ asset('motogate-v2/assets/images/icons/radiator.png') }}" width="24"/>
<div>
<strong dir="ltr">{{ $featured->specificationRows()[2]['value'] ?? '—' }}</strong><span dir="rtl">{{ $featured->specificationRows()[2]['label'] ?? '' }}</span>
</div>
</li>
<li class="spec-gears">
<img alt="" aria-hidden="true" class="icon bi bi-transmission" height="24" src="{{ asset('motogate-v2/assets/images/icons/transmission.png') }}" width="24"/>
<div>
<strong dir="ltr">{{ $featured->specificationRows()[3]['value'] ?? '—' }}</strong><span dir="rtl">{{ $featured->specificationRows()[3]['label'] ?? '' }}</span>
</div>
</li>
<li class="spec-brakes">
<img alt="" aria-hidden="true" class="icon bi bi-brake" height="24" src="{{ asset('motogate-v2/assets/images/icons/brake.png') }}" width="24"/>
<div>
<strong dir="ltr">{{ $featured->specificationRows()[4]['value'] ?? '—' }}</strong><span dir="rtl">{{ $featured->specificationRows()[4]['label'] ?? '' }}</span>
</div>
</li>
</ul>
</div>
<div class="featured-copy">
<span class="eyebrow" dir="rtl">المميز في المعرض</span>
@php
preg_match('/^(\D+?)\s*(\d.*)$/u', $featured->name, $nameParts);
@endphp
<h1><span>{{ $featured->brand?->name }}</span><strong>@if ($nameParts)<em>{{ $nameParts[1] }}</em>{{ $nameParts[2] }}@else{{ $featured->name }}@endif</strong></h1>
<p class="featured-tagline" dir="rtl">{{ $featured->categoryLabel() }}</p>
<div class="mobile-specs" data-icon-base="{{ asset('motogate-v2/assets/images/icons') }}/">@foreach (array_slice($featured->specificationRows(), 0, 4) as $spec)<span><img class="icon bi bi-{{ ['engine', 'stroke', 'transmission', 'brake'][$loop->index] }}" src="{{ asset('motogate-v2/assets/images/icons/' . ['engine', 'stroke', 'transmission', 'brake'][$loop->index] . '.png') }}" alt="" aria-hidden="true" width="24" height="24"> {{ $spec['value'] }}</span>@endforeach</div>
<div class="price" dir="rtl">@if (site('showroom.card.show_price'))<span>{{ site('showroom.card.price_prefix') }}</span><strong>{{ number_format((float) ($featured->type === 'offer' && $featured->new_price ? $featured->new_price : $featured->cash_price)) }}</strong><span>{{ site('showroom.card.currency') }}</span>
@endif
</div>
<div class="featured-actions"><a class="button button-orange" data-feature-calc data-open-calculator="{{ $featured->id }}" href="{{ route('installment.index', ['machine_id' => $featured->id]) }}">{{ site('showroom.detail.calc_button') }} <img class="icon bi bi-calculator" src="{{ asset('motogate-v2/assets/images/icons/calculator.svg') }}" alt="" aria-hidden="true" width="24" height="24"></a><a class="button button-outline" data-feature-contact href="{{ site_whatsapp(site('general.whatsapp'), $featured->name) }}" target="_blank" rel="noopener">{{ site('showroom.detail.whatsapp_button') }}</a></div>
</div>
</section>
@endif

<section class="latest-models" id="models">
<div class="section-heading">
<h2>{{ site('showroom.design.latest_models.text_01') }} <em>{{ site('showroom.design.latest_models.text_02') }}</em></h2>
<a class="button button-outline" href="{{ route('machines.index') }}">{{ site('showroom.design.latest_models.text_03') }}<img alt="" aria-hidden="true" class="icon bi bi-bullseye" height="24" src="{{ asset('motogate-v2/assets/images/icons/bullseye.svg') }}" width="24"/>
</a>
</div>
<div class="models-grid">@foreach($machines as $machine)
@php
$machineData = ['id' => $machine->id, 'name' => $machine->name, 'brand' => $machine->brand?->name, 'image' => $machine->displayImageUrl(), 'category' => $machine->categoryLabel(), 'cc' => $machine->cc, 'price' => (float) ($machine->type === 'offer' && $machine->new_price ? $machine->new_price : $machine->cash_price), 'specs' => $machine->specificationRows(), 'calc' => route('installment.index', ['machine_id' => $machine->id]), 'contact' => site_whatsapp(site('general.whatsapp'), $machine->name)];
@endphp
<article class="model-card" data-live-machine='@json($machineData)' tabindex="0"><img src="{{ $machine->displayImageUrl() }}" alt="{{ $machine->name }}" height="175" loading="lazy"><div class="model-copy"><h3 dir="ltr">{{ $machine->brand?->name }}<strong>{{ $machine->name }}</strong></h3><p dir="rtl">{{ $machine->categoryLabel() }}</p><span class="model-line"></span><p class="availability" dir="rtl">@if(site('showroom.card.show_price')){{ number_format($machineData['price']) }} {{ site('showroom.card.currency') }}
@endif
</p><button class="button button-outline" type="button" data-display-machine>اعرض على المنصة ↗</button><div class="model-actions"><a class="button button-outline" href="{{ $machineData['contact'] }}" target="_blank" rel="noopener">واتساب</a><a class="button button-orange" data-open-calculator="{{ $machine->id }}" href="{{ $machineData['calc'] }}">احسب القسط</a></div></div></article>

@endforeach
</div>
@if($machines->isEmpty())<p class="empty-models" dir="rtl">{{ site('showroom.empty.text') }}</p>
@endif

<nav class="design-pagination" aria-label="صفحات المعرض">@if($machines->previousPageUrl())<a class="button button-outline" href="{{ $machines->previousPageUrl() }}">السابق</a>
@endif
 @if($machines->hasMorePages())<a class="button button-outline" href="{{ $machines->nextPageUrl() }}">التالي</a>
@endif
</nav></section>
@includeWhen($posts->isNotEmpty(), 'site.home.sections.blog')
</main>
@endsection
@push('modals')
<div class="modal fade glass-modal" id="paymentCalculator" tabindex="-1" aria-labelledby="paymentCalculatorTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content" dir="rtl"><div class="modal-header"><h2 class="modal-title fs-4" id="paymentCalculatorTitle">احسب قسطك</h2><button type="button" class="drawer-close" data-bs-dismiss="modal" aria-label="إغلاق">×</button></div><div class="modal-body">@include('site.partials.design-calculator')</div></div></div></div>
@include('site.partials.payment-result')
@endpush
