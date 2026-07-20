@extends('layouts.app')

@section('content')

    <body>

        <!-- Hero Slider -->
        <div class="slider" id="slider">
            @foreach ($sliders as $index => $slide)
                <div class="slide {{ $index === 0 ? 'active' : '' }}">
                    @if ($slide->type === 'video')
                        <video autoplay muted loop playsinline>
                            <source src="{{ asset('storage/' . $slide->file_path) }}" type="video/mp4">
                            متصفحك لا يدعم عرض الفيديو.
                        </video>
                    @else
                        <img src="{{ asset('storage/' . $slide->file_path) }}" alt="{{ $slide->title }}">
                    @endif
                    <div class="overlay"></div>
                    <div class="slide-content">
                        <h1>{{ $slide->title }}</h1>
                        <p>{{ $slide->description }}</p>
                        <button class="slide-btn">
                        <a class="text-light text-decoration-none" href="{{route('machines.index')}}">
                            المعرض
                        </a>
                        </button>
                    </div>
                </div>
            @endforeach

            <!-- Dots -->
            <div class="controls">
                @foreach ($sliders as $index => $s)
                    <span class="dot {{ $index === 0 ? 'active' : '' }}"></span>
                @endforeach
            </div>
        </div>
        <!-- News Section -->
        <section class="news-track" id="news">
            <div class="container">
                <div class="swiper newsSwiper">
                    <div class="swiper-wrapper align-items-center">

                        @forelse($ads as $ad)
                            <div class="swiper-slide" style="background: transparent !important;">
                                {{ $ad }}
                            </div>
                        @empty
                            <div class="swiper-slide" style="background: transparent !important;">
                                🚀 لا توجد إعلانات حالياً
                            </div>
                        @endforelse

                    </div>
                </div>
            </div>
        </section>
                <!-- Installment Section -->
      <section class="installment-section mt-3" id="installment">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-12">
                <div class="installment-box">
                    <h4 class="text-center mb-4">احسب قسط مكنتك</h4>
                    <form id="calcForm">
                        @csrf
                        <div class="row g-3">

                            <div class="col-6">
                                <label>ماركة المكنة</label>
                                <select class="form-control" id="calc_brandSelect">
                                    <option value="">اختر...</option>
                                    @foreach ($brands as $brand)
                                        <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-6">
                                <label>المكنة</label>
                                <select class="form-control" id="calc_machineSelect" disabled>
                                    <option value="">اختر...</option>
                                    @foreach ($allMachines as $m)
                                        <option value="{{ $m->id }}" data-brand="{{ $m->brand_id }}"
                                            data-price="{{ $m->installment_price ?? 0 }}">
                                            {{ $m->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-6">
                                <label>نظام التقسيط</label>
                                <select class="form-control" id="calc_systemSelect">
                                    <option value="">اختر النظام...</option>
                                    @foreach ($installmentSystems as $system)
                                        <option value="{{ $system->id }}"
                                            data-plans='@json($system->plans)'
                                            data-fees="{{ $system->administrative_fees }}">
                                            {{ $system->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-6">
                                <label>عدد الشهور</label>
                                <select class="form-control" id="calc_monthsSelect" disabled>
                                    <option value="">اختر...</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label>المقدم</label>
                                <input type="number" class="form-control" id="calc_downPayment"
                                    placeholder="0">
                            </div>

                            <div class="col-12 text-center mt-3">
                                <button type="submit" class="calc-btn">احسب القسط</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>


      <div class="swiper mySwiper ">
            <h2 class="section-title">الموتوسيكلات الجديدة</h2>
            <div class="swiper-wrapper p-4">
                @foreach ($machines as $machine)
                    <div class="swiper-slide p-4 swiper-slide_bike">
                        <div class="bike-card position-relative overflow-hidden">
                            <img src="{{ asset('storage/' . $machine->display_image) }}" alt="{{ $machine->name }}"
                                class="img-fluid rounded-3">

                            <div class="bike-details text-center p-3">
                                <h3 class="bike-name fw-bold">{{ $machine->name }}</h3>
                                <div class="bike-price text-success fw-semibold">{{ number_format($machine->cash_price) }}
                                    جنيه</div>


                                <a href="{{ route('machines.show', $machine->id) }}" class="btn btn-primary mt-3 bike-btn">
                                    المزيد من التفاصيل
                                </a>
                            </div>
                        </div>

                    </div>
                @endforeach
            </div>
            <div class="swiper-pagination"></div>
        </div>
        <style>
            .bike-btn {
                background-color: #00bcd4 !important;
                /* الأزرق الأساسي للموقع */
                border: none !important;
                color: #fff !important;
                font-weight: 500;
                border-radius: 8px;
                transition: all 0.3s ease;
                padding: 6px 14px;
                display: inline-block;
            }
        </style>
        <!-- Features -->

  


        <section class="offers-slider container my-5">
            <h3 class="mb-4 text-center">🔥 العروض الخاصة 🔥</h3>
            <div class="swiper mySwiper">
                <div class="swiper-wrapper">
                    @foreach ($offerChunks as $chunk)
                        <div class="swiper-slide">
                            <div class="row justify-content-center">
                                @foreach ($chunk as $machine)
                                    <div class="col-md-5 mx-2">
                                        <a href="{{ route('machines.show', $machine->id) }}"
                                            class="text-decoration-none text-dark">
                                            <div class="card p-3 shadow-sm text-center h-100">
                                                <img style="height:250px"
                                                    src="{{ asset('storage/' . $machine->display_image) }}"
                                                    alt="{{ $machine->name }}" class="img-fluid mb-2 rounded">
                                                <h5 class="fw-bold">{{ $machine->name }}</h5>

                                                <p class="text-danger fw-bold mb-1">
                                                    {{ number_format($machine->new_price, 0) }} ج.م
                                                </p>
                                                <small class="text-muted text-decoration-line-through">
                                                    {{ number_format($machine->old_price, 0) }} ج.م
                                                </small>
{{--                                                 <ul
                                                    class="bike-features d-flex flex-column justify-content-center align-items-center  list-unstyled mt-2">
                                                    @foreach ($machine->features ?? [] as $f)
                                                        <li>{{ is_array($f) ? implode(' ', $f) : $f }}</li>
                                                    @endforeach
                                                </ul> --}}
                                                <a href="{{ route('machines.show', $machine->id) }}"
                                                    class="btn btn-primary mt-3 bike-btn">
                                                    المزيد من التفاصيل
                                                </a>
                                            </div>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- أزرار التحكم -->
                <div class="swiper-button-next"></div>
                <div class="swiper-button-prev"></div>
                <div class="swiper-pagination"></div>
            </div>
        </section>


        <section class="features" id="features">
            <div class="container">
                <h2 class="section-title">مميزات موتسيكلاتي</h2>
                <div class="row g-4 d-flex justify-content-around mt-3">
                    <div class="col-md-4 ">
                        <div class="feature-box d-flex justify-content-center flex-column align-items-center">
                            <div class="rounded-circle d-flex justify-content-center mb-3"
                                style="background-color: rgba(0, 167, 179, 0.1); width: 60px; height: 60px;">
                                <i class="bi bi-wrench-adjustable-circle"></i>
                            </div>
                            <h5>خدمة صيانة متميزة</h5>
                            <p>فريق صيانة متخصص وخدمة ما بعد البيع لضمان راحتك واستمرار أداء المكنة بكفاءة.</p>
                        </div>
                    </div>

                    <div class="col-md-4 ">
                        <div class="feature-box d-flex justify-content-center flex-column align-items-center">
                            <div class="rounded-circle d-flex justify-content-center mb-3"
                                style="background-color: rgba(0, 167, 179, 0.1); width: 60px; height: 60px;">
                                <i class="bi bi-currency-exchange"></i>
                            </div>
                            <h5>أنظمة تقسيط مرنة</h5>
                            <p>اختار نظام الدفع اللي يناسبك سواء كاش أو تقسيط على فترات مريحة بدون تعقيد.</p>
                        </div>
                    </div>

                    <div class="col-md-4 ">
                        <div class="feature-box d-flex justify-content-center flex-column align-items-center">
                            <div class="rounded-circle d-flex justify-content-center mb-3"
                                style="background-color: rgba(0, 167, 179, 0.1); width: 60px; height: 60px;">
                                <i class="bi bi-shield-check"></i>
                            </div>
                            <h5>ضمان وجودة</h5>
                            <p>جميع المكن بضمان رسمي ضد عيوب الصناعة مع ضمان على قطع الغيار الأصلية.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="brands" id="brands">
            <div class="container text-center">
                <h2 class="section-title">أشهر الماركات</h2>
                <div class="swiper brandsSwiper">
                    <div class="swiper-wrapper">
                        @foreach ($brands as $brand)
                            <div class="swiper-slide">
                                <a href="{{ route('machines.index', ['brand_id' => $brand->id]) }}">
                                    <img src="{{ asset('storage/' . $brand->image) }}" alt="{{ $brand->name }}">
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
        <!-- Partners Section -->
{{--         <section class="partners py-5" id="partners">
            <div class="container text-center">
                <h2 class="section-title mb-5">شركاؤنا في النجاح</h2>

                <div class="swiper partnersSwiper">
                    <div class="swiper-wrapper align-items-center">
                        @forelse($partners as $partner)
                            <div class="swiper-slide">
                                <img src="{{ asset('storage/' . $partner) }}" alt="شريك" />
                            </div>
                            <div class="swiper-slide">
                                <img src="{{ asset('storage/' . $partner) }}" alt="شريك" />
                            </div>
                            <div class="swiper-slide">
                                <img src="{{ asset('storage/' . $partner) }}" alt="شريك" />
                            </div>
                            <div class="swiper-slide">
                                <img src="{{ asset('storage/' . $partner) }}" alt="شريك" />
                            </div>
                        @empty

                            <div class="swiper-slide">
                                <p>🚀 لا توجد بيانات شركاء حالياً</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>
 --}}


    </body>
@endsection
