@extends('layouts.site')
@section('title', (string) (site('blog.seo.title')))
@section('meta_description', (string) (site('blog.seo.description')))
@section('body_class', 'article-page interior-page source-design')
@section('design_css', 'pages')
{{-- No list page in motogate-2: built only from its article-page parts (page-heading, related-card). --}}
@section('content')<main id="main" dir="rtl"><div class="article-shell"><nav aria-label="مسار الصفحة" class="breadcrumbs"><a href="{{ route('home') }}">{{ site('blog.design.article.text_01') }}</a><span>/</span><span>{{ site('blog.design.article.text_02') }}</span></nav><div class="page-heading"><span class="mini-label">{{ site('blog.small_title') }}</span><h1>{{ site('blog.banner.title') }}</h1><p>{{ site('blog.title') }}</p></div>@if ($posts->isEmpty())<p>{{ site('blog.empty_text') }}</p>@else<div class="related-grid">@foreach ($posts as $post)<a class="surface related-card" href="{{ route('blog.show', $post) }}"><img src="{{ $post->coverUrl() }}" alt="{{ $post->title }}" loading="lazy"><h3>{{ $post->title }}</h3></a>@endforeach</div>@endif
@if ($posts->hasPages())<nav class="design-pagination" aria-label="صفحات المقالات">@if ($posts->previousPageUrl())<a class="button button-outline" href="{{ $posts->previousPageUrl() }}">السابق</a>@endif @if ($posts->hasMorePages())<a class="button button-outline" href="{{ $posts->nextPageUrl() }}">التالي</a>@endif</nav>@endif
</div></main>
@endsection
