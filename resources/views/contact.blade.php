@extends('layouts.app')
@section('title', 'تواصل معنا | موتسيكلاتي')

@section('content')
<style>
    .contact-page {
        direction: rtl;
        font-family: "Cairo", sans-serif;
        color: #0f172a;
        background: #fff;
    }

    .contact-page .container {
        position: relative;
        z-index: 2;
    }

    .contact-hero {
        position: relative;
        min-height: 55vh;
        display: flex;
        align-items: center;
        text-align: center;
        color: #fff;
        background:
            linear-gradient(135deg, rgba(3, 15, 23, 0.82), rgba(20, 176, 191, 0.45)),
            url('https://firebasestorage.googleapis.com/v0/b/file-upload-d8004.appspot.com/o/1761011823928-MEITU_20251020_070445468.jpg?alt=media&token=a1171b57-93c0-462a-87e7-34d56b68ae82') center/cover no-repeat;
        overflow: hidden;
    }

    .contact-hero::after {
        content: "";
        position: absolute;
        inset: 0;
        background: radial-gradient(circle at top, rgba(255,255,255,0.08), transparent 45%);
        z-index: 1;
    }

    .contact-hero-content {
        width: 100%;
        padding: 110px 0 90px;
        position: relative;
        z-index: 2;
    }

    .contact-badge {
        display: inline-block;
        padding: 8px 16px;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 999px;
        font-size: 14px;
        margin-bottom: 18px;
        backdrop-filter: blur(8px);
    }

    .contact-hero h1 {
        font-size: clamp(2.1rem, 5vw, 3.8rem);
        font-weight: 800;
        margin-bottom: 16px;
    }

    .contact-hero p {
        max-width: 760px;
        margin: 0 auto;
        font-size: 1.06rem;
        line-height: 2;
        color: #eefbfd;
    }

    .contact-section {
        padding: 90px 0;
    }

    .contact-section-title {
        font-size: 2rem;
        font-weight: 800;
        color: #0b2e33;
        margin-bottom: 18px;
        position: relative;
        display: inline-block;
        padding-right: 14px;
    }

    .contact-section-title::before {
        content: "";
        position: absolute;
        right: 0;
        top: 8px;
        bottom: 8px;
        width: 5px;
        border-radius: 10px;
        background: #14b0bf;
    }

    .contact-intro {
        color: #475569;
        line-height: 2;
        font-size: 1.03rem;
        margin-bottom: 0;
    }

    .contact-grid {
        display: grid;
        grid-template-columns: 1.1fr 0.9fr;
        gap: 26px;
        align-items: start;
    }

    .contact-card {
        background: #fff;
        border: 1px solid #e6f3f4;
        border-radius: 24px;
        padding: 30px 26px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        height: 100%;
    }

    .contact-card h3 {
        font-size: 1.35rem;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 18px;
    }

    .contact-card p {
        color: #64748b;
        line-height: 2;
        margin-bottom: 16px;
    }

    .contact-list,
    .contact-legal-list,
    .contact-hours-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 14px;
    }

    .contact-list li,
    .contact-legal-list li,
    .contact-hours-list li {
        background: #f8fbfb;
        border: 1px solid #e7f1f2;
        border-radius: 14px;
        padding: 14px 16px;
        color: #334155;
        line-height: 1.9;
    }

    .contact-list span,
    .contact-legal-list span,
    .contact-hours-list span {
        display: inline-block;
        min-width: 150px;
        color: #0b2e33;
        font-weight: 700;
    }

    .contact-actions {
        display: flex;
        gap: 14px;
        flex-wrap: wrap;
        margin-top: 22px;
    }

    .contact-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 14px 26px;
        border-radius: 999px;
        text-decoration: none;
        font-weight: 700;
        transition: all .25s ease;
        border: none;
    }

    .contact-btn-whatsapp {
        background: #14b86a;
        color: #fff;
    }

    .contact-btn-whatsapp:hover {
        background: #109458;
        color: #fff;
        transform: translateY(-2px);
    }

    .contact-btn-call {
        background: #14b0bf;
        color: #fff;
    }

    .contact-btn-call:hover {
        background: #1099a6;
        color: #fff;
        transform: translateY(-2px);
    }

    .contact-brand-box {
        margin-top: 24px;
        background: #f8fbfb;
        border: 1px solid #e7f1f2;
        border-radius: 16px;
        padding: 18px 20px;
        color: #334155;
        line-height: 2;
    }

    .contact-brand-box p:last-child {
        margin-bottom: 0;
    }

    .contact-map-box {
        background: linear-gradient(135deg, #0f172a, #10222d);
        color: #fff;
        border-radius: 24px;
        padding: 32px 26px;
        box-shadow: 0 18px 34px rgba(15, 23, 42, 0.08);
    }

    .contact-map-box h3 {
        font-size: 1.35rem;
        font-weight: 800;
        margin-bottom: 16px;
    }

    .contact-map-box p {
        color: #dbeafe;
        line-height: 2;
        margin-bottom: 18px;
    }

    .contact-note {
        margin-top: 18px;
        font-size: 0.95rem;
        color: #cbd5e1;
        line-height: 1.9;
    }

    @media (max-width: 991.98px) {
        .contact-section {
            padding: 70px 0;
        }

        .contact-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 767.98px) {
        .contact-hero-content {
            padding: 95px 0 75px;
        }

        .contact-section-title {
            font-size: 1.6rem;
        }

        .contact-card,
        .contact-map-box {
            border-radius: 18px;
        }

        .contact-list span,
        .contact-legal-list span,
        .contact-hours-list span {
            display: block;
            min-width: auto;
            margin-bottom: 6px;
        }
    }
</style>

<div class="contact-page">

    <section class="contact-hero">
        <div class="container">
            <div class="contact-hero-content">
                <span class="contact-badge">نحن هنا لمساعدتك في أي استفسار</span>
                <h1>تواصل معنا</h1>
                <p>
                    تواصل مع فريق موتوسيكلاتي لأي استفسار بخصوص شراء أو بيع الدراجات النارية،
                    أو لمعرفة تفاصيل أكثر عن الخدمات المتاحة وطرق التواصل الرسمية.
                </p>
            </div>
        </div>
    </section>

    <section class="contact-section">
        <div class="container">
            <div class="row mb-5">
                <div class="col-lg-8">
                    <h2 class="contact-section-title">معلومات التواصل</h2>
                    <p class="contact-intro">
                        نسعد دائمًا بالرد على جميع استفساراتك ومساعدتك في الوصول إلى أفضل الخيارات المناسبة لك.
                        يمكنك التواصل معنا مباشرة عبر الهاتف أو واتساب، كما يمكنك مراجعة بيانات النشاط الرسمية بالأسفل.
                    </p>
                </div>
            </div>

            <div class="contact-grid">
                <div class="contact-card">
                    <h3>بيانات التواصل المباشر</h3>

                    <ul class="contact-list">
                        <li>
                            <span>رقم الهاتف:</span>
                            01028887119
                        </li>
                        <li>
                            <span>واتساب:</span>
                            01028887119
                        </li>
                        <li>
                            <span>البريد الإلكتروني:</span>
                            ahmed.work.ceo@gmail.com
                        </li>
                        <li>
                            <span>الدولة:</span>
                            مصر
                        </li>
                    </ul>

                    <div class="contact-actions">
                        <a href="https://wa.me/01028887119" target="_blank" class="contact-btn contact-btn-whatsapp">
                            <i class="bi bi-whatsapp"></i>
                            تواصل عبر واتساب
                        </a>

                        <a href="tel:01028887119" class="contact-btn contact-btn-call">
                            <i class="bi bi-telephone"></i>
                            اتصل الآن
                        </a>
                    </div>

                    <div class="contact-brand-box">
                        <p>
                            <strong>Motocyklaty</strong> هي إحدى العلامات التجارية التابعة لشركة
                            <strong>الحاوي (Elhawy)</strong>، ويتم تشغيلها وإدارتها من خلال
                            <strong>Elhawy Motors</strong>.
                        </p>
                        <p>
                            نحن متخصصون في بيع وشراء الدراجات النارية والمركبات داخل مصر.
                        </p>
                    </div>
                </div>

                <div class="contact-map-box">
                    <h3>البيانات الرسمية</h3>

                    <ul class="contact-legal-list">
                        <li>
                            <span>اسم النشاط:</span>
                            Elhawy Motors
                        </li>
                        <li>
                            <span>الشركة المالكة:</span>
                            شركة الحاوي
                        </li>
                        <li>
                            <span>العلامة التجارية:</span>
                            Motocyklaty
                        </li>
                        <li>
                            <span>رقم السجل التجاري:</span>
                            35575
                        </li>
                        <li>
                            <span>الرقم الضريبي:</span>
                            2163406268406216
                        </li>
                    </ul>

                    <div class="contact-note">
                        هذه الصفحة تمثل وسيلة التواصل الرسمية الخاصة بعلامة Motocyklaty
                        وتوضح ارتباطها القانوني والتجاري بالنشاط والشركة المالكة.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="contact-section" style="background: #f8fbfc;">
        <div class="container">
            <h2 class="contact-section-title mb-4">مواعيد العمل</h2>

            <div class="contact-card">
                <ul class="contact-hours-list">
                    <li>
                        <span>من السبت إلى الخميس:</span>
                        10:00 صباحًا - 8:00 مساءً
                    </li>
                    <li>
                        <span>الجمعة:</span>
                        مغلق
                    </li>
                </ul>
            </div>
        </div>
    </section>
</div>
@endsection