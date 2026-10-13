<div class="section-full p-t80 p-b80 site-bg-white">
    <div class="container">

        <x-site.section-head align="center" small-class="site-accent-cyan"
            :small="site('about.official.small_title')"
            :title="site('about.official.title')" />

        <div class="row justify-content-center" dir="rtl">
            <div class="col-lg-10 col-md-12">
                <div class="twm-official-info-card">
                    @foreach (site('about.official.items', []) as $item)
                        <div class="twm-official-info-item">
                            <span class="info-label">{{ $item['label'] ?? '' }}</span>
                            <span class="info-value">{{ $item['value'] ?? '' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

    </div>
</div>
