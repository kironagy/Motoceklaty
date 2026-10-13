<footer class="mg-footer" dir="rtl"
    @if (site('general.footer.bg_image')) style="--mg-footer-bg: url('{{ site_url(site('general.footer.bg_image')) }}');" @endif>
    <div class="container">

        @if (site('general.footer.newsletter_enabled'))
            <div class="mg-footer__newsletter" id="newsletter">
                <div class="mg-footer__newsletter-text">
                    <span class="mg-footer__newsletter-icon"><i class="feather feather-mail"></i></span>
                    <p>{{ site('general.footer.newsletter_text') }}</p>
                </div>
                <div class="mg-footer__newsletter-form">
                    <form method="POST" action="{{ route('newsletter.store') }}">
                        @csrf
                        <input name="email" type="email" required
                            aria-label="{{ site('general.footer.newsletter_placeholder') }}"
                            placeholder="{{ site('general.footer.newsletter_placeholder') }}">
                        <button type="submit">اشترك <i class="feather feather-arrow-left"></i></button>
                    </form>
                    @if (session('newsletter_status'))
                        <div class="site-form-note mt-2">{{ session('newsletter_status') }}</div>
                    @endif
                    @error('email', 'newsletter')
                        <div class="site-form-note is-error mt-2">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        @endif

        <div class="mg-footer__grid">

            <div class="mg-footer__col mg-footer__about">
                <a href="{{ route('home') }}" class="mg-footer__logo">
                    <img src="{{ site_url(site('general.footer_logo')) }}" alt="{{ site('general.site_name') }}">
                </a>
                @if (site('general.footer.about_text'))
                    <p>{{ site('general.footer.about_text') }}</p>
                @endif
                <x-site.social-links />
            </div>

            <div class="mg-footer__col">
                <h3 class="mg-footer__title">{{ site('general.footer.links_title') }}</h3>
                <ul class="mg-footer__links">
                    @foreach (site('general.footer.links', []) as $link)
                        <li><a href="{{ site_link($link['url'] ?? null) }}">{{ $link['label'] ?? '' }}</a></li>
                    @endforeach
                </ul>
            </div>

            <div class="mg-footer__col">
                <h3 class="mg-footer__title">{{ site('general.footer.categories_title') }}</h3>
                <ul class="mg-footer__links">
                    @foreach (site('home.categories.items', []) as $category)
                        <li>
                            <a href="{{ route('machines.index', array_filter(['category' => $category['category'] ?? null])) }}">{{ $category['label'] ?? '' }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="mg-footer__col">
                <h3 class="mg-footer__title">{{ site('general.footer.hours_title') }}</h3>
                <ul class="mg-footer__hours">
                    @foreach (site('general.working_hours', []) as $row)
                        <li><span>{{ $row['day'] ?? '' }}</span><strong>{{ $row['time'] ?? '' }}</strong></li>
                    @endforeach
                </ul>
                <ul class="mg-footer__contact">
                    @if (site('general.phone'))
                        <li><i class="feather feather-phone-call"></i><a href="{{ site_tel(site('general.phone')) }}" dir="ltr">{{ site('general.phone') }}</a></li>
                    @endif
                    @if (site('general.email'))
                        <li><i class="feather feather-mail"></i><a href="mailto:{{ site('general.email') }}">{{ site('general.email') }}</a></li>
                    @endif
                    @if (site('general.address'))
                        <li><i class="feather feather-map-pin"></i><span>{{ site('general.address') }}</span></li>
                    @endif
                </ul>
            </div>

        </div>
    </div>

    <div class="mg-footer__bottom">
        <div class="container">
            <span>© {{ now()->year }} <strong>{{ site('general.footer.copyright_brand') }}</strong> {{ site('general.footer.copyright_text') }}</span>
        </div>
    </div>
</footer>
