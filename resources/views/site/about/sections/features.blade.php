<div class="section-full p-t80 p-b80 site-bg-light">
    <div class="container">

        <x-site.section-head align="center" small-class="site-accent-cyan"
            :small="site('about.features.small_title')"
            :title="site('about.features.title')" />

        <div class="row" dir="rtl">
            @foreach (site('about.features.items', []) as $item)
                @php($color = preg_match('/^#[0-9a-f]{6}$/i', $item['color'] ?? '') ? $item['color'] : '#ff6100')
                <div class="col-lg-4 col-md-6 m-b30">
                    <div class="twm-about-feature-box">
                        <div class="twm-about-feature-icon" style="background: {{ $color }}1f; color: {{ $color }};">
                            <i class="feather {{ site_feather($item['icon'] ?? null) }}"></i>
                        </div>
                        <h3 class="twm-about-feature-title">{{ $item['title'] ?? '' }}</h3>
                        <p class="twm-about-feature-desc">{{ $item['text'] ?? '' }}</p>
                    </div>
                </div>
            @endforeach
        </div>

    </div>
</div>
