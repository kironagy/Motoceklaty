@php
    // A step saved without an icon (picked in the dashboard) falls back to one by its position.
    $stepIcons = ['fa-solid fa-motorcycle', 'fa-solid fa-route', 'fa-solid fa-wallet', 'fa-solid fa-key'];
@endphp

<section class="mg-steps section-full p-t120 p-b90 wow fadeInDown" data-wow-offset="100" data-wow-delay="0.2" dir="rtl">
    <div class="container">

        <x-site.section-head align="center"
            :small="site('home.steps.small_title')"
            :title="site('home.steps.title')" />

        <ol class="mg-steps__list">
            @foreach (site('home.steps.items', []) as $item)
                <li class="mg-step">
                    <div class="mg-step__marker">
                        <span class="mg-step__icon"><i class="{{ ($item['icon'] ?? null) ?: $stepIcons[($loop->index) % count($stepIcons)] }}"></i></span>
                        <span class="mg-step__num">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    </div>
                    <h3 class="mg-step__title">{{ $item['title'] ?? '' }}</h3>
                    <p class="mg-step__text">{{ $item['text'] ?? '' }}</p>
                </li>
            @endforeach
        </ol>

        @if (site('home.steps.image'))
            <div class="mg-steps__showcase">
                <img src="{{ site_url(site('home.steps.image')) }}" alt="{{ site('home.steps.title') }}" loading="lazy">
                <div class="mg-steps__cta">
                    <div class="mg-steps__cta-text">
                        <strong>جاهز تبدأ؟</strong>
                        <span>احسب قسطك في دقيقة أو قدّم طلبك مباشرة.</span>
                    </div>
                    <div class="mg-steps__cta-actions">
                        <a href="{{ route('installment.index') }}" class="mg-btn mg-btn--primary"><i class="fa-solid fa-calculator"></i> احسب قسطك</a>
                        <a href="{{ route('machines.index') }}" class="mg-btn mg-btn--ghost">تصفح المعرض <i class="fa-solid fa-arrow-left"></i></a>
                    </div>
                </div>
            </div>
        @endif

    </div>
</section>
