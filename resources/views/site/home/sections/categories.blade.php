<div class="section-full p-t150 site-bg-white twm-categories-section-wrap wow fadeInDown" data-wow-offset="100" data-wow-delay="0.2">
    <div class="container">

        <div class="row">
            <div class="col-lg-8 col-md-12">
                <x-site.section-head
                    :small="site('home.categories.small_title')"
                    :title="site('home.categories.title')" />
            </div>
        </div>

        <div class="section-content">
            <div class="owl-carousel twm-categories-carousel next-prev-top-right">
                @foreach (site('home.categories.items', []) as $item)
                    <div class="item">
                        <div class="twm-categories-type">
                            <div class="twm-media">
                                @if (! empty($item['image']))
                                    <img src="{{ site_url($item['image']) }}" alt="{{ $item['label'] ?? '' }}">
                                @endif
                                <div class="twm-media-link">
                                    <a href="{{ route('machines.index', array_filter(['category' => $item['category'] ?? null])) }}" class="twm-media-link-content">{{ $item['label'] ?? '' }}</a>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

    </div>
</div>
