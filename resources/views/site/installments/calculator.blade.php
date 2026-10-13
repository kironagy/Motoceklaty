@extends('layouts.site')

@section('title', (string) (site('installment.page.seo_title')))

@section('content')
    <x-site.page-banner :image="site('installment.page.banner_image')" :title="site('installment.page.banner_title')"
        :breadcrumbs="[site('installment.page.breadcrumb') => null]" />

    <div class="section-full p-t100 p-b120 site-bg-dark site-calc-page">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xl-8 col-lg-10">
                    <x-site.section-head align="center" title-class="site-text-white"
                        :small="site('installment.page.small_title')"
                        :title="site('installment.page.title')" />
                    @if (site('installment.page.text'))
                        <p class="site-calc-page-text" dir="rtl">{{ site('installment.page.text') }}</p>
                    @endif

                    <x-site.installment-calculator :tabs="site('home.hero.tabs', [])" :browse-url="route('machines.index')" />
                </div>
            </div>
        </div>
    </div>
@endsection
