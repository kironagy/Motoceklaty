@extends('layouts.app')
@section('title', 'من نحن | موتسيكلاتي')

@section('content')
<style>
    .about-page {
        direction: rtl;
        font-family: "Cairo", sans-serif;
        color: #0f172a;
        background: #fff;
    }

    .about-page .container {
        position: relative;
        z-index: 2;
    }

    .about-hero {
        position: relative;
        min-height: 70vh;
        display: flex;
        align-items: center;
        text-align: center;
        color: #fff;
        background:
            linear-gradient(135deg, rgba(3, 15, 23, 0.78), rgba(20, 176, 191, 0.45)),
            url('https://firebasestorage.googleapis.com/v0/b/file-upload-d8004.appspot.com/o/1761011823928-MEITU_20251020_070445468.jpg?alt=media&token=a1171b57-93c0-462a-87e7-34d56b68ae82') center/cover no-repeat;
        overflow: hidden;
    }

    .about-hero::after {
        content: "";
        position: absolute;
        inset: 0;
        background: radial-gradient(circle at top, rgba(255,255,255,0.08), transparent 45%);
        z-index: 1;
    }

    .about-hero-content {
        width: 100%;
        padding: 120px 0 100px;
    }

    .about-badge {
        display: inline-block;
        padding: 8px 16px;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 999px;
        font-size: 14px;
        margin-bottom: 18px;
        backdrop-filter: blur(8px);
    }

    .about-hero h1 {
        font-size: clamp(2.2rem, 5vw, 4rem);
        font-weight: 800;
        margin-bottom: 16px;
    }

    .about-hero p {
        max-width: 760px;
        margin: 0 auto;
        font-size: 1.08rem;
        line-height: 2;
        color: #eefbfd;
    }

    .about-section {
        padding: 90px 0;
    }

    .about-section-title {
        font-size: 2rem;
        font-weight: 800;
        color: #0b2e33;
        margin-bottom: 18px;
        position: relative;
        display: inline-block;
        padding-right: 14px;
    }

    .about-section-title::before {
        content: "";
        position: absolute;
        right: 0;
        top: 8px;
        bottom: 8px;
        width: 5px;
        border-radius: 10px;
        background: #14b0bf;
    }

    .about-story-text {
        color: #475569;
        font-size: 1.03rem;
        line-height: 2;
    }

    .about-story-text .lead {
        font-size: 1.15rem;
        color: #334155;
    }

    .about-image-wrap {
        position: relative;
    }

    .about-image-wrap img {
        width: 100%;
        border-radius: 24px;
        display: block;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.10);
    }

    .about-image-card {
        position: absolute;
        left: 20px;
        bottom: 20px;
        background: rgba(255,255,255,0.92);
        backdrop-filter: blur(8px);
        border-radius: 18px;
        padding: 14px 18px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.10);
        min-width: 190px;
    }

    .about-image-card strong {
        display: block;
        color: #0b2e33;
        font-size: 1.1rem;
    }

    .about-image-card span {
        color: #64748b;
        font-size: 0.95rem;
    }

    .company-link-box {
        margin-top: 24px;
        background: #f8fbfb;
        border: 1px solid #e7f1f2;
        padding: 18px 20px;
        border-radius: 14px;
        color: #334155;
        line-height: 2;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }

    .company-link-box p {
        margin-bottom: 0;
    }

    .about-features {
        background: linear-gradient(to bottom, #ffffff, #f7fbfc);
    }

    .about-card {
        height: 100%;
        background: #fff;
        border: 1px solid #e6f3f4;
        border-radius: 22px;
        padding: 30px 24px;
        text-align: center;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
        transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
    }

    .about-card:hover {
        transform: translateY(-6px);
        box-shadow: 0 16px 36px rgba(15, 23, 42, 0.08);
        border-color: #cfeef1;
    }

    .about-icon {
        width: 72px;
        height: 72px;
        margin: 0 auto 18px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(20, 176, 191, 0.10);
        color: #14b0bf;
        font-size: 2rem;
    }

    .about-card h5 {
        font-weight: 800;
        margin-bottom: 12px;
        color: #0f172a;
    }

    .about-card p {
        color: #64748b;
        line-height: 1.9;
        margin-bottom: 0;
    }

    .about-stats {
        background: #0f172a;
        color: #fff;
        position: relative;
        overflow: hidden;
    }

    .about-stats::before {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(20,176,191,0.15), transparent 55%);
    }

    .about-stat-box {
        text-align: center;
        position: relative;
        z-index: 2;
    }

    .about-stat-number {
        font-size: clamp(2rem, 4vw, 3rem);
        font-weight: 800;
        color: #22d3ee;
        display: block;
        margin-bottom: 8px;
    }

    .about-stat-label {
        color: #cbd5e1;
        font-size: 1rem;
    }

    .about-why-box {
        height: 100%;
        background: #f8fbfb;
        border: 1px solid #e7f1f2;
        border-radius: 20px;
        padding: 30px 22px;
        text-align: center;
        transition: all .25s ease;
    }

    .about-why-box:hover {
        transform: translateY(-5px);
        box-shadow: 0 14px 34px rgba(15, 23, 42, 0.06);
    }

    .about-why-box i {
        font-size: 2.1rem;
        color: #14b0bf;
        margin-bottom: 14px;
        display: inline-block;
    }

    .about-why-box h6 {
        font-size: 1.05rem;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 10px;
    }

    .about-why-box p {
        color: #64748b;
        line-height: 1.9;
        margin-bottom: 0;
    }

    .legal-box {
        background: #fff;
        border: 1px solid #e6f3f4;
        border-radius: 18px;
        padding: 28px 24px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
    }

    .legal-box ul {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 14px;
    }

    .legal-box li {
        padding: 14px 16px;
        border: 1px solid #eef2f7;
        border-radius: 12px;
        background: #f8fbfb;
        color: #334155;
        line-height: 1.9;
    }

    .legal-box span {
        display: inline-block;
        min-width: 170px;
        color: #0b2e33;
        font-weight: 700;
    }

    .about-reviews {
        background: linear-gradient(180deg, #08131c, #0b1d29);
        color: #fff;
        overflow: hidden;
    }

    .about-reviews .about-section-title {
        color: #fff;
    }

    .about-reviews .about-section-title::before {
        background: #22d3ee;
    }

    .about-review-box {
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(34, 211, 238, 0.16);
        border-radius: 24px;
        padding: 36px 28px;
        max-width: 760px;
        margin: auto;
        text-align: center;
        backdrop-filter: blur(8px);
    }

    .about-review-icon {
        font-size: 2.4rem;
        color: #22d3ee;
        margin-bottom: 18px;
        display: block;
    }

    .about-review-text {
        color: #e2e8f0;
        font-size: 1.06rem;
        line-height: 2;
        margin-bottom: 14px;
        word-break: break-word;
    }

    .about-review-name {
        color: #67e8f9;
        font-weight: 700;
        margin-bottom: 0;
    }

    .about-cta {
        background: linear-gradient(135deg, #f8fbfc, #eef9fb);
        text-align: center;
    }

    .about-cta-box {
        max-width: 760px;
        margin: auto;
        background: #fff;
        border: 1px solid #e4f2f3;
        border-radius: 28px;
        padding: 48px 30px;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.05);
    }

    .about-cta h2 {
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 14px;
    }

    .about-cta p {
        color: #64748b;
        line-height: 2;
        margin-bottom: 26px;
    }

    .about-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 14px 34px;
        border-radius: 999px;
        background: #14b0bf;
        color: #fff;
        text-decoration: none;
        font-weight: 700;
        transition: all .25s ease;
        border: none;
    }

    .about-btn:hover {
        background: #1099a6;
        color: #fff;
        transform: translateY(-2px);
    }

    .about-swiper {
        padding-bottom: 55px;
    }

    .about-swiper .swiper-pagination-bullet {
        background: #22d3ee;
        opacity: .35;
    }

    .about-swiper .swiper-pagination-bullet-active {
        opacity: 1;
        transform: scale(1.2);
    }

    @media (max-width: 991.98px) {
        .about-section {
            padding: 70px 0;
        }

        .about-image-wrap {
            margin-top: 30px;
        }
    }

    @media (max-width: 767.98px) {
        .about-hero-content {
            padding: 100px 0 80px;
        }

        .about-section-title {
            font-size: 1.6rem;
        }

        .about-image-card {
            position: static;
            margin-top: 16px;
        }

        .about-card,
        .about-why-box,
        .about-review-box,
        .about-cta-box,
        .legal-box {
            border-radius: 18px;
        }

        .legal-box span {
            min-width: auto;
            display: block;
            margin-bottom: 6px;
        }
    }
