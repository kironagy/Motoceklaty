<header class="site-header">
<div class="header-inner">
<button aria-controls="mobileMenu" aria-label="فتح القائمة" class="menu-toggle" data-bs-target="#mobileMenu" data-bs-toggle="offcanvas" type="button">
<img alt="" aria-hidden="true" class="icon bi bi-list" height="24" src="{{ asset('motogate-v2/assets/images/icons/list.svg') }}" width="24"/></button><a class="header-calc button button-orange" href="{{ route('installment.index') }}">احسب قسطك
              <img alt="" aria-hidden="true" class="icon bi bi-calculator" height="24" src="{{ asset('motogate-v2/assets/images/icons/calculator.svg') }}" width="24"/></a>
<nav aria-label="القائمة الرئيسية" class="desktop-nav">@foreach (site('general.menu', []) as $item)<a href="{{ $item['url'] ?? '/' }}" @if(url()->current() === url($item['url'] ?? '/')) aria-current="page" @endif>{{ $item['label'] ?? '' }}</a>@endforeach
<a href="{{ route('tracking.index') }}" @if(request()->routeIs('tracking.index')) aria-current="page" @endif>{{ site('tracking.title') }}</a></nav>
<button aria-label="البحث عن دراجة" class="search-toggle" data-bs-target="#searchModal" data-bs-toggle="modal" type="button">
<img alt="" aria-hidden="true" class="icon bi bi-search" height="24" src="{{ asset('motogate-v2/assets/images/icons/search.svg') }}" width="24"/></button><a aria-label="موتوجيت — الرئيسية" class="logo" href="{{ route('home') }}"><img alt="MotoGate" height="116" src="{{ site_url(site('general.logo')) }}" width="376"/></a>
</div>
</header>