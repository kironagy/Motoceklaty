@php
    $words = collect(site('home.hero.typed_words', []))->filter(fn ($word) => is_string($word) && trim($word) !== '')->values();
    $slides = collect(site('home.hero.slides', []))->filter(fn ($slide) => ! empty($slide['image']))->values();
@endphp

<div class="twm-home-1-banner-wrap mt-3">
    <div class="twm-home-1-banner" @if (site('home.hero.bg_image')) style="background-image: url('{{ site_url(site('home.hero.bg_image')) }}');" @endif>
        <div class="twm-banner-LR-wrap">
            <div class="twm-banner-left">
                <div class="twm-banner-left-info">
                    <div class="twm-banner-left-content">

                        @if (site('home.hero.small_title'))
                            <div class="twm-sm-title left">{{ site('home.hero.small_title') }}</div>
                        @endif
                        <h2 class="twm-banner-title text-center text-lg-start">
                            @if ($words->isNotEmpty())
                                <em class="txt-type" data-wait="3000" data-words='@json($words)'></em>
                            @endif
                            {{ site('home.hero.brand_first') }}<span class="p-0">{{ site('home.hero.brand_second') }}</span>
                        </h2>

                        <x-site.installment-calculator
                            :tabs="site('home.hero.tabs', [])"
                            :title="site('home.hero.calc_title')"
                            :browse-url="route('machines.index')" />

                    </div>
                </div>
            </div>
            <div class="twm-banner-right">
                <div class="twm-banner-right-section" id="twmMotoSlider">
                    @foreach ($slides as $slide)
                        <div @class(['twm-moto-slide', 'active' => $loop->first])>
                            <div class="twm-banner-r-content">
                                <div class="twm-banner-r-bx site-hero-stage">
                                    <h1 class="twm-bnr-title">{{ $slide['title'] ?? '' }}</h1>
                                    <div class="twm-banner-media">
                                        <img src="{{ site_url($slide['image']) }}" alt="{{ $slide['title'] ?? '' }}" data-hero-bike
                                            @unless ($loop->first) loading="lazy" @endunless>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script src="{{ site_asset('js/site-hero.js') }}"></script>
@endpush
