<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ site('general.site_name') }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800&display=swap" rel="stylesheet">
    @include('forms.partials.styles')
</head>
<body>
<div class="page">
    <header class="top"><img class="logo" src="{{ site_url(site('general.logo')) }}" alt="{{ site('general.site_name') }}"></header>
    <section class="card closed">
        @if ($reason === 'submitted')
            <div class="big-icon">✅</div>
            <h2>طلبك وصلنا</h2>
            <p class="muted">بنراجع البيانات وهنكلمك على الواتساب أول ما يطلع الرد.</p>
        @else
            <div class="big-icon">⌛</div>
            <h2>الرابط ده مبقاش شغال</h2>
            <p class="muted">كلّمنا على الواتساب وهنبعتلك رابط جديد فيه كل اللي كتبته.</p>
        @endif
    </section>
</div>
</body>
</html>
