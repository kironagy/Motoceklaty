<div class="section-full twm-explore-section-wrap site-bg-primary mt-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-xl-4 col-lg-12">
                <div class="section-head left text-center text-lg-end">
                    <div class="twm-sm-title left site-text-white fs-4">{{ site('home.explore.small_title') }}</div>
                    <h2 class="twm-large-title site-text-white fs-2">{{ site('home.explore.title') }}</h2>
                </div>
            </div>
            <div class="col-xl-5 col-lg-12">
                @if (site('home.explore.image'))
                    <div class="twm-mid-section-car">
                        <div class="twm-media">
                            <img src="{{ site_url(site('home.explore.image')) }}" alt="{{ site('home.explore.title') }}">
                        </div>
                    </div>
                @endif
            </div>
            <div class="col-xl-3 col-lg-12 text-center">
                @if (site('home.explore.button_text'))
                    <div class="twm-mid-section-btn">
                        <a href="{{ site_link(site('home.explore.button_url')) }}" class="site-button text-center rounded">
                            <em>{{ site('home.explore.button_text') }}</em>
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
