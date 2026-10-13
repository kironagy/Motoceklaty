@extends('layouts.site')

@section('title', (string) (site('contact.seo.title')))
@section('meta_description', (string) (site('contact.seo.description')))

@php
    $form = site('contact.form');
@endphp

@section('content')
    <x-site.page-banner :image="site('contact.banner.image')" :title="site('contact.banner.title')"
        :breadcrumbs="[site('contact.banner.breadcrumb') => null]" />

    <div class="section-full p-t150 p-b120 site-bg-white twm-contact-section-wrap">
        <div class="container">
            <div class="section-content">
                <div class="twm-contact-section">
                    <div class="row" dir="rtl">

                        <div class="col-xl-6 col-lg-6 col-md-12 m-b30">
                            <div class="section-head left">
                                <div class="twm-sm-title left">{{ site('contact.small_title') }}</div>
                                <h2 class="twm-large-title site-text-dark">{{ site('contact.title') }}</h2>
                                @if (site('contact.text'))
                                    <p class="p-text">{{ site('contact.text') }}</p>
                                @endif
                            </div>

                            <div class="twm-get-info-wrap mt-4">
                                <ul>
                                    @if (site('general.phone'))
                                        <li>
                                            <div class="twm-get-info">
                                                <div class="twm-media"><i class="feather feather-phone-call"></i></div>
                                                <div class="twm-content">
                                                    <p>{{ site('contact.phone_label') }}</p>
                                                    <h3 class="twm-title"><a href="{{ site_tel(site('general.phone')) }}">{{ site('general.phone') }}</a></h3>
                                                </div>
                                            </div>
                                        </li>
                                    @endif
                                    @if (site('general.email'))
                                        <li>
                                            <div class="twm-get-info">
                                                <div class="twm-media"><i class="feather feather-mail"></i></div>
                                                <div class="twm-content">
                                                    <p>{{ site('contact.email_label') }}</p>
                                                    <h3 class="twm-title"><a href="mailto:{{ site('general.email') }}">{{ site('general.email') }}</a></h3>
                                                </div>
                                            </div>
                                        </li>
                                    @endif
                                    @if (site('general.address'))
                                        <li>
                                            <div class="twm-get-info">
                                                <div class="twm-media"><i class="feather feather-map-pin"></i></div>
                                                <div class="twm-content">
                                                    <p>{{ site('contact.address_label') }}</p>
                                                    <h3 class="twm-title">{{ site('general.address') }}</h3>
                                                </div>
                                            </div>
                                        </li>
                                    @endif
                                    @if (count(site('general.working_hours', [])))
                                        <li>
                                            <div class="twm-get-info">
                                                <div class="twm-media"><i class="feather feather-clock"></i></div>
                                                <div class="twm-content">
                                                    <p>{{ site('contact.hours_label') }}</p>
                                                    <h3 class="twm-title">
                                                        @foreach (site('general.working_hours', []) as $row)
                                                            <span class="d-block">{{ trim(($row['day'] ?? '') . ': ' . ($row['time'] ?? ''), ': ') }}</span>
                                                        @endforeach
                                                    </h3>
                                                </div>
                                            </div>
                                        </li>
                                    @endif
                                </ul>

                                <x-site.social-links class="mt-4" :title="site('contact.social_title')" />
                            </div>
                        </div>

                        <div class="col-xl-6 col-lg-6 col-md-12">
                            @php
                                $wa = site_whatsapp(site('general.whatsapp'));
                                $subjects = collect($form['subjects'] ?? [])->pluck('label')->filter()->values();
                                $selectedSubject = old('subject', $subjects->first());
                            @endphp
                            <div class="mg-inquiry" id="contact-form">
                                <div class="mg-inquiry__head">
                                    <span class="mg-inquiry__icon"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></span>
                                    <div>
                                        <h3>{{ $form['title'] ?? '' }}</h3>
                                        @if (! empty($form['text']))
                                            <p>{{ $form['text'] }}</p>
                                        @endif
                                    </div>
                                </div>

                                @if ($wa || site('general.phone'))
                                    <div class="mg-inquiry__quick">
                                        @if ($wa)
                                            <a href="{{ $wa }}" target="_blank" rel="noopener" class="mg-quick mg-quick--wa"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> واتساب</a>
                                        @endif
                                        @if (site('general.phone'))
                                            <a href="{{ site_tel(site('general.phone')) }}" class="mg-quick"><i class="fa-solid fa-phone" aria-hidden="true"></i> اتصل بنا</a>
                                        @endif
                                    </div>
                                    <div class="mg-inquiry__or"><span>أو ابعتلنا رسالة</span></div>
                                @endif

                                @if (session('contact_status'))
                                    <div class="mg-inquiry__success" role="status">
                                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                        <span>{{ session('contact_status') }}</span>
                                    </div>
                                @endif

                                <form method="POST" action="{{ route('contact.store') }}" class="mg-inquiry__form">
                                    @csrf
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="mg-field" for="c-name">
                                                <span class="mg-field__label">{{ $form['name_label'] ?? '' }} <b aria-hidden="true">*</b></span>
                                                <span class="mg-field__control">
                                                    <i class="fa-regular fa-user" aria-hidden="true"></i>
                                                    <input id="c-name" type="text" name="name" value="{{ old('name') }}" autocomplete="name" required
                                                        placeholder="{{ $form['name_placeholder'] ?? '' }}" @error('name') aria-invalid="true" aria-describedby="c-name-err" @enderror>
                                                </span>
                                                @error('name') <span class="mg-field__error" id="c-name-err">{{ $message }}</span> @enderror
                                            </label>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="mg-field" for="c-phone">
                                                <span class="mg-field__label">{{ $form['phone_label'] ?? '' }} <b aria-hidden="true">*</b></span>
                                                <span class="mg-field__control">
                                                    <i class="fa-solid fa-mobile-screen" aria-hidden="true"></i>
                                                    <input id="c-phone" type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel" inputmode="tel" dir="ltr" required
                                                        placeholder="{{ $form['phone_placeholder'] ?? '' }}" @error('phone') aria-invalid="true" aria-describedby="c-phone-err" @enderror>
                                                </span>
                                                @error('phone') <span class="mg-field__error" id="c-phone-err">{{ $message }}</span> @enderror
                                            </label>
                                        </div>
                                        <div class="col-12">
                                            <label class="mg-field" for="c-email">
                                                <span class="mg-field__label">{{ $form['email_label'] ?? '' }} <small>(اختياري)</small></span>
                                                <span class="mg-field__control">
                                                    <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                                                    <input id="c-email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" dir="ltr"
                                                        placeholder="{{ $form['email_placeholder'] ?? '' }}" @error('email') aria-invalid="true" aria-describedby="c-email-err" @enderror>
                                                </span>
                                                @error('email') <span class="mg-field__error" id="c-email-err">{{ $message }}</span> @enderror
                                            </label>
                                        </div>
                                        @if ($subjects->isNotEmpty())
                                            <div class="col-12">
                                                <fieldset class="mg-field">
                                                    <legend class="mg-field__label">{{ $form['subject_label'] ?? '' }}</legend>
                                                    <div class="mg-chips">
                                                        @foreach ($subjects as $i => $subject)
                                                            <input type="radio" class="mg-chips__input" name="subject" id="c-subject-{{ $i }}" value="{{ $subject }}" @checked($selectedSubject === $subject)>
                                                            <label class="mg-chips__chip" for="c-subject-{{ $i }}">{{ $subject }}</label>
                                                        @endforeach
                                                    </div>
                                                </fieldset>
                                            </div>
                                        @endif
                                        <div class="col-12">
                                            <label class="mg-field" for="c-message">
                                                <span class="mg-field__label">{{ $form['message_label'] ?? '' }} <b aria-hidden="true">*</b></span>
                                                <span class="mg-field__control mg-field__control--area">
                                                    <i class="fa-regular fa-comment-dots" aria-hidden="true"></i>
                                                    <textarea id="c-message" name="message" rows="4" required
                                                        placeholder="{{ $form['message_placeholder'] ?? '' }}" @error('message') aria-invalid="true" aria-describedby="c-message-err" @enderror>{{ old('message') }}</textarea>
                                                </span>
                                                @error('message') <span class="mg-field__error" id="c-message-err">{{ $message }}</span> @enderror
                                            </label>
                                        </div>
                                        <input type="text" name="website" class="d-none" tabindex="-1" autocomplete="off" aria-hidden="true">
                                        <div class="col-12">
                                            <button type="submit" class="mg-inquiry__submit">
                                                <span>{{ $form['submit'] ?? '' }}</span>
                                                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                                            </button>
                                            <p class="mg-inquiry__privacy"><i class="fa-solid fa-lock" aria-hidden="true"></i> بياناتك بتستخدم بس عشان نرد على استفسارك.</p>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                    </div>
                </div>

                @if (site('contact.show_branches') && $branches->isNotEmpty())
                    <div class="site-branches m-t40" dir="rtl">
                        <x-site.section-head align="center" :title="site('contact.branches_title')" />
                        <div class="row">
                            @foreach ($branches as $branch)
                                <div class="col-lg-4 col-md-6 m-b30">
                                    <div class="site-branch-card">
                                        <h3>{{ $branch->name }}</h3>
                                        @if ($branch->address)
                                            <p><i class="feather feather-map-pin"></i> {{ $branch->address }}</p>
                                        @endif
                                        @foreach (array_filter((array) $branch->phones) as $phone)
                                            <p><i class="feather feather-phone-call"></i> <a href="{{ site_tel(is_array($phone) ? ($phone['number'] ?? '') : $phone) }}">{{ is_array($phone) ? ($phone['number'] ?? '') : $phone }}</a></p>
                                        @endforeach
                                        @foreach ((array) $branch->working_hours as $day => $time)
                                            <p><i class="feather feather-clock"></i> {{ is_string($day) ? "{$day}: " : '' }}{{ is_array($time) ? implode(' - ', $time) : $time }}</p>
                                        @endforeach
                                        @if ($branch->map_url)
                                            <a href="{{ $branch->map_url }}" target="_blank" rel="noopener" class="site-branch-map">{{ site('contact.branch_map_label') }} <i class="fa-solid fa-arrow-left"></i></a>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // One send per click: the browser has already checked required fields.
        document.querySelector('.mg-inquiry__form')?.addEventListener('submit', function (e) {
            e.target.querySelector('[type=submit]').disabled = true;
        });
    </script>
@endpush
