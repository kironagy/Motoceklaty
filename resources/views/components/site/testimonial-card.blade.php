@props(['review'])

<div class="twm-testimonial2" dir="rtl">
    <div class="twm-testimonial-head">
        @if ($review->image)
            <div class="media-pic">
                <img src="{{ $review->imageUrl() }}" alt="{{ $review->name }}" loading="lazy">
            </div>
        @endif
        <div class="twm-author-detail">
            <h3 class="twm-title">{{ $review->name }}</h3>
            @if ($review->position)
                <div class="twm-position">{{ $review->position }}</div>
            @endif
        </div>
    </div>

    <div class="twm-testimonial-detail">
        <p>{{ $review->review }}</p>
        <div class="twm-rating-wrap">
            @for ($i = 0; $i < max(0, min(5, (int) $review->rating)); $i++)
                <span><i class="fa fa-star"></i></span>
            @endfor
        </div>
    </div>

    <div class="twm-quote-icon">
        <img src="{{ asset('motogate/images/quote.png') }}" alt="">
    </div>
</div>
