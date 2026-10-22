<footer class="site-footer" id="contact">
<div class="contact-strip">
<div class="map-art">
<img alt="" aria-hidden="true" class="world-map" src="{{ asset('motogate-v2/assets/images/world-map.png') }}"/><img alt="" aria-hidden="true" class="icon bi bi-location-pin map-pin" height="24" src="{{ asset('motogate-v2/assets/images/icons/location-pin.png') }}" width="24"/>
</div>
<div class="contact-copy" dir="rtl">
<h2>{{ site('general.design.footer.text_01') }}</h2>
<p>{{ site('general.design.footer.text_02') }}<br/>{{ site('general.design.footer.text_03') }}</p>
<a class="button button-orange" dir="ltr" href="{{ site_tel(site('general.phone')) }}"><img class="icon bi bi-telephone-fill" src="{{ asset('motogate-v2/assets/images/icons/telephone-fill.svg') }}" alt="" aria-hidden="true" width="24" height="24">{{ site('general.phone') }}</a>
</div>
<a aria-label="تواصل عبر واتساب" class="contact-chat" href="{{ site_whatsapp(site('general.whatsapp')) }}"><img alt="" aria-hidden="true" class="icon bi bi-chat-square-dots" height="24" src="{{ asset('motogate-v2/assets/images/icons/chat-square-dots.svg') }}" width="24"/></a>
<div class="socials">@foreach (site('general.social', []) as $item)@php
$network = collect(['facebook', 'instagram', 'tiktok', 'whatsapp', 'youtube'])->first(fn ($key) => str_contains(strtolower(($item['icon'] ?? '') . ' ' . ($item['url'] ?? '')), $key));
@endphp<a class="social {{ $network }}" href="{{ $item['url'] ?? '#' }}" aria-label="{{ ['facebook' => 'فيسبوك', 'instagram' => 'إنستجرام', 'tiktok' => 'تيك توك', 'whatsapp' => 'واتساب', 'youtube' => 'يوتيوب'][$network] ?? 'تواصل اجتماعي' }}" target="_blank" rel="noopener noreferrer">@if ($network)<img class="icon bi bi-{{ $network }}" src="{{ asset('motogate-v2/assets/images/icons/' . $network . '.svg') }}" alt="" aria-hidden="true" width="24" height="24">@else<i class="{{ $item['icon'] ?? '' }}" aria-hidden="true"></i>@endif</a>
@endforeach
</div>
</div>
<div class="footer-bottom">
<a class="logo" href="{{ route('home') }}"><img alt="MotoGate" height="116" src="{{ site_url(site('general.footer_logo')) }}" width="376"/></a>
<nav aria-label="روابط أسفل الصفحة">@foreach (site('general.footer.links', []) as $item)<a href="{{ $item['url'] ?? '/' }}">{{ $item['label'] ?? '' }}</a>
@endforeach
</nav>
<small dir="rtl">© {{ date('Y') }} {{ site('general.footer.copyright_brand') }}<br>{{ site('general.footer.copyright_text') }}</small>
</div>
</footer>