@extends('layouts.site')

@section('title', (string) (site('installment.apply.seo_title')))

@php($staff = session('referred_staff_id') ? \App\Models\Staff::find(session('referred_staff_id')) : null)

@section('body_class', 'contact-page interior-page apply-page source-design')
@section('design_css', 'pages')
{{-- No apply page in motogate-2: same parts as its contact page (page-heading, surface form panel). --}}
@section('content')
<main id="main" dir="rtl"><div class="page-heading"><span class="mini-label">{{ site('installment.apply.small_title') }}</span><h1>{{ site('installment.apply.banner_title') }}</h1><p>{{ site('installment.apply.text') }}</p></div>
<section class="surface contact-form-panel site-apply-card"><h2>{{ site('installment.apply.title') }}</h2>
                        @if (session('tracking_code'))
                            <div class="alert alert-success"><h2 class="h5">احتفظ بكود ورابط متابعة طلبك</h2><label for="receipt-code">كود المتابعة</label><input id="receipt-code" class="form-control my-2" dir="ltr" readonly value="{{ session('tracking_code') }}"><a class="button button-orange" href="{{ route('tracking.index', ['code' => session('tracking_code')]) }}">متابعة حالة الطلب</a></div>
                        @endif

                        @if ($staff)
                            <div class="alert alert-info text-center">
                                هذا الطلب سيتم تسجيله باسم الموظف: <strong>{{ $staff->name }}</strong>
                            </div>
                        @endif

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <h6 class="fw-bold mb-2">يوجد بعض الأخطاء:</h6>
                                <ul class="mb-0 ps-3">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if (session('ocr_report'))
                            <div class="alert alert-info">
                                <h6 class="fw-bold mb-2">نتائج القراءة من المستندات:</h6>
                                {!! session('ocr_report') !!}
                            </div>
                        @endif

                        @if (session('success'))
                            <div class="alert alert-success">{{ session('success') }}</div>
                        @endif

                        <form method="POST" action="{{ route('installments.store') }}" enctype="multipart/form-data">
                            @csrf
                            @if ($staff)
                                <input type="hidden" name="staff_id" value="{{ $staff->id }}">
                            @endif

                            <h3 class="site-apply-title mini-label">المكنة ونظام القسط</h3>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">اختر المكنة</label>
                                    <select id="application-machine" name="machine_id" class="form-select form-control" required>
                                        <option value="">اختر المكنة...</option>
                                        @foreach ($machines as $machine)
                                            <option value="{{ $machine->id }}" @selected((string) old('machine_id', $selectedMachineId) === (string) $machine->id)>{{ $machine->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">نظام التقسيط</label>
                                    <select id="systemSelect" name="installment_type" class="form-select form-control" required>
                                        <option value="">اختر النظام...</option>
                                        @foreach ($plans as $systemName => $planOptions)
                                            <option value="{{ $systemName }}" data-plans='@json($planOptions)' @selected(old('installment_type', request('installment_type')) === $systemName)>{{ $systemName }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">عدد الشهور</label>
                                    <select id="monthsSelect" name="months" class="form-select form-control" data-old="{{ old('months', request('months')) }}" disabled required>
                                        <option value="">اختر عدد الشهور...</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3"><label for="application-deposit">المقدم (جنيه)</label><input id="application-deposit" type="number" name="deposit" min="0" step="any" class="form-control" value="{{ old('deposit', request('deposit', 0)) }}"></div>
                            <h3 class="site-apply-title mini-label">بيانات العميل</h3>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">اسم العميل (بالعربية)</label>
                                    <input type="text" name="applicant_name" class="form-control" value="{{ old('applicant_name') }}" required>
                                    <small class="text-muted">يجب كتابة الاسم كما هو في البطاقة تمامًا.</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">رقم الهاتف</label>
                                    <input type="text" name="applicant_phone" class="form-control" value="{{ old('applicant_phone') }}" required>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">العنوان بالتفصيل</label>
                                    <textarea name="applicant_address" class="form-control" rows="2" placeholder="اكتب عنوان السكن بالتفصيل..." required>{{ old('applicant_address') }}</textarea>
                                </div>
                                @foreach (['applicant_id_front' => 'صورة البطاقة - الوجه', 'applicant_id_back' => 'صورة البطاقة - الظهر'] as $field => $label)
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ $label }}</label>
                                        <label class="site-upload-area" for="{{ $field }}">
                                            <span data-upload-text>انقر لرفع {{ $label }}</span>
                                            <small>JPG, PNG - الحد الأقصى 10MB</small>
                                        </label>
                                        <input type="file" name="{{ $field }}" id="{{ $field }}" class="d-none" accept="image/*" required data-preview>
                                        <img src="" alt="" class="site-upload-preview d-none">
                                    </div>
                                @endforeach
                            </div>

                            <h3 class="site-apply-title mini-label">الحالة الوظيفية</h3>
                            <div class="mb-3">
                                <label class="form-label">اختر حالتك الوظيفية</label>
                                <select id="work_status" name="work_status" class="form-select form-control" required>
                                    <option value="">اختر حالتك الوظيفية...</option>
                                    <option value="employee" @selected(old('work_status') === 'employee')>موظف</option>
                                    <option value="pension" @selected(old('work_status') === 'pension')>صاحب معاش</option>
                                    <option value="self_employed" @selected(old('work_status') === 'self_employed')>صاحب نشاط</option>
                                    <option value="no_income_proof" @selected(old('work_status') === 'no_income_proof')>دخل حر</option>
                                </select>
                            </div>

                            <div class="mb-3 d-none" data-work="employee self_employed">
                                <label class="form-label">عنوان العمل</label>
                                <textarea name="work_address" class="form-control" rows="2" placeholder="اكتب عنوان جهة العمل أو مكان النشاط التجاري...">{{ old('work_address') }}</textarea>
                                <small class="text-muted">مثلاً: ٥ شارع الثورة - مدينة نصر - القاهرة</small>
                            </div>

                            <div class="site-apply-block d-none" data-work="employee">
                                <label class="form-label">مفردات المرتب</label>
                                <input type="file" name="salary_slip_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                                <small class="text-muted">يجب ألا يتجاوز تاريخ المستند 3 شهور وأن يكون الراتب ≥ 5000 جنيه.</small>
                            </div>

                            <div class="site-apply-block d-none" data-work="pension">
                                <label class="form-label">بيان المعاش</label>
                                <input type="file" name="pension_statement_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                                <small class="text-muted">يجب أن يكون المعاش ≥ 5000 جنيه.</small>
                            </div>

                            <div class="site-apply-block d-none" data-work="self_employed">
                                <label class="form-label">السجل التجاري</label>
                                <input type="file" name="commercial_reg_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                                <label class="form-label mt-2">البطاقة الضريبية</label>
                                <input type="file" name="tax_card_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                                <label class="form-label mt-2">فيديو المكان</label>
                                <input type="file" name="place_video" class="form-control" accept="video/mp4,video/quicktime">
                                <small class="text-muted d-block">يجب أن تكون المستندات سارية المفعول.</small>
                            </div>

                            <div class="site-apply-block d-none" data-work="no_income_proof">
                                <label class="form-label">اسم العمل (إلزامي)</label>
                                <input type="text" name="free_work_name" class="form-control" placeholder="مثال: حلاق - سباك - كهربائي" value="{{ old('free_work_name') }}">
                                <label class="form-label mt-3">عنوان مكان العمل (اختياري)</label>
                                <textarea name="free_work_address" class="form-control" rows="2" placeholder="عنوان المكان">{{ old('free_work_address') }}</textarea>
                            </div>

                            <button type="submit" class="button button-orange">إرسال الطلب</button>
                        </form>
</section></main>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var machineCatalog = @json(\App\Support\InstallmentCalculatorCatalog::payload()['machines']);
            var machineSelect = document.getElementById('application-machine');
            var systemSelect = document.getElementById('systemSelect');
            var monthsSelect = document.getElementById('monthsSelect');

            function refreshMonths() {
                var selected = systemSelect.options[systemSelect.selectedIndex];
                var plans = selected && selected.dataset.plans ? JSON.parse(selected.dataset.plans) : [];
                monthsSelect.innerHTML = '<option value="">اختر عدد الشهور...</option>';
                plans.forEach(function (p) {
                    var opt = new Option(p.months + ' شهر (' + p.interest + '% فايدة)', p.months);
                    if (String(p.months) === monthsSelect.dataset.old) opt.selected = true;
                    monthsSelect.appendChild(opt);
                });
                monthsSelect.disabled = plans.length === 0;
            }
            function refreshSystems() {
                var previous = systemSelect.value;
                var machine = machineCatalog.find(function (m) { return String(m.id) === machineSelect.value; });
                systemSelect.replaceChildren(new Option('اختر النظام...', ''));
                (machine ? machine.systems : []).forEach(function (system) {
                    var option = new Option(system.name, system.name);
                    option.dataset.plans = JSON.stringify(system.plans);
                    option.selected = system.name === previous;
                    systemSelect.appendChild(option);
                });
                refreshMonths();
            }
            machineSelect.addEventListener('change', refreshSystems);
            systemSelect.addEventListener('change', refreshMonths);
            refreshSystems();

            var ws = document.getElementById('work_status');
            function toggleWork() {
                document.querySelectorAll('[data-work]').forEach(function (block) {
                    block.classList.toggle('d-none', block.dataset.work.split(' ').indexOf(ws.value) === -1);
                });
            }
            ws.addEventListener('change', toggleWork);
            toggleWork();

            document.querySelectorAll('input[data-preview]').forEach(function (input) {
                input.addEventListener('change', function () {
                    var file = input.files[0];
                    var img = input.nextElementSibling;
                    var area = input.previousElementSibling;
                    if (!file) return;
                    area.classList.add('is-done');
                    area.querySelector('[data-upload-text]').textContent = file.name;
                    if (file.type.indexOf('image/') === 0) {
                        img.src = URL.createObjectURL(file);
                        img.classList.remove('d-none');
                    }
                });
            });
        });
    </script>
@endpush
