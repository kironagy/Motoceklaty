@if (site('general.loader.enabled'))
    <div class="loading-area">
        <div class="loading-box"></div>
        <div class="loading-pic">
            <video class="loader-video" autoplay muted playsinline>
                @if (site('general.loader.video_mobile'))
                    <source src="{{ site_url(site('general.loader.video_mobile')) }}" type="video/mp4" media="(max-width: 768px)">
                @endif
                @if (site('general.loader.video'))
                    <source src="{{ site_url(site('general.loader.video')) }}" type="video/mp4">
                @endif
            </video>
        </div>
    </div>
@endif
