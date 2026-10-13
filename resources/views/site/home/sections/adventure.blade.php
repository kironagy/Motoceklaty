<div class="section-full p-t150 p-b120 site-bg-dark twm-step-towards-section-wrap wow fadeInDown" data-wow-offset="100" data-wow-delay="0.2">
    @if (site('home.adventure.bg_image'))
        <div class="twm-half-bg-pic" style="background-image: url('{{ site_url(site('home.adventure.bg_image')) }}');"></div>
    @endif
    <div class="container">

        <div class="row">
            <div class="col-lg-8 col-md-12">
                <x-site.section-head title-class="site-text-white"
                    :small="site('home.adventure.small_title')"
                    :title="site('home.adventure.title')" />
            </div>
        </div>

        <div dir="rtl" class="section-content">
            <div class="row twm-step-towards-section">
                @foreach (site('home.adventure.items', []) as $item)
                    <div class="col-xl-4 col-lg-4 col-md-4 m-b30">
                        <div class="twm-icon-style-left large-set in-dark-area">
                            @if (! empty($item['icon']))
                                <div class="twm-media"><img src="{{ site_url($item['icon']) }}" alt="{{ $item['title'] ?? '' }}"></div>
                            @endif
                            <div class="twm-content">
                                <h3 class="twm-title">{{ $item['title'] ?? '' }}</h3>
                                <p>{{ $item['text'] ?? '' }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

    </div>
</div>
