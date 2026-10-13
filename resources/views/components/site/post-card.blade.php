{{-- Blog card. style: classic (blog.html) | grid (blog-grid.html). --}}
@props(['post', 'style' => 'classic'])

@php
    $url = route('blog.show', $post);
    $date = $post->published_at ?? $post->created_at;
@endphp

@if ($style === 'grid')
    <div class="twm-blog-grid-2">
        <div class="wt-post-media">
            <a href="{{ $url }}"><img src="{{ $post->coverUrl() }}" alt="{{ $post->title }}" loading="lazy"></a>
        </div>
        <div class="twm-post-info-wrap">
            <div class="post-date-wrap">
                <div class="post-date">
                    <span class="date">{{ $date->format('d') }}</span>
                    <span class="month">{{ $date->locale('ar')->translatedFormat('F') }}</span>
                </div>
            </div>
            <div class="twm-post-info">
                @if ($post->author_name)
                    <div class="post-author"><a class="au-name" href="{{ $url }}">{{ site('blog.detail.author_prefix') }} {{ $post->author_name }}</a></div>
                @endif
                <h3 class="post-title"><a href="{{ $url }}">{{ $post->title }}</a></h3>
            </div>
        </div>
    </div>
@else
    <div class="twm-lt-blog" dir="rtl">
        <div class="wt-post-media">
            <a href="{{ $url }}"><img src="{{ $post->coverUrl() }}" alt="{{ $post->title }}" loading="lazy"></a>
            <div class="post-date-wrap">
                <div class="post-date">
                    <span class="date">{{ $date->format('d') }}</span>
                    <span class="month">{{ $date->locale('ar')->translatedFormat('F') }}</span>
                </div>
            </div>
        </div>
        <div class="twm-post-info-wrap">
            <div class="twm-post-info">
                @if ($post->author_name)
                    <div class="post-author"><a class="au-name" href="{{ $url }}">{{ site('blog.detail.author_prefix') }} {{ $post->author_name }}</a></div>
                @endif
                <h3 class="post-title"><a href="{{ $url }}">{{ $post->title }}</a></h3>
            </div>
        </div>
    </div>
@endif
