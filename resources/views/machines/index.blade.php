@extends('layouts.app')

@section('content')
<section class="machines-section py-5">
    <div class="container">
        <h2 class="section-title text-center mb-4">كل الموتوسيكلات</h2>

        <!-- 🔍 البحث -->
        <div class="text-center mb-4">
            <input type="text" id="searchInput" class="form-control w-50 mx-auto" placeholder="ابحث باسم المكنة...">
        </div>

        <!-- 🏷️ فلترة الماركات -->
        <div class="text-center mb-5">
            <button class="brand-filter active" data-brand="all">الكل</button>
            @foreach ($brands as $brand)
                <button class="brand-filter" data-brand="{{ $brand->id }}">{{ $brand->name }}</button>
            @endforeach
        </div>

        <!-- 💥 عرض الماركات والموتوسيكلات -->
        @foreach ($brands as $brand)
            @if ($brand->machines->count() > 0)
                <div class="brand-block mb-5 brand-group" data-brand="{{ $brand->id }}">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h3 class="fw-bold">{{ $brand->name }}</h3>
                    </div>

                    <div class="swiper mySwiper">
                        <div class="swiper-wrapper p-4">
                            @foreach ($brand->machines as $machine)
                                <div class="swiper-slide p-3 swiper-slide_bike" data-name="{{ strtolower($machine->name) }}">
                                    <div class="bike-card position-relative overflow-hidden shadow-sm rounded">
                                        <img src="{{ asset('storage/' . $machine->display_image) }}" alt="{{ $machine->name }}" class="img-fluid">

                                        <div class="bike-details text-center p-3">
                                            <h4 class="bike-name fw-bold">{{ $machine->name }}</h4>
                                            <div class="bike-price text-success fw-semibold">
                                                {{ number_format($machine->cash_price) }} جنيه
                                            </div>

                                            <a href="{{ route('machines.show', $machine->id) }}" class="btn bike-btn mt-3">
                                                المزيد من التفاصيل
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="swiper-pagination"></div>
                    </div>
                </div>
            @endif
        @endforeach

        <div class="no-results">لا توجد نتائج مطابقة</div>
    </div>
</section>

<style>
    .brand-filter {
        border: 1px solid #ccc;
        background: #fff;
        padding: 8px 18px;
        margin: 5px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 500;
        transition: all .3s;
    }

    .brand-filter:hover {
        background: #00bcd4;
        color: #fff;
    }

    .brand-filter.active {
        background: #00bcd4;
        color: #fff;
        border-color: #00bcd4;
    }

    .bike-btn {
        background-color: #00bcd4 !important;
        border: none !important;
        color: #fff !important;
        font-weight: 500;
        border-radius: 8px;
        transition: all 0.3s ease;
        padding: 6px 14px;
        display: inline-block;
    }

    .bike-card img {
        border-radius: 12px;
        width: 100%;
        height: 200px;
        object-fit: cover;
    }

    .brand-block {
        border-bottom: 1px solid #eee;
        padding-bottom: 2rem;
    }

    .swiper-slide_bike {
        transition: all 0.3s ease;
    }

    .no-results {
        text-align: center;
        color: #888;
        font-weight: bold;
        display: none;
    }
</style>

<script>
    const searchInput = document.getElementById('searchInput');
    const slides = document.querySelectorAll('.swiper-slide_bike');
    const noResults = document.querySelector('.no-results');
    const brandFilters = document.querySelectorAll('.brand-filter');
    const brandGroups = document.querySelectorAll('.brand-group');

    // 🔍 فلترة بالبحث
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        let found = false;

        slides.forEach(slide => {
            const name = slide.dataset.name.toLowerCase();
            if (name.includes(query) || query === '') {
                slide.style.opacity = '1';
                slide.style.pointerEvents = 'auto';
                slide.style.transform = 'scale(1)';
                found = true;
            } else {
                slide.style.opacity = '0';
                slide.style.pointerEvents = 'none';
                slide.style.transform = 'scale(0.5)';
            }
        });

        noResults.style.display = found ? 'none' : 'block';
    });

    // 🏷️ فلترة حسب الماركة
    brandFilters.forEach(btn => {
        btn.addEventListener('click', () => {
            brandFilters.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const brandId = btn.dataset.brand;

            brandGroups.forEach(group => {
                if (brandId === 'all' || group.dataset.brand === brandId) {
                    group.style.display = 'block';
                } else {
                    group.style.display = 'none';
                }
            });
        });
    });
</script>
@endsection