</style>

<div class="about-page">

    <section class="about-hero">
        <div class="container">
            <div class="about-hero-content">
                <span class="about-badge">منصة متخصصة في بيع وشراء الدراجات النارية في مصر</span>
                <h1>موتسيكلاتي</h1>
                <p>
                    الحرية تبدأ من هنا — تجربة شراء أو بيع موتوسيكل بشكل أوضح، أسرع، وبثقة أكبر.
                </p>
            </div>
        </div>
    </section>

    <section class="about-section">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <h2 class="about-section-title">قصتنا</h2>
                    <div class="about-story-text">
                        <p class="lead mb-4">
                            في <strong>موتسيكلاتي</strong> قررنا نغيّر فكرة شراء المكن في مصر، ونخليها أبسط وأوضح وأقرب للعميل.
                        </p>
                        <p>
                            بنقدّم منصة متخصصة تجمع كل اللي يهمك في مكان واحد:
                            موديلات متنوعة، خيارات مناسبة، وتجربة تصفح مريحة تساعدك تاخد قرارك بثقة.
                        </p>
                        <p>
                            هدفنا إنك تلاقي الموتوسيكل المناسب بسهولة، وتشوف التفاصيل بوضوح، وتحس إنك بتتعامل مع جهة فاهمة احتياجك فعلًا.
                        </p>
                        <p class="mb-0">
                            إحنا مش بس بنعرض منتجات، إحنا بنبني تجربة احترافية بروح شبابية تناسب السوق المصري.
                        </p>
                    </div>

                    <div class="company-link-box">
                        <p>
                            <strong>Motocyklaty</strong> هي إحدى العلامات التجارية التابعة لشركة
                            <strong>الحاوي (Elhawy)</strong>، ويتم تشغيلها وإدارتها من خلال
                            <strong>Elhawy Motors</strong> المتخصصة في بيع وشراء الدراجات النارية
                            والمركبات داخل مصر.
                        </p>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="about-image-wrap">
                        <img src="https://firebasestorage.googleapis.com/v0/b/file-upload-d8004.appspot.com/o/1761011751152-MEITU_20251020_065823772.jpg?alt=media&token=310e982b-de8a-4a0a-b892-1aed65f6628a"
                             alt="موتسيكلاتي">
                        <div class="about-image-card">
                            <strong>Motocyklaty</strong>
                            <span>خدمة موثوقة وتجربة أبسط للشراء والبيع</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="about-section about-features">
        <div class="container text-center">
            <h2 class="about-section-title mb-5">مميزات موتسيكلاتي</h2>

            <div class="row g-4">
                <div class="col-md-4">
                    <div class="about-card">
                        <div class="about-icon"><i class="bi bi-shield-check"></i></div>
                        <h5>ضمان وجودة</h5>
                        <p>نهتم بتقديم دراجات بحالة ممتازة مع وضوح في التفاصيل وجودة تديك راحة واطمئنان قبل القرار.</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="about-card">
                        <div class="about-icon"><i class="bi bi-cash-coin"></i></div>
                        <h5>حلول دفع مرنة</h5>
                        <p>سواء كنت بتدور على كاش أو أنظمة مناسبة، بنحاول نوفر اختيارات تسهّل عليك خطوة الشراء.</p>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="about-card">
                        <div class="about-icon"><i class="bi bi-wrench-adjustable-circle"></i></div>
                        <h5>دعم ومتابعة</h5>
                        <p>مش بنقف عند البيع وبس، لكن بنهتم كمان بإن تكون التجربة مريحة وواضحة من البداية للنهاية.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="about-section about-stats">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-3 col-6">
                    <div class="about-stat-box">
                        <span class="about-stat-number stat" data-target="15">0</span>
                        <div class="about-stat-label">سنة خبرة</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="about-stat-box">
                        <span class="about-stat-number stat" data-target="2000">0</span>
                        <div class="about-stat-label">عميل سعيد</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="about-stat-box">
                        <span class="about-stat-number stat" data-target="150">0</span>
                        <div class="about-stat-label">موديل مختلف</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="about-stat-box">
                        <span class="about-stat-number stat" data-target="2">0</span>
                        <div class="about-stat-label">فروعنا في مصر</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="about-section">
        <div class="container text-center">
            <h2 class="about-section-title mb-5">ليه الناس بتختار موتسيكلاتي؟</h2>

            <div class="row g-4">
                <div class="col-md-3 col-6">
                    <div class="about-why-box">
                        <i class="bi bi-heart"></i>
                        <h6>شغف حقيقي بالمكن</h6>
                        <p>بنشتغل بحب وفهم للسوق، وده ظاهر في كل تفصيلة بنقدّمها.</p>
                    </div>
                </div>

                <div class="col-md-3 col-6">
                    <div class="about-why-box">
                        <i class="bi bi-box-seam"></i>
                        <h6>اختيارات متنوعة</h6>
                        <p>بنوفّر موديلات مختلفة تناسب استخدامات وأذواق متعددة.</p>
                    </div>
                </div>

                <div class="col-md-3 col-6">
                    <div class="about-why-box">
                        <i class="bi bi-person-lines-fill"></i>
                        <h6>دعم سريع</h6>
                        <p>فريقنا جاهز يساعدك ويوضح لك أي نقطة محتاج تعرفها قبل الشراء.</p>
                    </div>
                </div>

                <div class="col-md-3 col-6">
                    <div class="about-why-box">
                        <i class="bi bi-geo-alt"></i>
                        <h6>سهولة الوصول</h6>
                        <p>هدفنا نخلي الوصول للمعلومة والمنتج المناسب أسهل وأسرع.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="about-section">
        <div class="container">
            <h2 class="about-section-title mb-4">البيانات الرسمية</h2>

            <div class="legal-box">
                <ul>
                    <li><span>اسم النشاط:</span> Elhawy Motors</li>
                    <li><span>الشركة المالكة:</span> شركة الحاوي</li>
                    <li><span>العلامة التجارية:</span> Motocyklaty</li>
                    <li><span>رقم السجل التجاري:</span> 35575 </li>
                    <li><span>الرقم الضريبي:</span> 2163406268406216 </li>
                </ul>
            </div>
        </div>
    </section>

    <section class="about-section about-reviews" dir="rtl">
        <div class="container text-center">
            <h2 class="about-section-title mb-5">آراء عملائنا</h2>

            <div class="swiper about-swiper">
                <div class="swiper-wrapper">
                    @isset($reviews)
                        @forelse ($reviews as $review)
                            <div class="swiper-slide">
                                <div class="about-review-box">
                                    <i class="bi bi-chat-quote about-review-icon"></i>
                                    <p class="about-review-text">"{{ $review->review }}"</p>
                                    <p class="about-review-name">— {{ $review->name }}</p>
                                </div>
                            </div>
                        @empty
                            <div class="swiper-slide">
                                <div class="about-review-box">
                                    <i class="bi bi-chat-quote about-review-icon"></i>
                                    <p class="about-review-text">لا توجد آراء بعد.</p>
                                </div>
                            </div>
                        @endforelse
                    @else
                        <div class="swiper-slide">
                            <div class="about-review-box">
                                <i class="bi bi-chat-quote about-review-icon"></i>
                                <p class="about-review-text">لا توجد آراء بعد.</p>
                            </div>
                        </div>
                    @endisset
                </div>

                <div class="swiper-pagination"></div>
            </div>
        </div>
    </section>

    <section class="about-section about-cta">
        <div class="container">
            <div class="about-cta-box">
                <h2>جاهز تبدأ رحلتك؟ 🏍️</h2>
                <p>
                    اكتشف الموتوسيكل المناسب ليك وابدأ تجربة قيادة أقرب لأسلوبك واحتياجك.
                </p>
                <a href="/#installment" class="about-btn">ابدأ دلوقتي</a>
            </div>
        </div>
    </section>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const swiperEl = document.querySelector(".about-swiper");

        if (swiperEl) {
            new Swiper(".about-swiper", {
                loop: true,
                slidesPerView: 1,
                spaceBetween: 24,
                autoplay: {
                    delay: 3500,
                    disableOnInteraction: false,
                },
                pagination: {
                    el: ".about-swiper .swiper-pagination",
                    clickable: true,
                },
                speed: 800,
            });
        }

        const counters = document.querySelectorAll('.about-page .stat');

        counters.forEach(counter => {
            counter.innerText = '0';

            const updateCounter = () => {
                const target = +counter.getAttribute('data-target');
                const count = +counter.innerText;
                const increment = Math.max(1, Math.ceil(target / 80));

                if (count < target) {
                    counter.innerText = Math.min(target, count + increment);
                    requestAnimationFrame(updateCounter);
                } else {
                    counter.innerText = target;
                }
            };

            updateCounter();
        });
    });
</script>
@endsection
