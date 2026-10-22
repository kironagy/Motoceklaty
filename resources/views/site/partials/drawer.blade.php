<aside aria-labelledby="menuTitle" class="offcanvas offcanvas-end clean-drawer" id="mobileMenu" tabindex="-1">
<div class="offcanvas-header">
<h2 class="visually-hidden" id="menuTitle">القائمة الرئيسية</h2>
<a class="drawer-logo" href="{{ route('home') }}"><img alt="MotoGate" class="" height="116" src="{{ site_url(site('general.logo')) }}" width="376"/></a><button aria-label="إغلاق القائمة" class="drawer-close" data-bs-dismiss="offcanvas" type="button">
<svg aria-hidden="true" height="25" viewBox="0 0 24 24" width="25">
<path d="M5 5L19 19M19 5L5 19" stroke="currentColor" stroke-width="1.5"></path>
</svg>
</button>
</div>
<div class="drawer-body">
<nav aria-label="قائمة الموبايل" class="drawer-links">@foreach (site('general.menu', []) as $item)<a href="{{ $item['url'] ?? '/' }}" @if(url()->current() === url($item['url'] ?? '/')) aria-current="page" @endif>{{ $item['label'] ?? '' }}</a>@endforeach
<a href="{{ route('tracking.index') }}" @if(request()->routeIs('tracking.index')) aria-current="page" @endif>{{ site('tracking.title') }}</a></nav>
<div class="drawer-footer">
<a class="button button-outline" href="{{ route('installment.index') }}">احسب قسطك الآن</a>
</div>
</div>
</aside>
