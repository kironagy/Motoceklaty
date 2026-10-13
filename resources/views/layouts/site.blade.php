@php
    // @section('x', value) stores e(value); escape the fallbacks the same way and print raw.
    $pageTitle = trim($__env->yieldContent('title')) ?: e(site('general.site_name'));
    $metaDescription = trim($__env->yieldContent('meta_description')) ?: e(site('general.seo.description'));
    $ogImage = trim($__env->yieldContent('og_image')) ?: e(site_url(site('general.seo.og_image')));
@endphp
<!DOCTYPE html>
<html lang="ar">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="keywords" content="{{ site('general.seo.keywords') }}">
    <meta name="author" content="{{ site('general.site_name') }}">
    <meta name="robots" content="index, follow">
    <meta name="description" content="{!! $metaDescription !!}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="{!! $pageTitle !!}">
    <meta property="og:description" content="{!! $metaDescription !!}">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($ogImage)
        <meta property="og:image" content="{!! $ogImage !!}">
    @endif

    <link rel="shortcut icon" type="image/x-icon" href="{{ site_url(site('general.favicon')) }}">
    <title>{!! $pageTitle !!}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('motogate/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/animate.min.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="{{ asset('motogate/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/lc_lightbox.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/owl.carousel.min.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/magnific-popup.min.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/swiper-bundle.min.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/bootstrap-datetimepicker.min.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/moto-slider.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/jquery.mCustomScrollbar.min.css') }}">
    <link rel="stylesheet" href="{{ site_asset('css/site-extras.css') }}">

    @stack('styles')

    {!! site('general.integrations.head_code') !!}
</head>

<body>

    @include('site.partials.loader')

    <div class="page-wraper">

        @include('site.partials.header')

        <div class="page-content">
            @yield('content')
        </div>

        @include('site.partials.footer')

        <button class="scroltop"><span class="fa fa-angle-up relative" id="btn-vibrate"></span></button>

    </div>

    <script src="{{ asset('motogate/js/jquery-3.7.1.min.js') }}"></script>
    <script src="{{ asset('motogate/js/popper.min.js') }}"></script>
    <script src="{{ asset('motogate/js/bootstrap.min.js') }}"></script>
    <script src="{{ asset('motogate/js/magnific-popup.min.js') }}"></script>
    <script src="{{ asset('motogate/js/waypoints.min.js') }}"></script>
    <script src="{{ asset('motogate/js/waypoints-sticky.min.js') }}"></script>
    <script src="{{ asset('motogate/js/counterup.min.js') }}"></script>
    <script src="{{ asset('motogate/js/isotope.pkgd.min.js') }}"></script>
    <script src="{{ asset('motogate/js/imagesloaded.pkgd.min.js') }}"></script>
    <script src="{{ asset('motogate/js/owl.carousel.min.js') }}"></script>
    <script src="{{ asset('motogate/js/theia-sticky-sidebar.js') }}"></script>
    <script src="{{ asset('motogate/js/lc_lightbox.lite.js') }}"></script>
    <script src="{{ asset('motogate/js/swiper-bundle.min.js') }}"></script>
    <script src="{{ asset('motogate/js/wow.min.js') }}"></script>
    <script src="{{ asset('motogate/js/custom.js') }}"></script>
    <script src="{{ asset('motogate/js/tickerNews.min.js') }}"></script>
    <script src="{{ asset('motogate/js/moment.min.js') }}"></script>
    <script src="{{ asset('motogate/js/bootstrap-datetimepicker.min.js') }}"></script>
    <script src="{{ asset('motogate/js/jquery.mCustomScrollbar.concat.min.js') }}"></script>
    <script src="{{ asset('motogate/js/moto-slider.js') }}"></script>

    @stack('scripts')

    @if (site('general.integrations.protect_images'))
        <script>
            document.addEventListener('contextmenu', function (event) {
                if (event.target.tagName === 'IMG') event.preventDefault();
            });
        </script>
    @endif

    @if (site('general.integrations.tawk_enabled') && site('general.integrations.tawk_src'))
        <script>
            var Tawk_API = Tawk_API || {}, Tawk_LoadStart = new Date();
            (function () {
                var s1 = document.createElement('script'), s0 = document.getElementsByTagName('script')[0];
                s1.async = true;
                s1.src = @json(site('general.integrations.tawk_src'));
                s1.charset = 'UTF-8';
                s1.setAttribute('crossorigin', '*');
                s0.parentNode.insertBefore(s1, s0);
            })();
        </script>
    @endif

    {!! site('general.integrations.footer_code') !!}
</body>

</html>
