@extends('layouts.app')

@section('content')

    <style>
        /* 🌌 خلفية الهيرو */
        .machine-hero {
            position: relative;
            background: radial-gradient(circle at center, #0f172a, #000);
            color: white;
            border-radius: 25px;
            padding: 50px 40px;
            overflow: hidden;
            box-shadow: 0 0 40px rgba(0, 0, 0, 0.4);
        }

        /* 🎨 محتوى الصفحة فوق الخلفية */
        .machine-hero .content {
            position: relative;
            z-index: 2;
        }

        .swiper {
            width: 100%;
            max-width: 500px;
            margin-top: 20px;
            position: relative;
            z-index: 1;
        }

        .swiper-slide {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .swiper-slide img {
            width: 100%;
            height: 100px;
            object-fit: cover;
            border-radius: 12px;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .swiper-slide img:hover {
            transform: scale(1.08);
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.4);
        }

        .swiper-button-next,
        .swiper-button-prev {
            color: #38bdf8;
        }

        /* 💠 خلفية hexagons */
        #tsparticles {
            position: absolute;
            inset: 0;
            z-index: 1;
            opacity: 0.45;
            /* خفيفة ومريحة */
        }

        /* باقي الاستايل زي ما هو */
        .machine-hero h2 {
            font-size: 2.8rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .machine-hero .brand {
            color: #38bdf8;
            font-size: 1.1rem;
        }

        .price-box h4 {
            color: #22c55e;
        }

        .price-box h5 {
            color: #60a5fa;
        }

        #mainImage {
            border-radius: 25px;
            width: 100%;
            max-height: 350px;
            object-fit: cover;
            transition: opacity 0.3s ease, transform 0.3s ease;
            box-shadow: 0 0 30px rgba(0, 0, 0, 0.4);
        }

        .swiper {
            width: 100%;
            max-width: 500px;
            margin-top: 20px;
        }

        .color-dot {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            border: 2px solid #fff;
            cursor: pointer;
            transition: transform 0.25s ease, box-shadow 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(90deg, #3b82f6, #06b6d4);
            border: none;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.6);
        }
    </style>
    <section class="py-5">
        <div class="container ">
            {{-- 🏍️ عرض المكنة --}}
            <div class="machine-hero">
                <div id="tsparticles"></div> {{-- الخلفية المتحركة --}}

                <div class="content">
                    {{-- محتوى المكنة --}}
                    <div class="row align-items-center">
                        <div class="col-md-6 text-center mb-4 mb-md-0">
                            <img draggable="false" id="mainImage" src="{{ asset('storage/' . $machine->display_image) }}"
                                alt="{{ $machine->name }}">
                            <div class="swiper mySwiper mt-4">
                                <div class="swiper-wrapper" id="colorImages">
                                    @foreach ($machine->colors[0]['images'] ?? [] as $img)
                                        <div class="swiper-slide">
                                            <img draggable="false" src="{{ asset('storage/' . $img) }}" alt="machine image">
                                        </div>
                                    @endforeach
                                </div>
                                <div class="swiper-button-next"></div>
                                <div class="swiper-button-prev"></div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <h2>{{ strtoupper($machine->name) }}</h2>
                            <p class="brand">الماركة: {{ $machine->brand->name }}</p>

                            <h5 class="mt-4 mb-2">الألوان المتاحة:</h5>
                            <div class="d-flex flex-wrap gap-3" id="colorsContainer">
                                @foreach ($machine->colors ?? [] as $color)
                                    @php
                                        $colorCode = $color['color'] ?? '#ccc';
                                        $main = $color['color_display'] ?? $machine->display_image;
                                        $images = $color['images'] ?? [];
                                        $data = ['color' => $colorCode, 'main_image' => $main, 'images' => $images];
                                    @endphp
                                    <div class="color-dot" title="{{ $colorCode }}"
                                        style="background-color: {{ $colorCode }};"
                                        data-color='@json($data)'></div>
                                @endforeach
                            </div>

                            <h5 class="mt-4">المميزات:</h5>
                            @if (!empty($machine->features))
                                <ul class="list-unstyled">
                                    @foreach ($machine->features as $feature)
                                        <li>✅ {{ $feature }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="text-muted">لا توجد مميزات محددة.</p>
                            @endif

                            <div class="price-box mt-4">
                                <h4>{{ number_format($machine->cash_price) }} جنيه كاش</h4>
                            </div>

                            <a href="#apply-form" class="btn btn-primary mt-4 px-4 py-2">قدّم طلبك بنفسك</a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        {{-- 📋 فورم التقديم --}}


        </div>
    </section>
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

   

    {{-- ✅ سكريبت عرض الصور + إظهار عنوان العمل --}}
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // 🖼️ عرض الصور
            const previewMap = {
                applicant_id_front: 'preview_app_front',
                applicant_id_back: 'preview_app_back',
                guar_front: 'preview_guar_front',
                guar_back: 'preview_guar_back'
            };

            Object.entries(previewMap).forEach(([inputId, imgId]) => {
                const input = document.getElementById(inputId);
                const img = document.getElementById(imgId);
                if (input && img) {
                    input.addEventListener('change', e => {
                        const file = e.target.files[0];
                        if (file) {
                            const reader = new FileReader();
                            reader.onload = evt => {
                                img.src = evt.target.result;
                                img.classList.remove('d-none');
                            };
                            reader.readAsDataURL(file);
                        } else {
                            img.classList.add('d-none');
                            img.src = '#';
                        }
                    });
                }
            });

            // 💼 منطق الحالة الوظيفية + عنوان العمل
            const ws = document.getElementById('work_status');
            const emp = document.getElementById('employee_block');
            const pen = document.getElementById('pension_block');
            const self = document.getElementById('self_block');
            const workAddressBlock = document.getElementById('work_address_block');
            const workAddressTextarea = workAddressBlock.querySelector('textarea');

            function toggleWork() {
                [emp, pen, self].forEach(e => e.classList.add('d-none'));
                if (ws.value === 'employee') emp.classList.remove('d-none');
                if (ws.value === 'pension') pen.classList.remove('d-none');
                if (ws.value === 'self_employed') self.classList.remove('d-none');

                // 👇 إظهار عنوان العمل فقط للموظف وصاحب النشاط
                if (ws.value === 'employee' || ws.value === 'self_employed') {
                    workAddressBlock.classList.remove('d-none');
                    workAddressTextarea.setAttribute('required', 'required');
                } else {
                    workAddressBlock.classList.add('d-none');
                    workAddressTextarea.removeAttribute('required');
                }
            }

            ws.addEventListener('change', toggleWork);
            toggleWork();
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/tsparticles@2.12.0/tsparticles.bundle.min.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", async () => {
            await tsParticles.load("tsparticles", {
                fullScreen: false,
                fpsLimit: 120,
                particles: {
                    number: {
                        value: 100,
                        density: {
                            enable: true,
                            area: 1000
                        }
                    },
                    color: {
                        value: "#00c4cc"
                    },
                    shape: {
                        type: "polygon",
                        polygon: {
                            sides: 6
                        }
                    },
                    opacity: {
                        value: 0.15
                    },
                    size: {
                        value: 20,
                        random: {
                            enable: true,
                            minimumValue: 10
                        }
                    },
                    move: {
                        enable: true,
                        speed: 0.5,
                        direction: "none",
                        outModes: {
                            default: "bounce"
                        }
                    },
                    links: {
                        enable: true,
                        distance: 130,
                        color: "#00c4cc",
                        opacity: 0.1,
                        width: 1
                    }
                },
                detectRetina: true,
                background: {
                    color: "transparent"
                }
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const mainImage = document.getElementById('mainImage');
            const colorImages = document.getElementById('colorImages');
            const dots = document.querySelectorAll('.color-dot');

            // 🌀 تهيئة السلايدر
            let swiper = new Swiper(".mySwiper", {
                slidesPerView: 3,
                spaceBetween: 15,
                loop: true,
                navigation: {
                    nextEl: ".swiper-button-next",
                    prevEl: ".swiper-button-prev",
                },
                breakpoints: {
                    0: {
                        slidesPerView: 2
                    },
                    768: {
                        slidesPerView: 3
                    },
                    992: {
                        slidesPerView: 4
                    },
                },
            });

            // ✅ الضغط على أي صورة لتغيير الصورة الرئيسية
            function bindThumbClick() {
                document.querySelectorAll('.swiper-slide img').forEach(img => {
                    img.addEventListener('click', () => {
                        mainImage.classList.add('fade');
                        setTimeout(() => {
                            mainImage.src = img.src;
                            mainImage.classList.remove('fade');
                        }, 300);
                    });
                });
            }
            bindThumbClick();

            // 🎨 عند اختيار لون
            dots.forEach(dot => {
                dot.addEventListener('click', () => {
                    dots.forEach(d => d.classList.remove('active'));
                    dot.classList.add('active');
                    const data = JSON.parse(dot.dataset.color);

                    // تغيير الصورة الرئيسية
                    mainImage.classList.add('fade');
                    setTimeout(() => {
                        mainImage.src = `/storage/${data.main_image}`;
                        mainImage.classList.remove('fade');
                    }, 300);

                    // تحديث صور السلايدر
                    swiper.removeAllSlides();
                    (data.images || []).forEach(img => {
                        swiper.appendSlide(
                            `<div class='swiper-slide'><img src='/storage/${img}' alt='machine'></div>`
                        );
                    });
                    swiper.update();
                    setTimeout(bindThumbClick, 500);
                });
            });
        });
    </script>
    {{-- سكربت --}}
    <script>
        document.addEventListener('DOMContentLoaded', () => {

            // تحديث الشهور بناء على النظام
            const systemSelect = document.getElementById('systemSelect');
            const monthsSelect = document.getElementById('monthsSelect');
            systemSelect.addEventListener('change', () => {
                const selected = systemSelect.options[systemSelect.selectedIndex];
                const plans = selected.dataset.plans ? JSON.parse(selected.dataset.plans) : [];
                monthsSelect.innerHTML = `<option value="">اختر...</option>`;
                if (plans.length) {
                    monthsSelect.disabled = false;
                    plans.forEach(p => {
                        const opt = document.createElement('option');
                        opt.value = p.months;
                        opt.textContent = `${p.months} شهر (${p.interest}% فايدة)`;
                        monthsSelect.appendChild(opt);
                    });
                } else monthsSelect.disabled = true;
            });

            // تفعيل أقسام الحالة الوظيفية
            const ws = document.getElementById('work_status');
            const emp = document.getElementById('employee_block');
            const pen = document.getElementById('pension_block');
            const self = document.getElementById('self_block');

            function toggleWork() {
                [emp, pen, self].forEach(e => e.classList.add('d-none'));
                if (ws.value === 'employee') emp.classList.remove('d-none');
                if (ws.value === 'pension') pen.classList.remove('d-none');
                if (ws.value === 'self_employed') self.classList.remove('d-none');
            }
            ws.addEventListener('change', toggleWork);
            toggleWork();

            // معاينة الصور
            function preview(inputId, imgId) {
                const inp = document.getElementById(inputId);
                const img = document.getElementById(imgId);
                if (!inp) return;
                inp.addEventListener('change', e => {
                    const f = e.target.files[0];
                    if (!f || !f.type.startsWith('image/')) return img.classList.add('d-none');
                    img.src = URL.createObjectURL(f);
                    img.classList.remove('d-none');
                });
            }
            preview('applicant_id_front', 'preview_app_front');
            preview('applicant_id_back', 'preview_app_back');
            preview('guar_front', 'preview_guar_front');
            preview('guar_back', 'preview_guar_back');
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {

            const systemSelect = document.getElementById('systemSelect');
            const form = document.querySelector('form[action="{{ route('installments.store') }}"]');

            // ✅ لما يختار "مايلو"
            /*     systemSelect.addEventListener('change', (e) => {
                    const selected = e.target.value.trim();
                    if (selected === 'مايلو' || selected.toLowerCase() === 'milo') {
                        Swal.fire({
                            title: 'تم قبول طلبك مؤقتًا ✅',
                            text: 'يرجى التواصل معنا على الرقم 01000000000 لاستكمال الإجراءات.',
                            icon: 'success',
                            confirmButtonText: 'تمام',
                            confirmButtonColor: '#3085d6',
                        });
                    }
                }); */

            // ✅ كمان لما يضغط إرسال الطلب
            form.addEventListener('submit', (e) => {
                const selected = systemSelect.value.trim();
                if (selected === 'مايلو' || selected.toLowerCase() === 'milo') {
                    e.preventDefault(); // يمنع الإرسال مؤقتًا
                    Swal.fire({
                        title: 'تم قبول طلبك مؤقتًا ✅',
                        text: 'يرجى التواصل معنا على الرقم 01000000000 لاستكمال الإجراءات.',
                        icon: 'success',
                        confirmButtonText: 'تمام',
                        confirmButtonColor: '#3085d6',
                    }).then(() => {
                        form.submit(); // بعد الضغط على "تمام" يكمّل الإرسال
                    });
                }
            });
        });
    </script>

@endsection
