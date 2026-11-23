{{--
    Owner 2026-11-23: the top of a request's edit page. A summary card (customer, status in its
    colour, machine, system, money, staff, how complete the data is) and a rail of the form's
    sections, each with its own completion ring, that jumps to (and opens) a section.
    The rail and the rings are filled in by the script below from the form itself.
--}}
@php
    use App\Filament\Resources\DeliveryResource;

    $status = (string) $record->status;
    $statusLabel = DeliveryResource::STATUS_LABELS[$status] ?? $status;
    $hues = [
        'success' => '#22c55e', 'warning' => '#f59e0b', 'danger' => '#ef4444',
        'info' => '#38bdf8', 'primary' => '#2dd4bf', 'gray' => '#94a3b8',
    ];
    // every status => its colour, so the review section follows the status picked in the form
    $statusHues = array_map(fn ($c) => $hues[$c] ?? $hues['gray'], DeliveryResource::STATUS_COLORS);
    $statusHue = $statusHues[$status] ?? $hues['gray'];

    $name = trim((string) $record->applicant_name) ?: 'عميل بدون اسم';
    $initials = mb_substr(preg_replace('/\s+/u', '', $name), 0, 1);
    $machine = trim(($record->machine?->brand?->name ?? '').' '.($record->machine?->name ?? ''));
    $money = fn ($v) => filled($v) && (float) $v > 0 ? number_format((float) $v).' ج.م' : null;

    $chips = array_filter([
        ['🏍️', 'المكنة', $machine ?: null],
        ['🏦', 'النظام', $record->installment_type ?: null],
        ['🗓️', 'المدة', $record->months ? $record->months.' شهر' : null],
        ['💰', 'سعر التقسيط', $money($record->machine_installment_price)],
        ['💵', 'المقدم', $money($record->deposit)],
        ['👤', 'الموظف', $record->staff?->name],
    ], fn ($c) => filled($c[2]));
@endphp

