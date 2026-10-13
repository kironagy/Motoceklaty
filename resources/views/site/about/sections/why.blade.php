<div class="section-full p-t80 p-b80 site-bg-light">
    <div class="container">

        <x-site.section-head align="center" small-class="site-accent-cyan"
            :small="site('about.why.small_title')"
            :title="site('about.why.title')" />

        <div class="row" dir="rtl">
            @foreach (site('about.why.items', []) as $item)
                <div class="col-lg-3 col-md-6 col-sm-6 m-b30">
                    <div class="twm-why-card">
                        <div class="twm-why-icon"><i class="feather {{ site_feather($item['icon'] ?? null) }}"></i></div>
                        <h3 class="twm-why-title">{{ $item['title'] ?? '' }}</h3>
                        <p class="twm-why-desc">{{ $item['text'] ?? '' }}</p>
                    </div>
                </div>
            @endforeach
        </div>

    </div>
</div>
