@extends('layouts.site')

@section('title', (string) ($machine->name . ' | ' . site('general.seo.title_suffix')))
@section('meta_description', (string) (\Illuminate\Support\Str::limit($machine->description ?: implode('، ', $machine->featureTitles()), 160)))
@section('og_image', (string) ($machine->displayImageUrl()))

@php
    $detail = site('showroom.detail');
    $variants = $machine->colorVariants();
    // One flat gallery: every colour's photos in order; each slide knows its colour.
    $slides = collect($variants)->flatMap(fn ($variant, $i) => collect($variant['images'])->map(fn ($image) => [
        'image' => $image,
        'caption' => trim($machine->name . ($variant['name'] ? ' - ' . $variant['name'] : '')),
        'variant' => $i,
    ]))->values();
    $firstSlideOfVariant = collect($variants)->keys()->mapWithKeys(fn ($i) => [$i => $slides->search(fn ($s) => $s['variant'] === $i)])->all();
    $isOffer = $machine->type === 'offer' && $machine->new_price;
    $currency = site('showroom.card.currency');
    $category = $machine->categoryLabel();
    $features = $machine->featureTitles();
    $specRows = $machine->specificationRows();
    $whatsapp = site_whatsapp(site('general.whatsapp'), 'لو سمحت بستفسر عن ' . trim(($machine->brand?->name ?? '') . ' ' . $machine->name));
@endphp

