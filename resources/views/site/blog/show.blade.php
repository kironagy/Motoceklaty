@extends('layouts.site')

@section('title', (string) ($post->title . ' | ' . site('general.seo.title_suffix')))
@section('meta_description', (string) ($post->meta_description ?: $post->excerptText()))
@section('og_image', (string) ($post->coverUrl()))

@php
    $detail = site('blog.detail');
    $date = $post->published_at ?? $post->created_at;
    $shareUrl = urlencode(route('blog.show', $post));
@endphp

@section('content')
    <div class="section-full p-t150 p-b120 site-bg-white">
        <div class="twm-blog-detail-wrap">
            <div class="twm-mid-bx-section" dir="rtl">

                <div class="twm-blog-detail-head">
                    <div class="twm-post-info-wrap">
                        <div class="post-date-wrap">
                            <div class="post-date">
                                <span class="date">{{ $date->format('d') }}</span>
                                <span class="month">{{ $date->locale('ar')->translatedFormat('F') }}</span>
                            </div>
                        </div>
                        <div class="twm-post-info">
                            @if ($post->author_name)
                                <div class="post-author"><a class="au-name" href="{{ route('team.index') }}">{{ $detail['author_prefix'] ?? '' }} {{ $post->author_name }}</a></div>
                            @endif
                            <h2 class="post-title">{{ $post->title }}</h2>
                        </div>
                    </div>

                    @if ($post->cover_image)
                        <div class="wt-post-media mt-4">
                            <img src="{{ $post->coverUrl() }}" class="rounded" alt="{{ $post->title }}">
                        </div>
                    @endif
                </div>

                <div class="site-post-body">{!! $post->body !!}</div>

                <div class="tag-list-wrap mt-5 pt-4 border-top d-flex justify-content-between align-items-center flex-wrap">
                    <div class="tag-list">
                        @if (count($post->tags ?? []))
                            <span class="fw-bold me-2">{{ $detail['tags_label'] ?? '' }}</span>
                            @foreach ($post->tags as $tag)
                                <span class="badge bg-light text-dark p-2 me-1">{{ $tag }}</span>
                            @endforeach
                        @endif
                    </div>
                    <div class="share-post">
                        <span class="fw-bold me-2">{{ $detail['share_label'] ?? '' }}</span>
                        <a href="https://www.facebook.com/sharer/sharer.php?u={{ $shareUrl }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary me-1"><i class="feather feather-facebook"></i></a>
                        <a href="https://wa.me/?text={{ $shareUrl }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success"><i class="fa-brands fa-whatsapp"></i></a>
                    </div>
                </div>

                @if ($comments->isNotEmpty())
                    <div class="site-comments mt-5 pt-4 border-top">
                        <h3 class="mb-4">{{ $detail['comments_title'] ?? '' }} ({{ $comments->count() }})</h3>
                        @foreach ($comments as $comment)
                            <div class="site-comment">
                                <div class="site-comment-head">
                                    <strong>{{ $comment->name }}</strong>
                                    <span>{{ $comment->created_at->locale('ar')->translatedFormat('d F Y') }}</span>
                                </div>
                                <p>{{ $comment->body }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($post->allow_comments)
                    <div class="comment-area mt-5 pt-4 border-top" id="comment-form">
                        <h3 class="mb-4">{{ $detail['form_title'] ?? '' }}</h3>
                        @if (session('comment_status'))
                            <div class="alert alert-success">{{ session('comment_status') }}</div>
                        @endif
                        <form method="POST" action="{{ route('blog.comment', $post) }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <input class="form-control" name="name" value="{{ old('name') }}" placeholder="{{ $detail['name_placeholder'] ?? '' }}" required>
                                    @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <input class="form-control" type="email" name="email" value="{{ old('email') }}" placeholder="{{ $detail['email_placeholder'] ?? '' }}" required>
                                    @error('email') <small class="text-danger">{{ $message }}</small> @enderror
                                </div>
                                <div class="col-12 mb-3">
                                    <textarea class="form-control" name="body" rows="4" placeholder="{{ $detail['body_placeholder'] ?? '' }}" required>{{ old('body') }}</textarea>
                                    @error('body') <small class="text-danger">{{ $message }}</small> @enderror
                                </div>
                                <input type="text" name="website" class="d-none" tabindex="-1" autocomplete="off">
                                <div class="col-12">
                                    <button type="submit" class="site-button"><em>{{ $detail['submit'] ?? '' }}</em></button>
                                </div>
                            </div>
                        </form>
                    </div>
                @endif

            </div>
        </div>
    </div>
@endsection
