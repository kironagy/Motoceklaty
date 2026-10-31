<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $form->name }} - {{ site('general.site_name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    @include('forms.partials.styles')
    <script>window.__FORM_VOICE__ = {base: @json(asset('audio/form-eg-v1')), clips: @json(array_keys(json_decode(file_get_contents(resource_path('forms/voice-eg.json')), true)['clips']))};</script>
    <script defer src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.14.1/cdn.min.js"></script>
</head>
<body>
<div class="page" x-data="applicationForm()" @pointerdown="startVoice()" @keydown="startVoice()" x-cloak>
    <header class="top">
        <img class="logo" src="{{ site_url(site('general.logo')) }}" alt="{{ site('general.site_name') }}">
        <div class="top-text">
            <h1>{{ $form->name }}</h1>
            <div class="progress-line"><span :style="`width: ${progress.percent}%`"></span></div>
            <small x-text="progress.total ? `${progress.done} من ${progress.total} مطلوب` : ''"></small>
        </div>
    </header>
    <p class="muted" role="status" x-text="voiceStatus"></p><small class="muted">إرشادات صوتية مسجّلة بصوت مولّد بالذكاء الاصطناعي.</small>
    <div class="cap-overlay" x-show="capOffer" role="dialog" aria-modal="true" aria-label="تأكيد المقدم النقدي" @keydown.escape.window="if(capOffer) declineCap()">
        <section class="card cap-dialog">
            <h2>تأكيد المقدم النقدي</h2>
            <p>سعر المكنة أعلى من حد التمويل المتاح لحالتك. الفرق بيتدفع كاش مقدم، والباقي بالتقسيط.</p>
            <p><strong x-text="capOffer ? 'المقدم المطلوب: '+money(capOffer.plan.down_payment)+' جنيه' : ''"></strong></p>
            <p x-text="capOffer ? 'المدة '+capOffer.plan.months+' شهر، والقسط '+money(capOffer.plan.monthly)+' جنيه شهريًا' : ''"></p>
            <p class="muted" x-text="capOffer ? 'إجمالي المطلوب وقت الاستلام: '+money(capOffer.plan.upfront)+' جنيه شامل المصاريف الموضحة بالنظام' : ''"></p>
            <div class="actions"><button type="button" class="btn" @click="acceptCap()">موافق وأكمّل</button><button type="button" class="btn ghost" @click="declineCap()">أختار مكنة أو نظام تاني</button></div>
        </section>
    </div>

    <template x-if="done">
        <section class="card success">
            <div class="big-icon">✅</div>
            <h2>استلمنا طلبك بنجاح</h2>
            <p>هنراجع البيانات ونتواصل معاك على الواتساب في أقرب وقت.</p>
        </section>
    </template>

    <template x-if="!done">
        <main>
            @if ($link->purpose === 'fix' && filled($link->staff_note))
                <section class="card notice">
                    <strong>المطلوب تعديله:</strong>
                    <p>{{ $link->staff_note }}</p>
                </section>
            @elseif (filled($schema->settings()['intro'] ?? null))
                <section class="card notice"><p>{{ $schema->settings()['intro'] }}</p></section>
            @endif

            <section class="card machine-card" x-show="selectedMachine()">
                <img :src="selectedMachine()?.image" x-show="selectedMachine()?.image" alt="">
                <div>
                    <strong x-text="selectedMachine()?.name"></strong>
                    <div class="muted" x-show="selectedPlan()" x-text="selectedPlan() ? `${selectedPlan().months} شهر - القسط ${money(selectedPlan().monthly)} جنيه في الشهر` : ''"></div>
                    <div class="machine-cash" x-show="selectedPlan()?.capped" x-text="selectedPlan()?.capped ? `ومقدم كاش ${money(selectedPlan().down_payment)} جنيه (فرق التمويل)` : ''"></div>
                </div>
            </section>

            <section class="card notice" x-show="skipped && step >= skipped">
                <p>كمّلنا اللي قولته لنا على الواتساب. لو حابب تغيّر المكنة أو المدة أو أي حاجة <button type="button" class="link-btn" @click="go(0)">ارجع للأول</button>.</p>
            </section>

            <nav class="steps" x-show="shownSections().length > 1" aria-label="خطوات الطلب">
                <template x-for="(section, i) in shownSections()" :key="section.key">
                    <div class="step-wrap">
                        <button type="button" class="step-dot"
                                :class="{ active: i === step, done: i !== step && sectionDone(section) && canOpen(i), locked: !canOpen(i), bad: i < step && !sectionDone(section) }"
                                :disabled="!canOpen(i)" :title="section.title" @click="go(i)">
                            <span x-show="!(i !== step && sectionDone(section) && canOpen(i)) && canOpen(i)" x-text="i + 1"></span>
                            <span x-show="i !== step && sectionDone(section) && canOpen(i)">✓</span>
                            <svg x-show="!canOpen(i)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        </button>
                        <i class="step-line" x-show="i < shownSections().length - 1" :class="{ on: i < unlockedUpTo() }"></i>
                    </div>
                </template>
            </nav>
            <p class="step-title" x-show="shownSections().length > 1" x-text="`خطوة ${step + 1} من ${shownSections().length}: ${shownSections()[step]?.title || ''}`"></p>

            <template x-for="(section, i) in shownSections()" :key="section.key">
                <section class="card" x-show="i === step">
                    <h2 x-text="section.title"></h2>
                    <p class="muted" x-show="section.description" x-text="section.description"></p>

                    <template x-for="item in section.items" :key="item.key">
                        <div class="field" @focusin="speakField(item)" @click="speakField(item)" x-show="isVisible(item.key)" :id="'f-' + item.key">
                            {{-- display items --}}
                            <template x-if="item.type === 'note'">
                                <div class="note" x-text="item.message || item.label"></div>
                            </template>
                            <template x-if="item.type === 'stop'">
                                <div class="stop" x-text="item.message || item.label"></div>
                            </template>

                            <template x-if="!['note', 'stop'].includes(item.type)">
                                <div>
                                    <label :for="'in-' + item.key">
                                        <span x-text="item.label"></span>
                                        <b class="req" x-show="item.required">*</b>
                                    </label>
                                    <p class="help" x-show="item.help" x-text="item.help"></p>

                                    @include('forms.partials.inputs')

                                    <p class="error" x-show="errors[item.key]" x-text="errors[item.key]"></p>
                                </div>
                            </template>
                        </div>
                    </template>

                    <div class="actions">
                        <button type="button" class="btn ghost" x-show="i > 0" @click="go(i - 1)">السابق</button>
                        <button type="button" class="btn" x-show="i < shownSections().length - 1" @click="next(section, i)">التالي</button>
                        <button type="button" class="btn" x-show="i === shownSections().length - 1" :disabled="sending" @click="submit()">
                            <span x-text="sending ? 'جاري الإرسال...' : 'إرسال الطلب'"></span>
                        </button>
                    </div>
                </section>
            </template>

            <section class="card blocking" x-show="blocking.length">
                <template x-for="m in blocking"><p x-text="m"></p></template>
            </section>
            <section class="card missing" x-show="submitTried && progress.missing.length">
                <strong>فاضل:</strong>
                <ul><template x-for="m in progress.missing"><li x-text="m"></li></template></ul>
            </section>
            <p class="saved" x-text="savedNote"></p>
        </main>
    </template>

    <div class="lightbox" x-show="lightbox" x-transition.opacity @click="lightbox = null" @keydown.escape.window="lightbox = null">
        <img :src="lightbox" alt="">
        <button type="button" aria-label="قفل">×</button>
    </div>
</div>

<script>
    window.__FORM__ = {
        base: @json(url('/f/'.$link->token)),
        sections: @json($schema->sections()),
        state: @json($state),
        machines: @json($machines),
        governorates: @json($governorates),
        nidGovernorates: @json(\App\Support\EgyptianNationalId::GOVERNORATES),
        bindings: @json(collect($schema->fields())->map(fn ($f) => $f['binding'])->filter()),
    };
</script>
@include('forms.partials.script')
</body>
</html>
