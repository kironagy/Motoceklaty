@props([
    'small' => null,
    'title' => null,
    'text' => null,
    'align' => 'left',
    'titleClass' => 'site-text-dark',
    'smallClass' => '',
    'tag' => 'h2',
])

@if ($small || $title || $text)
    <div {{ $attributes->merge(['class' => "section-head {$align}", 'dir' => 'rtl']) }}>
        @if ($small)
            <div class="twm-sm-title left {{ $smallClass }}">{{ $small }}</div>
        @endif
        @if ($title)
            <{{ $tag }} class="twm-large-title {{ $titleClass }}">{{ $title }}</{{ $tag }}>
        @endif
        @if ($text)
            <div class="section-head-detail">{{ $text }}</div>
        @endif
        {{ $slot }}
    </div>
@endif
