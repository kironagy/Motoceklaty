@extends('layouts.site')

@section('title', (string) (site('showroom.seo.title')))
@section('meta_description', (string) (site('showroom.seo.description')))

@php
    $filter = site('showroom.filter');
    $visibleChips = max(1, (int) ($filter['visible_chips'] ?? 6));
    $hiddenChips = max(0, $brands->count() - $visibleChips);
    $activeIsHidden = $brandId && $brands->search(fn ($b) => $b->id === $brandId) >= $visibleChips;
    // Filter links keep the other filters; changing one restarts at page 1.
    $link = fn (array $change) => route('machines.index', array_filter(array_merge(
        ['q' => $search ?: null, 'category' => $category, 'brand' => $brandId],
        $change,
    ), fn ($v) => $v !== null && $v !== '')) . '#filterBar';
@endphp

@section('content')
    <div class="motogate-fleet-hero" dir="rtl">
        <div class="container">
            <div class="motogate-hero-content">
                @if (site('showroom.hero.badge'))
                    <div class="motogate-hero-badge wow fadeInDown" data-wow-delay="0.1">
                        <i class="fa-solid fa-shield-halved text-orange"></i>
                        <span>{{ site('showroom.hero.badge') }}</span>
                    </div>
                @endif

                <h1 class="motogate-hero-title wow fadeInDown" data-wow-delay="0.2">
                    {{ site('showroom.hero.title_before') }} <span class="text-orange">{{ site('showroom.hero.title_highlight') }}</span>
                </h1>

                @if (site('showroom.hero.subtitle'))
                    <p class="motogate-hero-subtitle wow fadeInDown" data-wow-delay="0.3">{{ site('showroom.hero.subtitle') }}</p>
                @endif

                <ul class="motogate-hero-breadcrumb wow fadeInDown" data-wow-delay="0.4">
                    <li><a href="{{ route('home') }}">الرئيسية</a></li>
                    <li><i class="fa-solid fa-chevron-left"></i></li>
                    <li class="active">{{ site('showroom.hero.breadcrumb') }}</li>
                </ul>

                <div class="twm-creative-filter-bar wow fadeInUp" data-wow-delay="0.5" id="filterBar"
                    data-more-label="{{ ($filter['more_label'] ?? '') . " ({$hiddenChips}+)" }}"
                    data-less-label="{{ $filter['less_label'] ?? '' }}">

                    <form method="GET" action="{{ route('machines.index') }}#filterBar" class="mg-search" role="search">
                        @if ($category) <input type="hidden" name="category" value="{{ $category }}"> @endif
                        @if ($brandId) <input type="hidden" name="brand" value="{{ $brandId }}"> @endif
                        <label for="showroom-q" class="visually-hidden">{{ $filter['search_placeholder'] ?? '' }}</label>
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input id="showroom-q" type="search" name="q" value="{{ $search }}" maxlength="80" autocomplete="off"
                            placeholder="{{ $filter['search_placeholder'] ?? '' }}">
                        @if ($search !== '')
                            <a href="{{ $link(['q' => null]) }}" class="mg-search__clear" aria-label="{{ $filter['clear_search'] ?? '' }}"><i class="fa-solid fa-xmark"></i></a>
                        @endif
                        <button type="submit">{{ $filter['search_button'] ?? '' }}</button>
                    </form>

                    <div class="filter-bar-header">
                        <div class="filter-bar-title">
                            <i class="fa-solid fa-sliders"></i>
                            <span>{{ $filter['title'] ?? '' }}</span>
                        </div>
                        <div class="filter-bar-status" id="filterStatusBadge">
                            <i class="fa-solid fa-circle-check"></i>
                            <span id="filterCountText">{{ site_text($filter['results'] ?? '', ['from' => $machines->firstItem() ?? 0, 'to' => $machines->lastItem() ?? 0, 'total' => $machines->total()]) }}</span>
                        </div>
                    </div>

                    @if ($activeCategory || $search !== '')
                        <div class="site-active-category">
                            <span>
                                @if ($activeCategory)
                                    {{ $filter['category_label'] ?? '' }} <strong>{{ $activeCategory }}</strong>
                                @endif
                                @if ($search !== '')
                                    {{ $filter['search_label'] ?? '' }} <strong>"{{ $search }}"</strong>
                                @endif
                            </span>
                            <span class="d-flex gap-3 flex-wrap">
                                @if ($search !== '')
                                    <a href="{{ $link(['q' => null]) }}"><i class="fa-solid fa-xmark"></i> {{ $filter['clear_search'] ?? '' }}</a>
                                @endif
                                @if ($activeCategory)
                                    <a href="{{ $link(['category' => null]) }}"><i class="fa-solid fa-xmark"></i> {{ $filter['clear_category'] ?? '' }}</a>
                                @endif
                            </span>
                        </div>
                    @endif

                    <div @class(['filter-chips-container', 'is-expanded' => $activeIsHidden]) id="brandFilterContainer">
                        <a href="{{ $link(['brand' => null]) }}" @class(['filter-chip-btn', 'active' => ! $brandId]) @if (! $brandId) aria-current="true" @endif>
                            <span class="chip-main">
                                <i class="fa-solid fa-layer-group"></i>
                                <span>{{ $filter['all_label'] ?? '' }}</span>
                            </span>
                            <span class="chip-count">{{ $totalAll }}</span>
                        </a>
                        @foreach ($brands as $brand)
                            <a href="{{ $link(['brand' => $brand->id]) }}"
                                @class(['filter-chip-btn', 'active' => $brandId === $brand->id, 'filter-chip-secondary' => $loop->iteration > $visibleChips])
                                @if ($brandId === $brand->id) aria-current="true" @endif>
                                <span class="chip-main">
                                    <i class="{{ $brand->web_icon ?: 'fa-solid fa-motorcycle' }}"></i>
                                    <span>{{ trim($brand->name) }}</span>
                                </span>
                                <span class="chip-count">{{ $brand->visible_count }}</span>
                            </a>
                        @endforeach
                    </div>

                    @if ($hiddenChips > 0)
                        <div class="filter-toggle-wrap">
                            <button type="button" class="filter-toggle-brands-btn" id="toggleBrandsBtn" aria-expanded="{{ $activeIsHidden ? 'true' : 'false' }}">
                                <span>{{ $activeIsHidden ? ($filter['less_label'] ?? '') : ($filter['more_label'] ?? '') . " ({$hiddenChips}+)" }}</span>
                                <i class="fa-solid fa-chevron-{{ $activeIsHidden ? 'up' : 'down' }}"></i>
                            </button>
                        </div>
                    @endif
                </div>

            </div>
        </div>
    </div>

    <div class="section-full p-t80 p-b120 site-bg-white twm-cars-section-wrap" id="fleetGridContainer">
        <div class="container">
            <div class="section-content">
                <div class="twm-cars-section m-b30">
                    <div class="row g-4" id="vehiclesRow">
                        @forelse ($machines as $machine)
                            <div class="col-xl-3 col-lg-4 col-sm-6 d-flex">
                                <x-site.machine-card :machine="$machine" />
                            </div>
                        @empty
                            <div class="col-12 filter-empty-alert">
                                <i class="fa-solid fa-motorcycle"></i>
                                <h3 style="font-weight: 800; margin-bottom: 12px; color: #fff;">{{ site('showroom.empty.title') }}</h3>
                                <p style="color: #94a3b8; font-size: 15px; margin-bottom: 22px;">{{ site('showroom.empty.text') }}</p>
                                <a href="{{ route('machines.index') }}" class="site-button">{{ site('showroom.empty.button') }}</a>
                            </div>
                        @endforelse
                    </div>
                </div>

                @if ($machines->hasPages())
                    <nav class="mg-pagination" aria-label="صفحات المعرض" dir="rtl">
                        @if ($machines->onFirstPage())
                            <span class="mg-pagination__arrow is-disabled" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
                        @else
                            <a class="mg-pagination__arrow" href="{{ $machines->previousPageUrl() }}" rel="prev" aria-label="الصفحة السابقة"><i class="fa-solid fa-arrow-right"></i></a>
                        @endif

                        @foreach ($machines->getUrlRange(1, $machines->lastPage()) as $page => $url)
                            @if ($page === 1 || $page === $machines->lastPage() || abs($page - $machines->currentPage()) <= 1)
                                @if ($page === $machines->currentPage())
                                    <span class="mg-pagination__page is-active" aria-current="page">{{ $page }}</span>
                                @else
                                    <a class="mg-pagination__page" href="{{ $url }}" aria-label="صفحة {{ $page }}">{{ $page }}</a>
                                @endif
                            @elseif (abs($page - $machines->currentPage()) === 2)
                                <span class="mg-pagination__dots" aria-hidden="true">…</span>
                            @endif
                        @endforeach

                        @if ($machines->hasMorePages())
                            <a class="mg-pagination__arrow" href="{{ $machines->nextPageUrl() }}" rel="next" aria-label="الصفحة التالية"><i class="fa-solid fa-arrow-left"></i></a>
                        @else
                            <span class="mg-pagination__arrow is-disabled" aria-hidden="true"><i class="fa-solid fa-arrow-left"></i></span>
                        @endif
                    </nav>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ site_asset('js/site-showroom.js') }}"></script>
@endpush