{{-- the review section is outlined in the request's status colour from the first paint --}}
<style>.fi-resource-deliveries [id="data.mragaa-altlb"] { --h: {{ $statusHue }}; }</style>

<div class="mg-rq" style="--st: {{ $statusHue }}" data-hues='@json($statusHues)' wire:ignore>
    <section class="mg-rq-hero">
        <span class="mg-rq-ghost" aria-hidden="true">#{{ $record->id }}</span>
        <span class="mg-rq-orb o1" aria-hidden="true"></span>
        <span class="mg-rq-orb o2" aria-hidden="true"></span>

        <div class="mg-rq-who">
            <div class="mg-rq-avatar"><span>{{ $initials }}</span></div>
            <div class="mg-rq-id">
                <div class="mg-rq-top">
                    <span class="mg-rq-no">طلب <bdi dir="ltr">#{{ $record->id }}</bdi></span>
                    <span class="mg-rq-status"><i></i>{{ $statusLabel }}</span>
                </div>
                <h2>{{ $name }}</h2>
                <div class="mg-rq-contact">
                    @if ($record->applicant_phone)
                        <a href="tel:{{ $record->applicant_phone }}" dir="ltr">📞 {{ $record->applicant_phone }}</a>
                    @endif
                    @if ($record->applicant_phone_2)
                        <a href="tel:{{ $record->applicant_phone_2 }}" dir="ltr">📞 {{ $record->applicant_phone_2 }}</a>
                    @endif
                    <span>🕒 {{ $record->created_at?->locale('ar')->diffForHumans() }}</span>
                </div>
            </div>
        </div>

        <div class="mg-rq-meter" title="نسبة البيانات المتملية في الفورم">
            <svg class="mg-rq-meter-svg" viewBox="0 0 120 120" aria-hidden="true">
                <circle class="track" cx="60" cy="60" r="52"></circle>
                <circle class="fill" cx="60" cy="60" r="52"></circle>
            </svg>
            <div><strong data-mg-rq-total>0%</strong><span>البيانات مكتملة</span></div>
        </div>

        @if ($chips)
            <div class="mg-rq-chips">
                @foreach (array_values($chips) as $i => [$icon, $label, $value])
                    <div class="mg-rq-chip" style="--i: {{ $i }}">
                        <em>{{ $icon }}</em>
                        <span>{{ $label }}</span>
                        <strong>{{ $value }}</strong>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <nav class="mg-rq-rail" aria-label="أقسام الطلب"></nav>
</div>

<script>
    (() => {
        const root = document.currentScript.previousElementSibling;
        const rail = root.querySelector('.mg-rq-rail');
        const total = root.querySelector('[data-mg-rq-total]');
        const meter = root.querySelector('.mg-rq-meter');
        const C = 2 * Math.PI * 52;

        // the form's top-level sections (a section inside another counts with its parent)
        const sections = () => [...document.querySelectorAll('.fi-page form .fi-section')]
            .filter((s) => ! s.parentElement.closest('.fi-section') && s.querySelector(':scope > header .fi-section-header-heading'));

        // every field of the section counts, also while the section is folded (Filament doesn't
        // render the fields it hides), except switches; it is filled when it holds a value
        const fields = (section) => [...section.querySelectorAll('.fi-fo-field-wrp')].filter((w) =>
            ! w.querySelector('.fi-fo-toggle, input[type=checkbox], input[type=radio]') && ! w.querySelector('.fi-fo-field-wrp'));

        const filled = (w) => {
            if (w.querySelector('.filepond--item, .fi-fo-file-upload img')) return true;
            const single = w.querySelector('.choices__list--single .choices__item');
            if (single) return ! single.classList.contains('choices__placeholder') && single.textContent.trim() !== '';
            const select = w.querySelector('select');
            if (select) return select.value !== '';
            const input = w.querySelector('input:not([type=hidden]):not([type=search]):not([type=file]), textarea');
            return input ? input.value.trim() !== '' && input.value !== '0.00' : false;
        };

        const pct = (section) => {
            const list = fields(section);
            return list.length ? Math.round(list.filter(filled).length * 100 / list.length) : null;
        };

        const build = () => {
            const list = sections();
            if (! list.length || rail.dataset.count === String(list.length)) return list;
            rail.dataset.count = list.length;
            rail.innerHTML = '';
            list.forEach((s, i) => {
                const icon = s.querySelector(':scope > header .fi-section-header-icon');
                const a = document.createElement('a');
                a.href = '#';
                a.className = 'mg-rq-step';
                a.style.setProperty('--i', i);
                a.style.setProperty('--h', getComputedStyle(s).getPropertyValue('--h').trim() || '#2dd4bf');
                a.innerHTML = '<span class="mg-rq-ring"><svg class="mg-rq-ring-svg" viewBox="0 0 36 36"><circle class="track" cx="18" cy="18" r="15"></circle><circle class="fill" cx="18" cy="18" r="15"></circle></svg>'
                    + (icon ? icon.outerHTML : '') + '</span><span class="mg-rq-step-txt"><b></b><small></small></span>';
                a.querySelector('b').textContent = s.querySelector(':scope > header .fi-section-header-heading').textContent.trim();
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    s.dispatchEvent(new CustomEvent('expand'));
                    setTimeout(() => s.scrollIntoView({ behavior: 'smooth', block: 'start' }), 60);
                });
                a._section = s;
                rail.appendChild(a);
            });
            return list;
        };

        // the review section's outline follows the status picked, before it's even saved
        const hues = JSON.parse(root.dataset.hues || '{}');
        const paintReview = () => {
            const review = document.querySelector('[id="data.mragaa-altlb"]');
            const status = review?.querySelector('[id="data.status"]');
            if (review && status && hues[status.value]) review.style.setProperty('--h', hues[status.value]);
        };

        const refresh = () => {
            paintReview();
            const list = build();
            let sum = 0, count = 0;
            rail.querySelectorAll('.mg-rq-step').forEach((a) => {
                if (! document.contains(a._section)) { rail.dataset.count = ''; return; }
                const p = pct(a._section);
                a.style.setProperty('--h', getComputedStyle(a._section).getPropertyValue('--h').trim() || '#2dd4bf');
                a.querySelector('small').textContent = p === null ? '—' : p === 100 ? 'كامل ✓' : p + '%';
                a.querySelector('.fill').style.strokeDashoffset = 94.25 * (1 - (p ?? 0) / 100);
                a.classList.toggle('done', p === 100);
                if (p === null) return;
                const n = fields(a._section).length;
                sum += p * n; count += n;
            });
            const all = count ? Math.round(sum / count) : 0;
            total.textContent = all + '%';
            meter.querySelector('.fill').style.strokeDashoffset = C * (1 - all / 100);
            meter.classList.toggle('done', all === 100);
            return list;
        };

        // the step of the section on screen lights up
        let spy;
        const watch = () => {
            spy?.disconnect();
            spy = new IntersectionObserver((entries) => entries.forEach((en) => {
                if (! en.isIntersecting) return;
                rail.querySelectorAll('.mg-rq-step').forEach((a) => a.classList.toggle('on', a._section === en.target));
            }), { rootMargin: '-35% 0px -55% 0px' });
            sections().forEach((s) => spy.observe(s));
        };

        const start = () => { refresh(); watch(); };
        setTimeout(start, 300);
        document.addEventListener('input', () => requestAnimationFrame(refresh), true);
        document.addEventListener('change', () => setTimeout(refresh, 50), true);
        // Livewire re-renders (a section opening, a file uploading) change the form under us
        setInterval(() => { const before = rail.dataset.count; refresh(); if (rail.dataset.count !== before) watch(); }, 2000);
    })();
</script>
