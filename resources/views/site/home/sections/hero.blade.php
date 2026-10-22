<div class="hero-wrap"><img class="hero-road" src="{{ site_url(site('home.hero.bg_image')) }}" alt="" aria-hidden="true">
@include('site.partials.site-header')
<section class="home-hero">
<div class="hero-copy">
<h1>{{ site('home.design.hero.text_01') }}</h1>
<p class="hero-brand" dir="ltr">{{ site('home.design.hero.text_02') }}</p>
<p class="hero-description" dir="rtl">{{ site('home.design.hero.text_03') }}<br/>{{ site('home.design.hero.text_04') }}</p>
<a class="button button-orange" href="{{ route('machines.index') }}">{{ site('home.design.hero.text_05') }}<img alt="" aria-hidden="true" class="icon bi bi-chevron-right" height="24" src="{{ asset('motogate-v2/assets/images/icons/chevron-right.svg') }}" width="24"/></a>
</div>
<div aria-label="دراجات موتوجيت" aria-roledescription="عرض شرائح" class="home-visual hero-carousel" data-carousel="hero" role="region" tabindex="0">
<img alt="" class="hero-dial" height="961" src="{{ asset('motogate-v2/assets/images/العداد.png') }}" width="1637"/>
<div class="hero-slides">@foreach (collect(site('home.hero.slides', []))->filter(fn ($s) => !empty($s['image']))->values() as $slide)<div class="hero-slide {{ $loop->first ? 'is-active' : '' }}" data-slide aria-hidden="{{ $loop->first ? 'false' : 'true' }}"><img class="hero-motorcycle" src="{{ site_url($slide['image']) }}" alt="{{ $slide['title'] ?? '' }}" width="799" height="693"><p class="hero-model" dir="ltr">{{ explode(' ', $slide['title'] ?? '', 2)[0] }}<strong>{{ explode(' ', $slide['title'] ?? '', 2)[1] ?? '' }}</strong></p></div>
@endforeach
</div>
<div class="carousel-controls hero-controls">
<button aria-label="الدراجة السابقة" data-prev="" type="button">
<img alt="" aria-hidden="true" class="icon" height="24" src="{{ asset('motogate-v2/assets/images/icons/chevron-left.svg') }}" width="24"/>
</button>
<div class="carousel-dots">
@foreach (collect(site('home.hero.slides', []))->filter(fn ($s) => !empty($s['image']))->values() as $slide)<button type="button" data-dot="{{ $loop->index }}" aria-label="الشريحة {{ $loop->iteration }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}"></button>
@endforeach

</div>
<button aria-label="الدراجة التالية" data-next="" type="button">
<img alt="" aria-hidden="true" class="icon" height="24" src="{{ asset('motogate-v2/assets/images/icons/chevron-left.svg') }}" width="24"/></button><button aria-label="إيقاف التبديل التلقائي" aria-pressed="false" data-pause="" type="button">
<svg aria-hidden="true" height="15" viewBox="0 0 20 20" width="15">
<path d="M6 4V16M14 4V16" stroke="currentColor" stroke-width="2"></path>
</svg>
</button>
</div>
<span aria-live="polite" class="visually-hidden" data-carousel-status=""></span>
</div>
</section></div>