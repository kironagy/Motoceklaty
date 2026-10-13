@php
    $isActive = function (?string $url): bool {
        $path = trim((string) parse_url(site_link($url), PHP_URL_PATH), '/');

        return $path === '' ? request()->is('/') : request()->is($path, $path . '/*');
    };
@endphp
<header dir="rtl" class="site-header header-style-1 mobile-sider-drawer-menu light-hdr">

    <div class="header-middle-wraper sticky-header">
        <div class="header-middle main-bar col-12 d-flex justify-content-center justify-content-lg-around">
            <div class="logo-header">
                <div class="logo-header-inner logo-header-one">
                    <a href="{{ route('home') }}">
                        <img src="{{ site_url(site('general.logo')) }}" class="col-12" alt="{{ site('general.site_name') }}">
                    </a>
                </div>
            </div>

            <div class="header-info-wraper">
                <div class="main-bar-wraper navbar-expand-lg">
                    <div class="header-bottom">
                        <div class="container-block clearfix">
                            <div class="navigation-bar">
                                <button id="mobile-side-drawer" data-target=".header-nav" data-toggle="collapse"
                                    type="button" class="navbar-toggler collapsed">
                                    <span class="sr-only">Toggle navigation</span>
                                    <span class="icon-bar icon-bar-first"></span>
                                    <span class="icon-bar icon-bar-two"></span>
                                    <span class="icon-bar icon-bar-three"></span>
                                </button>

                                <div class="nav-animation header-nav navbar-collapse collapse d-flex justify-content-between">
                                    <ul class="nav navbar-nav">
                                        @foreach (site('general.menu', []) as $item)
                                            <li @class(['active' => $isActive($item['url'] ?? null)])>
                                                <a class="fs-5" href="{{ site_link($item['url'] ?? null) }}">{{ $item['label'] ?? '' }}</a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @if (site('general.show_header_email') && site('general.email'))
                <div class="extra-nav header-1-nav">
                    <div class="extra-cell one">
                        <ul class="wt-topbar-left-info border p-2 rounded">
                            <li>
                                <a href="mailto:{{ site('general.email') }}">
                                    <span><i class="feather feather-mail"></i></span>{{ site('general.email') }}
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            @endif

        </div>
    </div>

</header>