@section('content')
    <section class="mg-product" dir="rtl">
        <div class="container">

            <nav class="mg-breadcrumb" aria-label="مسار التنقل">
                <a href="{{ route('home') }}">الرئيسية</a>
                <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                <a href="{{ route('machines.index') }}">{{ collect(site('general.menu', []))->firstWhere('url', '/machines')['label'] ?? 'المعرض' }}</a>
                <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                <span aria-current="page">{{ $machine->name }}</span>
            </nav>

            <div class="row g-4 align-items-start">

                {{-- Gallery --}}
                <div class="col-lg-7">
                    <div class="mg-gallery">
                        @if ($slides->isNotEmpty())
                            <div class="swiper mg-gallery__main" aria-roledescription="carousel" aria-label="صور {{ $machine->name }}">
                                <div class="swiper-wrapper">
                                    @foreach ($slides as $i => $slide)
                                        <div class="swiper-slide" data-variant="{{ $slide['variant'] }}" style="--mg-slide-img: url('{{ $slide['image'] }}');">
                                            <button type="button" class="mg-gallery__open" data-lightbox-index="{{ $i }}"
                                                aria-label="عرض الصورة {{ $i + 1 }} بالحجم الكامل">
                                                <img src="{{ $slide['image'] }}" alt="{{ $slide['caption'] }}"
                                                    @if ($i > 0) loading="lazy" @endif decoding="async">
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                                @if ($slides->count() > 1)
                                    <button type="button" class="mg-gallery__nav mg-gallery__nav--prev" aria-label="الصورة السابقة"><i class="fa-solid fa-chevron-right"></i></button>
                                    <button type="button" class="mg-gallery__nav mg-gallery__nav--next" aria-label="الصورة التالية"><i class="fa-solid fa-chevron-left"></i></button>
                                    <div class="mg-gallery__counter" aria-live="polite"><span data-gallery-current>1</span> / {{ $slides->count() }}</div>
                                @endif
                                <span class="mg-gallery__expand" aria-hidden="true"><i class="fa-solid fa-expand"></i></span>
                            </div>

                            @if ($slides->count() > 1)
                                <div class="swiper mg-gallery__thumbs">
                                    <div class="swiper-wrapper">
                                        @foreach ($slides as $i => $slide)
                                            <div class="swiper-slide">
                                                <img src="{{ $slide['image'] }}" alt="" loading="lazy" decoding="async">
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if (! empty($detail['gallery_zoom']))
                                <p class="mg-gallery__hint"><i class="fa-solid fa-magnifying-glass-plus" aria-hidden="true"></i> {{ $detail['gallery_zoom'] }}</p>
                            @endif
                        @else
                            <div class="mg-gallery__empty"><i class="fa-solid fa-motorcycle" aria-hidden="true"></i></div>
                        @endif
                    </div>
                </div>

                {{-- Summary --}}
                <div class="col-lg-5">
                    <div class="mg-summary">
                        <div class="mg-summary__meta">
                            @if ($machine->brand?->name)
                                <span class="mg-chip mg-chip--brand">{{ trim($machine->brand->name) }}</span>
                            @endif
                            @if ($category)
                                <span class="mg-chip">{{ $category }}</span>
                            @endif
                            @if ($machine->cc)
                                <span class="mg-chip">{{ $machine->cc }} CC</span>
                            @endif
                            @if ($isOffer)
                                <span class="mg-chip mg-chip--offer">{{ $detail['offer_label'] ?? '' }}</span>
                            @endif
                        </div>

                        <h1 class="mg-summary__title">{{ $machine->name }}</h1>

                        <div class="mg-summary__prices">
                            @if ($machine->cash_price)
                                <div class="mg-price">
                                    <span>{{ $detail['cash_label'] ?? '' }}</span>
                                    <strong>{{ number_format((float) ($isOffer ? $machine->new_price : $machine->cash_price)) }} <small>{{ $currency }}</small></strong>
                                    @if ($isOffer && $machine->old_price)
                                        <del>{{ number_format((float) $machine->old_price) }} {{ $currency }}</del>
                                    @endif
                                </div>
                            @endif
                            @if (! empty($detail['show_installment_price']) && $machine->installment_price)
                                <div class="mg-price mg-price--alt">
                                    <span>{{ $detail['installment_label'] ?? '' }}</span>
                                    <strong>{{ number_format((float) $machine->installment_price) }} <small>{{ $currency }}</small></strong>
                                </div>
                            @endif
                        </div>

                        @if (count($variants) > 1)
                            <div class="mg-colors">
                                <div class="mg-colors__label">
                                    {{ $detail['color_label'] ?? '' }}
                                    <strong data-color-name>{{ $variants[0]['name'] ?? '' }}</strong>
                                </div>
                                <div class="mg-colors__list" role="group" aria-label="{{ $detail['color_label'] ?? '' }}">
                                    @foreach ($variants as $i => $variant)
                                        <button type="button" @class(['mg-swatch', 'is-active' => $i === 0])
                                            style="--swatch: {{ $variant['hex'] ?? '#cccccc' }};"
                                            data-variant="{{ $i }}" data-slide="{{ $firstSlideOfVariant[$i] }}" data-name="{{ $variant['name'] }}"
                                            aria-pressed="{{ $i === 0 ? 'true' : 'false' }}"
                                            aria-label="{{ $variant['name'] ?: 'لون ' . ($i + 1) }}"></button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="mg-summary__actions">
                            @if ($hasCalculator)
                                <a href="#calculator" class="site-button"><em>{{ $detail['calc_button'] ?? '' }}</em></a>
                            @endif
                            @if ($whatsapp && ! empty($detail['whatsapp_button']))
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="mg-whatsapp">
                                    <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> اعرف التفاصيل
                                </a>
                            @endif
                        </div>

                        @if ($features)
                            <ul class="mg-summary__features">
                                @foreach (array_slice($features, 0, 6) as $feature)
                                    <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> {{ $feature }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Details --}}
            <div class="mg-details">
                @if ($machine->description)
                    <div class="mg-panel">
                        @foreach (preg_split('/\R{2,}/', trim($machine->description)) as $paragraph)
                            <p>{!! nl2br(e($paragraph)) !!}</p>
                        @endforeach
                    </div>
                @endif

                @if ($specRows)
                    <div class="mg-panel">
                        <h2 class="mg-panel__title">{{ $detail['specs_title'] ?? '' }}</h2>
                        <dl class="mg-specs">
                            @foreach ($specRows as $row)
                                <div class="mg-specs__row">
                                    <dt>{{ $row['label'] ?? '—' }}</dt>
                                    <dd>{{ $row['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif

                @if (count($features) > 6)
                    <div class="mg-panel">
                        <h2 class="mg-panel__title">{{ $detail['features_title'] ?? '' }}</h2>
                        <ul class="mg-feature-grid">
                            @foreach ($features as $feature)
                                <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> {{ $feature }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($video = $machine->youtubeEmbedUrl())
                    <div class="twm-video-showcase-wrap">
                        <div class="section-head text-center m-b20">
                            <div class="twm-sm-title site-text-primary">{{ $detail['video_small_title'] ?? '' }}</div>
                            <h2 class="twm-large-title text-white" style="font-size: 26px;">{{ site_text($detail['video_title'] ?? '', ['name' => $machine->name]) }}</h2>
                            @if (! empty($detail['video_text']))
                                <p style="color: #94a3b8; font-size: 14px; max-width: 650px; margin: 10px auto 0;">{{ $detail['video_text'] }}</p>
                            @endif
                        </div>
                        <div class="twm-video-container">
                            <iframe src="{{ $video }}" title="{{ $machine->name }}" loading="lazy"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                        </div>
                    </div>
                @endif

                @if ($hasCalculator)
                    <div class="site-detail-calc" id="calculator">
                        <x-site.installment-calculator :machine="$machine"
                            :title="site_text($detail['calc_title'] ?? '', ['name' => $machine->name])" />
                    </div>
                @endif

                @if (count($detail['faqs'] ?? []))
                    <div class="mg-panel">
                        <h2 class="mg-panel__title">{{ $detail['faq_title'] ?? '' }}</h2>
                        <div class="twm-faq-info">
                            <div class="accordion twm-acdn" id="sf-faq-accordion">
                                @foreach ($detail['faqs'] as $i => $faq)
                                    <div class="accordion-item">
                                        <button @class(['accordion-button', 'collapsed' => $i > 0]) type="button" data-bs-toggle="collapse"
                                            data-bs-target="#FAQ{{ $i }}" aria-expanded="{{ $i === 0 ? 'true' : 'false' }}">
                                            {{ $faq['question'] ?? '' }}
                                        </button>
                                        <div id="FAQ{{ $i }}" @class(['accordion-collapse', 'collapse', 'show' => $i === 0]) data-bs-parent="#sf-faq-accordion">
                                            <div class="accordion-body">{{ $faq['answer'] ?? '' }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                @if ($related->isNotEmpty())
                    <div class="mg-related">
                        <h2 class="mg-panel__title">{{ $detail['related_title'] ?? '' }}</h2>
                        <div class="row g-4">
                            @foreach ($related as $item)
                                <div class="col-xl-3 col-lg-4 col-sm-6">
                                    <x-site.machine-card :machine="$item" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- Full-screen viewer --}}
    @if ($slides->isNotEmpty())
        <div class="mg-lightbox" id="mgLightbox" role="dialog" aria-modal="true" aria-label="صور {{ $machine->name }}" hidden>
            <div class="mg-lightbox__bar">
                <span class="mg-lightbox__counter" aria-live="polite"></span>
                <button type="button" class="mg-lightbox__close" aria-label="إغلاق"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="swiper mg-lightbox__swiper" dir="rtl">
                <div class="swiper-wrapper">
                    @foreach ($slides as $slide)
                        <div class="swiper-slide">
                            <div class="swiper-zoom-container">
                                <img data-src="{{ $slide['image'] }}" alt="{{ $slide['caption'] }}">
                            </div>
                        </div>
                    @endforeach
                </div>
                @if ($slides->count() > 1)
                    <button type="button" class="mg-lightbox__nav mg-lightbox__nav--prev" aria-label="الصورة السابقة"><i class="fa-solid fa-chevron-right"></i></button>
                    <button type="button" class="mg-lightbox__nav mg-lightbox__nav--next" aria-label="الصورة التالية"><i class="fa-solid fa-chevron-left"></i></button>
                @endif
            </div>
            <p class="mg-lightbox__caption"></p>
        </div>
    @endif
@endsection

@push('scripts')
    <script src="{{ site_asset('js/site-product.js') }}"></script>
@endpush
