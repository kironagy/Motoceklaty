@extends('layouts.site')

@section('title', (string) (site('about.seo.title')))
@section('meta_description', (string) (site('about.seo.description')))

@section('content')
    <x-site.page-banner :image="site('about.banner.image')" :title="site('about.banner.title')"
        :breadcrumbs="[site('about.banner.breadcrumb') => null]" />

    @include('site.about.sections.story')
    @includeWhen(site('about.features.enabled'), 'site.about.sections.features')
    @includeWhen(site('about.stats.enabled'), 'site.about.sections.stats')
    @includeWhen(site('about.why.enabled'), 'site.about.sections.why')
    @includeWhen(site('about.official.enabled'), 'site.about.sections.official')
    @includeWhen(site('about.team.enabled') && $team->isNotEmpty(), 'site.about.sections.team')
    @include('site.about.sections.testimonial-cta')
@endsection
