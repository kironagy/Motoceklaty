@props(['title' => null])

@if (count(site('general.social', [])))
    <div {{ $attributes->merge(['class' => 'twm-social']) }}>
        @if ($title)
            <h3 class="twm-title">{{ $title }}</h3>
        @endif
        <ul>
            @foreach (site('general.social', []) as $social)
                @if (! empty($social['url']))
                    <li><a href="{{ $social['url'] }}" target="_blank" rel="noopener"><i class="{{ ($social['icon'] ?? null) ?: 'fa-solid fa-link' }}"></i></a></li>
                @endif
            @endforeach
        </ul>
    </div>
@endif
