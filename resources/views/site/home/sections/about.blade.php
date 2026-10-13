<div class="section-full site-bg-white p-t150 p-b120 twm-abus-section-wrap wow fadeInDown" data-wow-offset="100" data-wow-delay="0.2">
    <div class="container">
        <div class="row twm-abus-section">

            <div class="col-lg-7 col-md-12">
                <div class="twm-abus-left">
                    <div class="twm-media">
                        @if (site('home.about.image'))
                            <img src="{{ site_url(site('home.about.image')) }}" alt="{{ site('home.about.title') }}">
                        @endif
                        @if (site('home.about.video_url'))
                            <div class="twm-abus-video">
                                <a href="{{ site('home.about.video_url') }}" class="mfp-video"><i class="icon fa fa-play"></i></a>
                            </div>
                        @endif
                        @if (site('home.about.year'))
                            <div class="twm-abus-year-section">
                                <div class="tem-abus-year-content">
                                    <span>{{ site('home.about.year_label') }}</span>
                                    <h2 class="year-title">{{ site('home.about.year') }}</h2>
                                </div>
                            </div>
                        @endif
                    </div>
                    @if (site('home.about.image2'))
                        <div class="twm-media2">
                            <img src="{{ site_url(site('home.about.image2')) }}" class="col-12" alt="{{ site('home.about.title') }}">
                        </div>
                    @endif
                </div>
            </div>

            <div class="col-lg-5 col-md-12">
                <x-site.section-head class="aside-section"
                    :small="site('home.about.small_title')"
                    :title="site('home.about.title')"
                    :text="site('home.about.text')" />

                @if (count(site('home.about.bullets', [])))
                    <div class="twm-inline-list2" dir="rtl">
                        <ul>
                            @foreach (site('home.about.bullets', []) as $bullet)
                                <li>{{ is_array($bullet) ? ($bullet['text'] ?? '') : $bullet }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (site('home.about.button_text'))
                    <div class="twm-btn-left">
                        <a href="{{ site_link(site('home.about.button_url')) }}" class="site-button"><em>{{ site('home.about.button_text') }}</em></a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
