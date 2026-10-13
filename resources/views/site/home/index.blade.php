@extends('layouts.site')

@section('title', (string) (site('home.seo.title')))
@section('meta_description', (string) (site('home.seo.description')))

@section('content')
    @include('site.home.sections.hero')
    @includeWhen(site('home.explore.enabled'), 'site.home.sections.explore')
    @includeWhen(site('home.about.enabled'), 'site.home.sections.about')
    @includeWhen(site('home.adventure.enabled'), 'site.home.sections.adventure')
    @includeWhen(site('home.fleet.enabled') && $fleet->isNotEmpty(), 'site.home.sections.fleet')
    @includeWhen(site('home.why.enabled'), 'site.home.sections.why')
    @includeWhen(site('home.categories.enabled'), 'site.home.sections.categories')
    @includeWhen(site('home.steps.enabled'), 'site.home.sections.steps')
    @includeWhen(site('home.testimonials.enabled') && $reviews->isNotEmpty(), 'site.home.sections.testimonials')
    @includeWhen(site('home.blog.enabled') && $posts->isNotEmpty(), 'site.home.sections.blog')
@endsection
