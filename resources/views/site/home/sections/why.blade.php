<div class="section-full site-bg-white twm-w-chooseus-section-wrap wow fadeInDown" data-wow-offset="100" data-wow-delay="0.2">
    <div class="container">
        <div class="row twm-w-chooseus-section">
            <div class="col-lg-5 col-md-12">
                <x-site.section-head
                    :small="site('home.why.small_title')"
                    :title="site('home.why.title')"
                    :text="site('home.why.text')" />

                <div class="twm-list-icon-style1" dir="rtl">
                    <ul>
                        @foreach (site('home.why.items', []) as $item)
                            <li>
                                <div class="twm-list-icon-style-bx">
                                    @if (! empty($item['icon']))
                                        <div class="twm-icon-bx"><span><img src="{{ site_url($item['icon']) }}" alt="{{ $item['title'] ?? '' }}"></span></div>
                                    @endif
                                    <div class="twm-icon-bx-detail">
                                        <h3 class="twm-title">{{ $item['title'] ?? '' }}</h3>
                                        <p>{{ $item['text'] ?? '' }}</p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="col-lg-7 col-md-12">
                <div class="twm-w-chooseus-media">
                    @if (site('home.why.image'))
                        <div class="twm-media">
                            <img src="{{ site_url(site('home.why.image')) }}" alt="{{ site('home.why.title') }}">
                        </div>
                    @endif
                    @if (site('home.why.help_phone'))
                        <div class="twm-need-help-bx" dir="rtl">
                            <div class="twm-need-help-content">
                                <span>{{ site('home.why.help_text') }}</span>
                                <h3 class="twm-title"><a href="{{ site_tel(site('home.why.help_phone')) }}" style="color: inherit;">{{ site('home.why.help_phone') }}</a></h3>
                            </div>
                            @if (site('home.why.help_icon'))
                                <div class="twm-need-help-icon"><img src="{{ site_url(site('home.why.help_icon')) }}" alt=""></div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
