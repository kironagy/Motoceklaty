@extends('layouts.site')
@section('title', (string) site('home.seo.title'))
@section('meta_description', (string) site('home.seo.description'))
@section('body_class', 'home-page source-design')
@section('design_css', 'base')
@section('design_shell', 'home-shell')
@section('content')
<img class="page-atmosphere" src="{{ asset('motogate-v2/assets/images/الباكجراوند.png') }}" alt="" aria-hidden="true">
@include('site.home.sections.hero')
<main id="main" class="home-content">
@include('site.home.sections.calculator')
@includeWhen(site('home.about.enabled'), 'site.home.sections.about')
@includeWhen(site('home.fleet.enabled') && $fleet->isNotEmpty(), 'site.home.sections.fleet')
@includeWhen(site('home.blog.enabled') && $posts->isNotEmpty(), 'site.home.sections.blog')
@includeWhen(site('home.why.enabled'), 'site.home.sections.why')
@includeWhen(site('home.categories.enabled'), 'site.home.sections.categories')
</main>
@push('modals')
@include('site.partials.payment-result')
@include('site.partials.story-video')
@endpush

@endsection
