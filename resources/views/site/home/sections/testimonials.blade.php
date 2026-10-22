<div class="section-full p-t150 site-bg-white twm-testimonial-section-wrap wow fadeInDown" data-wow-offset="100" data-wow-delay="0.2">
    <div class="container">

        <div class="row">
            <div class="col-lg-8 col-md-12">
                <x-site.section-head
                    :small="site('home.testimonials.small_title')"
                    :title="site('home.testimonials.title')" />
            </div>
        </div>

        <div class="section-content">
            <div class="reviews-grid">
                @foreach ($reviews as $review)
                    <div class="item">
                        <x-site.testimonial-card :review="$review" />
                    </div>
                @endforeach
            </div>
        </div>

    </div>
</div>
