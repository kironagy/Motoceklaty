@if ((site('about.testimonial.enabled') && $review) || site('about.cta.enabled'))
    <div class="section-full p-t80 p-b100 site-bg-white">
        <div class="container">

            @if (site('about.testimonial.enabled') && $review)
                <x-site.section-head align="center" small-class="site-accent-cyan"
                    :small="site('about.testimonial.small_title')"
                    :title="site('about.testimonial.title')" />

                <div class="row justify-content-center" dir="rtl">
                    <div class="col-lg-8 col-md-10">
                        <div class="site-about-quote">
                            <div class="site-about-quote-icon"><i class="fa fa-quote-right"></i></div>
                            <p>"{{ $review->review }}"</p>
                            <h4>{{ $review->name }}</h4>
                            @if ($review->position)
                                <span>{{ $review->position }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if (site('about.cta.enabled'))
                <div class="row justify-content-center" dir="rtl">
                    <div class="col-lg-8 col-md-10">
                        <div class="twm-cta-box-modern">
                            <h3>{{ site('about.cta.title') }}</h3>
                            <p>{{ site('about.cta.text') }}</p>
                            @if (site('about.cta.button_text'))
                                <a href="{{ site_link(site('about.cta.button_url')) }}" class="twm-cta-btn-modern">{{ site('about.cta.button_text') }}</a>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

        </div>
    </div>
@endif
