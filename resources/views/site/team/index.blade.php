@extends('layouts.site')

@section('title', (string) (site('team.seo.title')))
@section('meta_description', (string) (site('team.seo.description')))

@section('content')
    <x-site.page-banner :image="site('team.banner.image')" :title="site('team.banner.title')"
        :breadcrumbs="[site('team.banner.breadcrumb') => null]" />

    <div class="section-full p-t150 p-b120 site-bg-white twm-team2-section-wrap">
        <div class="container">
            <div class="section-content">
                <x-site.section-head align="center" :small="site('team.small_title')" :title="site('team.title')" />

                <div class="row" dir="rtl">
                    @foreach ($members as $member)
                        <div class="col-lg-3 col-md-6 col-sm-6 m-b30 wow fadeInDown" data-wow-delay="0.{{ ($loop->index % 4) + 1 }}">
                            <x-site.team-card :member="$member" />
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection
