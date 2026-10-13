{{--
    Machine card. The whole card is one link (stretched title link - no
    nested anchors). The photo is never cropped: object-fit contain over a
    blurred copy of itself, so any background (white studio or dark
    showroom) fills the frame cleanly.
--}}
@props(['machine'])

@php
    $url = route('machines.show', $machine->id);
    $image = $machine->displayImageUrl();
    $category = $machine->categoryLabel();
    $features = array_slice($machine->featureTitles(), 0, 3);
    $isOffer = $machine->type === 'offer' && $machine->new_price;
    $price = $isOffer ? $machine->new_price : $machine->cash_price;
    $showPrice = site('showroom.card.show_price') && $price;
@endphp

<article class="mg-card">
    <div class="mg-card__media" @if ($image) style="--mg-card-img: url('{{ $image }}');" @endif>
        @if ($image)
            <img src="{{ $image }}" alt="{{ $machine->name }}" loading="lazy" decoding="async">
        @else
            <i class="fa-solid fa-motorcycle mg-card__placeholder" aria-hidden="true"></i>
        @endif

        <div class="mg-card__badges">
            @if ($isOffer)
                <span class="mg-card__badge mg-card__badge--offer">{{ site('showroom.detail.offer_label') }}</span>
            @endif
            @if ($machine->cc)
                <span class="mg-card__badge">{{ $machine->cc }} CC</span>
            @endif
        </div>
    </div>

    <div class="mg-card__body" dir="rtl">
        @if ($machine->brand?->name || $category)
            <div class="mg-card__meta">
                @if ($machine->brand?->name)
                    <span class="mg-card__brand">{{ trim($machine->brand->name) }}</span>
                @endif
                @if ($category)
                    <span class="mg-card__category">{{ $category }}</span>
                @endif
            </div>
        @endif

        <h3 class="mg-card__title">
            <a href="{{ $url }}" class="mg-card__link">{{ $machine->name }}</a>
        </h3>

        @if ($features)
            <ul class="mg-card__features">
                @foreach ($features as $feature)
                    <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>{{ $feature }}</span></li>
                @endforeach
            </ul>
        @endif

        <div class="mg-card__footer">
            @if ($showPrice)
                <div class="mg-card__price">
                    <span>{{ site('showroom.card.price_prefix') }}</span>
                    <strong>
                        {{ number_format((float) $price) }} <small>{{ site('showroom.card.currency') }}</small>
                    </strong>
                    @if ($isOffer && $machine->old_price)
                        <del>{{ number_format((float) $machine->old_price) }}</del>
                    @endif
                </div>
            @endif
            <span class="mg-card__cta" aria-hidden="true">
                {{ site('showroom.card.details_label') }} <i class="fa-solid fa-arrow-left"></i>
            </span>
        </div>
    </div>
</article>
