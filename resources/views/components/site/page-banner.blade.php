{{-- Inner page banner. $breadcrumbs: [label => url|null] after "الرئيسية". --}}
@props(['image' => null, 'title' => null, 'breadcrumbs' => []])

<div class="wt-bnr-inr site-bg-dark twm-primary-overlay-wrap" @if ($image) style="background-image: url('{{ site_url($image) }}');" @endif>
    <div class="twm-primary-overlay"></div>
    <div class="container">
        <div class="wt-bnr-inr-entry" dir="rtl">
            <div class="banner-title-outer">
                <div class="banner-title-name">
                    <h2 class="wt-title">{{ $title }}</h2>
                </div>
                <ul class="wt-breadcrumb breadcrumb-style-2">
                    <li><a href="{{ route('home') }}">الرئيسية</a></li>
                    @foreach ($breadcrumbs as $label => $url)
                        @if ($url)
                            <li><a href="{{ $url }}">{{ $label }}</a></li>
                        @else
                            <li>{{ $label }}</li>
                        @endif
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
