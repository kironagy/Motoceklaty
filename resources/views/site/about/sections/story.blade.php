<div class="about-intro about-section">
    <div class="container">
        <div class="row align-items-center" dir="rtl">

            <div class="col-lg-5 col-md-12 m-b30">
                <div class="site-about-brand-card"><img class="about-story-photo" src="{{ site_url(site('home.about.image')) }}" alt="{{ site('about.story.card_title') }}" loading="lazy">
                    <div class="site-about-brand-icon">
                        <i class="feather {{ site_feather(site('about.story.card_icon'), 'feather-compass') }}"></i>
                    </div>
                    <h3>{{ site('about.story.card_title') }}</h3>
                    <p>{{ site('about.story.card_subtitle') }}</p>
                    @if (site('about.story.card_note'))
                        <div class="site-about-brand-note"><span>{{ site('about.story.card_note') }}</span></div>
                    @endif
                </div>
            </div>

            <div class="col-lg-7 col-md-12 m-b30">
                <div class="section-head left m-b20">
                    @if (site('about.story.small_title'))
                        <div class="twm-sm-title left" style="color: #00bcd4;">{{ site('about.story.small_title') }}</div>
                    @endif
                    <h2 class="twm-large-title site-text-dark" style="font-size: 30px; font-weight: 800;">{{ site('about.story.title') }}</h2>
                </div>
                <div class="site-about-story-body">{!! site('about.story.body') !!}</div>
            </div>

        </div>
    </div>
</div>
