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
    <meta name="viewport" content="width=device-width, initial-scale=1">
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

    @hasSection('design_css')
    {{-- Same stylesheets, same order, as the matching page in motogate-2/. --}}
    <meta name="theme-color" content="#080b0c">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/vendor/bootstrap.min.css') }}">
    @foreach (['fonts', 'style', 'responsiv', 'layers', 'components', 'updates'] as $sheet)
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/' . $sheet . '.css') }}">
    @endforeach
    @foreach (array_diff(array_filter(explode(',', trim($__env->yieldContent('design_css')))), ['base']) as $sheet)
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/' . trim($sheet) . '.css') }}">
    @endforeach
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/navigation.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/integration.css') . '?v=' . filemtime(public_path('motogate-v2/assets/css/integration.css')) }}">
    @else
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/vendor/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="{{ site_asset('css/site-extras.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate/css/swiper-bundle.min.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/responsiv.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/layers.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/components.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/updates.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/about.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/pages.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/calculate.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/navigation.css') }}">
    <link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/integration.css') . '?v=' . filemtime(public_path('motogate-v2/assets/css/integration.css')) }}">
    @endif
    @stack('styles')

    @unless (request()->routeIs('tracking.index')){!! site('general.integrations.head_code') !!}@endunless
</head>

@hasSection('design_css')
<body class="@yield('body_class')">
@include('site.partials.drawer')
@hasSection('design_shell')
<a class="skip-link" href="#main">انتقل إلى المحتوى</a>
<div class="@yield('design_shell')">
@yield('content')
@include('site.partials.footer')
</div>
@else
@include('site.partials.site-header')
@yield('content')
@include('site.partials.footer')
@endif
@else
<body class="motogate-v2 @yield('body_class', 'legacy-page')">

<a class="skip-link" href="#main">انتقل إلى المحتوى</a>


    <div class="page-wraper {{ request()->routeIs('machines.index') ? 'gallery-shell' : 'home-shell' }}">

        @include('site.partials.header')

        <div class="page-content" id="main">
            @yield('content')
        </div>

        @include('site.partials.footer')



    </div>
@endif

    @stack('modals')
    <div class="modal fade" id="searchModal" tabindex="-1" aria-labelledby="searchTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title fs-4" id="searchTitle">ابحث عن دراجتك</h2>
            <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
          </div>
          <div class="modal-body" dir="rtl">
            <label for="bikeSearch" class="form-label">اسم الماركة أو الموديل</label><input id="bikeSearch" class="form-control" type="search" placeholder="مثال: Keeway أو TNT" autocomplete="off" />
            <div id="searchResults" class="search-results" aria-live="polite"></div>
          </div>
        </div>
      </div>
    </div>
    @php
        $searchMachines = \App\Models\Machine::query()->where('is_active', true)->with('brand:id,name')->orderBy('name')->get(['id', 'name', 'brand_id'])
            ->map(fn ($machine) => ['label' => \Illuminate\Support\Str::squish(($machine->brand?->name ?? '') . ' ' . $machine->name), 'url' => route('machines.show', $machine)]);
    @endphp
    <script type="application/json" id="searchMachines">{!! json_encode($searchMachines, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>

    <script src="{{ asset('motogate-v2/assets/vendor/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('motogate/js/swiper-bundle.min.js') }}"></script>
    <script src="{{ asset('motogate-v2/assets/js/integration.js') . '?v=' . filemtime(public_path('motogate-v2/assets/js/integration.js')) }}"></script>
    @stack('scripts')

    @if (site('general.integrations.protect_images'))
        <script>
            document.addEventListener('contextmenu', function (event) {
                if (event.target.tagName === 'IMG') event.preventDefault();
            });
        </script>
    @endif


    @unless (request()->routeIs('tracking.index')){!! site('general.integrations.footer_code') !!}@endunless
</body>

</html>
