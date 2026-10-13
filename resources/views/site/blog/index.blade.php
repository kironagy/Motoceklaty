@extends('layouts.site')

@section('title', (string) (site('blog.seo.title')))
@section('meta_description', (string) (site('blog.seo.description')))

@php($style = site('blog.card_style', 'classic'))

@section('content')
    <x-site.page-banner :image="site('blog.banner.image')" :title="site('blog.banner.title')"
        :breadcrumbs="[site('blog.banner.breadcrumb') => null]" />

    <div @class(['section-full p-t150 p-b150 site-bg-white', 'twm-blog-grid-2-section-wrap' => $style === 'grid', 'twm-blog-grid-section-wrap' => $style !== 'grid'])>
        <div class="container">
            <div class="section-content">
                <x-site.section-head align="center" :small="site('blog.small_title')" :title="site('blog.title')" />

                <div class="masonry-wrap row" dir="rtl">
                    @forelse ($posts as $post)
                        <div class="masonry-item col-xl-4 col-lg-6 col-md-6 m-b30 wow fadeInDown" data-wow-delay="0.{{ ($loop->index % 3) + 1 }}">
                            <x-site.post-card :post="$post" :style="$style" />
                        </div>
                    @empty
                        <div class="col-12 text-center"><p class="p-text">{{ site('blog.empty_text') }}</p></div>
                    @endforelse
                </div>

                @if ($posts->hasPages())
                    <div class="pagination-outer d-flex justify-content-center">
                        <div class="pagination-style1" dir="rtl">
                            <ul class="clearfix">
                                <li class="prev"><a href="{{ $posts->previousPageUrl() ?? '#' }}"><span><i class="fa-solid fa-arrow-right"></i></span></a></li>
                                @foreach ($posts->getUrlRange(1, $posts->lastPage()) as $page => $url)
                                    <li @class(['active' => $page === $posts->currentPage()])><a href="{{ $url }}">{{ $page }}</a></li>
                                @endforeach
                                <li class="next"><a href="{{ $posts->nextPageUrl() ?? '#' }}"><span><i class="fa-solid fa-arrow-left"></i></span></a></li>
                            </ul>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
