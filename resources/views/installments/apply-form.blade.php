@extends('layouts.app')
@section('content')
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>نموذج طلب التقسيط</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            :root {
                --primary: #14B0BF;
                --secondary: #14B0BF;
                --accent: #4cc9f0;
                --light: #f8f9fa;
                --dark: #212529;
                --warning: #ffc107;
                --danger: #dc3545;
                --border-radius: 12px;
                --box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
                --transition: all 0.3s ease;
            }

            body {
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                min-height: 100vh;
                color: var(--dark);
            }

            .apply-form-container {
                max-width: 900px;
                margin: 2rem auto;
                background: white;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                overflow: hidden;
            }

            .form-header {
                background: var(--secondary);
                color: white;
                padding: 1.5rem;
                text-align: center;
                position: relative;
            }

            .form-header h4 {
                margin: 0;
                font-weight: 700;
            }

            .form-header::after {
                content: '';
                position: absolute;
                bottom: 0;
                left: 0;
                width: 100%;
                height: 4px;
                background: var(--accent);
            }

            .form-body {
                padding: 2rem;
            }

            .section-title {
                color: var(--secondary);
                border-right: 4px solid var(--accent);
                padding-right: 0.75rem;
                margin: 1.5rem 0 1rem;
                font-weight: 700;
            }

            .form-card {
                background: var(--light);
                border-radius: var(--border-radius);
                padding: 1.25rem;
                margin-bottom: 1.5rem;
                border: 1px solid rgba(0, 0, 0, 0.05);
                transition: var(--transition);
            }

            .form-card:hover {
                box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            }

            .form-label {
                font-weight: 600;
                color: var(--secondary);
                margin-bottom: 0.5rem;
            }

            .form-control,
            .form-select {
                border-radius: 8px;
                padding: 0.75rem;
                border: 1px solid #dee2e6;
                transition: var(--transition);
            }

            .form-control:focus,
            .form-select:focus {
                border-color: var(--accent);
                box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.15);
            }

            .file-upload-area {
                border: 2px dashed #dee2e6;
                border-radius: var(--border-radius);
                padding: 1.5rem;
                text-align: center;
                cursor: pointer;
                transition: var(--transition);
                background: #f8f9fa;
            }

            .file-upload-area:hover {
                border-color: var(--accent);
                background: rgba(76, 201, 240, 0.05);
            }

            .file-upload-area i {
                font-size: 2rem;
                color: var(--primary);
                margin-bottom: 0.5rem;
            }

            .file-upload-area p {
                margin: 0;
                color: var(--dark);
            }

            .file-input {
                display: none;
            }

            .preview-image {
                max-height: 150px;
                border-radius: 8px;
                margin-top: 1rem;
                box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            }

            .btn-submit {
                background: linear-gradient(to right, var(--primary), var(--secondary));
                color: white;
                border: none;
                border-radius: 50px;
                padding: 0.75rem 2rem;
                font-weight: 600;
                font-size: 1.1rem;
                transition: var(--transition);
                display: block;
                width: 100%;
                margin-top: 1.5rem;
            }

            .btn-submit:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
            }

            .alert {
                border-radius: var(--border-radius);
                border: none;
                padding: 1rem 1.25rem;
            }

            .alert-danger {
                background: rgba(220, 53, 69, 0.1);
                color: var(--danger);
            }

            .alert-success {
                background: rgba(75, 181, 67, 0.1);
                color: var(--success);
            }

            .alert-info {
                background: rgba(76, 201, 240, 0.1);
                color: var(--primary);
            }

            .step-indicator {
                display: flex;
                justify-content: space-between;
                margin-bottom: 2rem;
                position: relative;
            }

            .step-indicator::before {
                content: '';
                position: absolute;
                top: 15px;
                left: 0;
                right: 0;
                height: 2px;
                background: #dee2e6;
                z-index: 1;
            }

            .step {
                display: flex;
                flex-direction: column;
                align-items: center;
                position: relative;
                z-index: 2;
            }

            .step-circle {
                width: 30px;
                height: 30px;
                border-radius: 50%;
                background: white;
                border: 2px solid #dee2e6;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 600;
                margin-bottom: 0.5rem;
                transition: var(--transition);
            }

            .step.active .step-circle {
                background: var(--secondary);
                border-color: var(--secondary);
                color: white;
            }

            .step-label {
                font-size: 0.85rem;
                font-weight: 600;
                color: var(--secondary);
            }

            .step.active .step-label {
                color: var(--secondary);
            }

            @media (max-width: 768px) {
                .form-body {
                    padding: 1.5rem;
                }

                .step-label {
                    font-size: 0.75rem;
                }
            }
        </style>
    </head>

    <body>
        <div class="container py-4">
            <div class="apply-form-container">
                <div class="form-header">
                    <h4><i class="fas fa-file-alt me-2"></i>تقديم طلب تقسيط</h4>
                </div>

                <div class="form-body">
                    {{-- ✅ لو الطلب جاي من موظف إحالة --}}
                    @if (session('referred_staff_id'))
                        @php
                            $staff = \App\Models\Staff::find(session('referred_staff_id'));
                        @endphp
                        @if ($staff)
                            <div class="alert alert-info text-center mb-4">
                                <i class="fas fa-user-tie me-1"></i>
                                هذا الطلب سيتم تسجيله باسم الموظف:
                                <strong>{{ $staff->name }}</strong>
                            </div>
                            <input type="hidden" name="staff_id" value="{{ $staff->id }}">
                        @endif
                    @endif

                    <!-- مؤشر التقدم -->
                    <div class="step-indicator">
                        <div class="step active">
                            <div class="step-circle">1</div>
                            <div class="step-label">الماكينة والنظام</div>
                        </div>
                        <div class="step">
                            <div class="step-circle">2</div>
                            <div class="step-label">بيانات العميل</div>
                        </div>
                        <div class="step">
                            <div class="step-circle">4</div>
                            <div class="step-label">الحالة الوظيفية</div>
                        </div>
                    </div>

                    <!-- الأخطاء -->
                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <h6 class="fw-bold mb-2"><i class="fas fa-exclamation-triangle me-2"></i>يوجد بعض الأخطاء:</h6>
                            <ul class="mb-0 ps-3">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <!-- نتائج OCR -->
                    @if (session('ocr_report'))
                        <div class="alert alert-info">
                            <h6 class="fw-bold mb-2"><i class="fas fa-file-alt me-2"></i>نتائج القراءة من المستندات:</h6>
                            {!! session('ocr_report') !!}
                        </div>
                    @endif

                    <!-- رسالة نجاح -->
                    @if (session('success'))
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
                        </div>
                    @endif

                    <!-- الفورم الكاملة -->
                    <form method="POST" action="{{ route('installments.store') }}" enctype="multipart/form-data">
                        @csrf
@php
    $staff = null;
    if (session('referred_staff_id')) {
        $staff = \App\Models\Staff::find(session('referred_staff_id'));
    }
@endphp

@if ($staff)
    <input type="hidden" name="staff_id" value="{{ $staff->id }}">
@endif

                        <!-- قسم الماكينة والنظام -->
                        <h5 class="section-title"><i class="fas fa-motorcycle me-2"></i>المكنه ونظام القسط</h5>
                        <div class="form-card">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">اختر المكنه</label>
                                    <select name="machine_id" class="form-select" required>
                                        <option value="">اختر المكنه...</option>
                                        @foreach ($machines as $machine)
                                            <option value="{{ $machine->id }}">{{ $machine->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">نظام التقسيط</label>
                                    <select id="systemSelect" name="installment_type" class="form-select" required>
                                        <option value="">اختر النظام...</option>
                                        @foreach ($plans as $systemName => $planOptions)
                                            <option value="{{ $systemName }}" data-plans='@json($planOptions)'>
                                                {{ $systemName }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">عدد الشهور</label>
                                    <select id="monthsSelect" name="months" class="form-select" disabled required>
                                        <option value="">اختر عدد الشهور...</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- بيانات العميل -->
                        <h5 class="section-title"><i class="fas fa-user me-2"></i>بيانات العميل</h5>
                        <div class="form-card">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">اسم العميل (بالعربية)</label>
                                    <input type="text" name="applicant_name" class="form-control"
                                        value="{{ old('applicant_name') }}" required>
                                    <small class="text-muted">يجب كتابة الاسم كما هو في البطاقة تمامًا.</small>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">رقم الهاتف</label>
                                    <input type="text" name="applicant_phone" class="form-control"
                                        value="{{ old('applicant_phone') }}" required>
                                </div>

                                {{-- 🏠 العنوان بالتفصيل --}}
                                <div class="col-12 mb-3">
                                    <label class="form-label">العنوان بالتفصيل</label>
                                    <textarea name="applicant_address" class="form-control" rows="2" placeholder="اكتب عنوان السكن بالتفصيل..."
                                        required>{{ old('applicant_address') }}</textarea>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">صورة البطاقة - الوجه</label>
                                    <div class="file-upload-area"
                                        onclick="document.getElementById('applicant_id_front').click()">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>انقر لرفع صورة البطاقة (الوجه)</p>
                                        <small class="text-muted">JPG, PNG - الحد الأقصى 5MB</small>
                                    </div>
                                    <input type="file" name="applicant_id_front" id="applicant_id_front"
                                        class="file-input" accept="image/*" required>
                                    <img id="preview_app_front" src="#" class="preview-image d-none">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">صورة البطاقة - الظهر</label>
                                    <div class="file-upload-area"
                                        onclick="document.getElementById('applicant_id_back').click()">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>انقر لرفع صورة البطاقة (الظهر)</p>
                                        <small class="text-muted">JPG, PNG - الحد الأقصى 5MB</small>
                                    </div>
                                    <input type="file" name="applicant_id_back" id="applicant_id_back"
                                        class="file-input" accept="image/*" required>
                                    <img id="preview_app_back" src="#" class="preview-image d-none">
                                </div>
                            </div>
                        </div>

                        <!-- بيانات الضامن -->
                       

                        <!-- الحالة الوظيفية -->
                        <!-- الحالة الوظيفية -->
                        <h5 class="section-title"><i class="fas fa-briefcase me-2"></i>الحالة الوظيفية</h5>
                        <div class="form-card">
                            <div class="mb-3">
                                <label class="form-label">اختر حالتك الوظيفية</label>
                               <select id="work_status" name="work_status" class="form-select" required>
    <option value="">اختر حالتك الوظيفية...</option>
    <option value="employee" @selected(old('work_status') === 'employee')>موظف</option>
    <option value="pension" @selected(old('work_status') === 'pension')>صاحب معاش</option>
    <option value="self_employed" @selected(old('work_status') === 'self_employed')>صاحب نشاط</option>
    <option value="no_income_proof" @selected(old('work_status') === 'no_income_proof')>دخل حر</option>
</select>

                            </div>

                            <!-- 🏢 عنوان العمل -->
                            <div class="mb-3" id="work_address_block">
                                <label class="form-label">عنوان العمل</label>
                                <textarea name="work_address" class="form-control" rows="2"
                                    placeholder="اكتب عنوان جهة العمل أو مكان النشاط التجاري...">{{ old('work_address') }}</textarea>
                                <small class="text-muted">مثلاً: ٥ شارع الثورة - مدينة نصر - القاهرة</small>
                            </div>

                            <!-- كتل الحالة -->
                            <div id="employee_block" class="d-none border rounded p-3 mb-3">
                                <label class="form-label">مفردات المرتب</label>
                                <input type="file" name="salary_slip_file" class="form-control"
                                    accept=".jpg,.jpeg,.png,.pdf">
                                <small class="text-muted">يجب ألا يتجاوز تاريخ المستند 3 شهور وأن يكون الراتب ≥ 5000
                                    جنيه.</small>
                            </div>


                            <div id="pension_block" class="d-none border rounded p-3 mb-3">
                                <label class="form-label">بيان المعاش</label>
                                <input type="file" name="pension_statement_file" class="form-control"
                                    accept=".jpg,.jpeg,.png,.pdf">
                                <small class="text-muted">يجب أن يكون المعاش ≥ 5000 جنيه.</small>
                            </div>

                            <div id="self_block" class="d-none border rounded p-3 mb-3">
                                <label class="form-label">السجل التجاري</label>
                                <input type="file" name="commercial_reg_file" class="form-control"
                                    accept=".jpg,.jpeg,.png,.pdf">
                                <label class="form-label mt-2">البطاقة الضريبية</label>
                                <input type="file" name="tax_card_file" class="form-control"
                                    accept=".jpg,.jpeg,.png,.pdf">
                                <label class="form-label mt-2">فيديو المكان</label>
                                <input type="file" name="place_video" class="form-control"
                                    accept="video/mp4,video/quicktime">
                                <small class="text-muted d-block">يجب أن تكون المستندات سارية المفعول.</small>
                            </div>
                        </div>
<div id="free_income_block" class="d-none border rounded p-3 mb-3">
    <label class="form-label">اسم العمل (الزامي)</label>
    <input type="text" name="free_work_name" class="form-control" placeholder="مثال: حلاق - سباك - كهربائي" value="{{ old('free_work_name') }}">

    <label class="form-label mt-3">عنوان مكان العمل (اختياري)</label>
    <textarea name="free_work_address" class="form-control" rows="2" placeholder="عنوان المكان">{{ old('free_work_address') }}</textarea>
</div>

                        <button class="btn-submit">
                            <i class="fas fa-paper-plane me-2"></i>إرسال الطلب
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                // ✅ تحديث الشهور بناءً على النظام
                const systemSelect = document.getElementById('systemSelect');
                const monthsSelect = document.getElementById('monthsSelect');
                systemSelect.addEventListener('change', () => {
                    const selected = systemSelect.options[systemSelect.selectedIndex];
                    const plans = selected.dataset.plans ? JSON.parse(selected.dataset.plans) : [];
                    monthsSelect.innerHTML = `<option value="">اختر عدد الشهور...</option>`;
                    if (plans.length) {
                        monthsSelect.disabled = false;
                        plans.forEach(p => {
                            const opt = document.createElement('option');
                            opt.value = p.months;
                            opt.textContent = `${p.months} شهر (${p.interest}% فايدة)`;
                            monthsSelect.appendChild(opt);
                        });
                    } else {
                        monthsSelect.disabled = true;
                    }
                });

                // ✅ أقسام الحالة الوظيفية + عنوان العمل
                const ws = document.getElementById('work_status');
                const emp = document.getElementById('employee_block');
                const pen = document.getElementById('pension_block');
                const self = document.getElementById('self_block');
                const freeIncome = document.getElementById('free_income_block');

                const workAddressBlock = document.getElementById('work_address_block');
function toggleWork() {
    // إخفاء كل البلوكات
    [emp, pen, self, freeIncome].forEach(e => e.classList.add('d-none'));

    // إظهار البلوك المناسب حسب الحالة
    if (ws.value === 'employee') emp.classList.remove('d-none');
    if (ws.value === 'pension') pen.classList.remove('d-none');
    if (ws.value === 'self_employed') self.classList.remove('d-none');
    if (ws.value === 'no_income_proof') freeIncome.classList.remove('d-none');

    // 👇 التحكم في إظهار عنوان العمل الأساسي
    if (ws.value === 'employee' || ws.value === 'self_employed') {
        // إظهار عنوان العمل الأساسي
        workAddressBlock.classList.remove('d-none');
    } else {
        // إخفاؤه في كل الحالات الأخرى
        workAddressBlock.classList.add('d-none');
    }
}


                ws.addEventListener('change', toggleWork);
                toggleWork(); // تفعيل عند تحميل الصفحة أول مرة

                // ✅ معاينة الصور
                function preview(inputId, imgId) {
                    const inp = document.getElementById(inputId);
                    const img = document.getElementById(imgId);
                    if (!inp) return;

                    inp.addEventListener('change', e => {
                        const f = e.target.files[0];
                        if (!f || !f.type.startsWith('image/')) {
                            img.classList.add('d-none');
                            return;
                        }

                        img.src = URL.createObjectURL(f);
                        img.classList.remove('d-none');

                        // تحديث منطقة الرفع
                        const uploadArea = inp.previousElementSibling;
                        if (uploadArea && uploadArea.classList.contains('file-upload-area')) {
                            uploadArea.innerHTML = `
                        <i class="fas fa-check-circle text-success"></i>
                        <p class="text-success">تم رفع الملف بنجاح</p>
                        <small class="text-muted">${f.name}</small>
                    `;
                        }
                    });
                }

                preview('applicant_id_front', 'preview_app_front');
                preview('applicant_id_back', 'preview_app_back');
                preview('guar_front', 'preview_guar_front');
                preview('guar_back', 'preview_guar_back');

                // ✅ تحديث مؤشر التقدم
                function updateStepIndicator() {
                    const sections = document.querySelectorAll('.form-card');
                    const steps = document.querySelectorAll('.step');

                    let currentStep = 0;
                    const scrollPosition = window.scrollY + 100;

                    sections.forEach((section, index) => {
                        const sectionTop = section.offsetTop;
                        if (scrollPosition >= sectionTop) {
                            currentStep = index + 1;
                        }
                    });

                    steps.forEach((step, index) => {
                        if (index <= currentStep) {
                            step.classList.add('active');
                        } else {
                            step.classList.remove('active');
                        }
                    });
                }

                window.addEventListener('scroll', updateStepIndicator);
                updateStepIndicator();
            });
        </script>

    </body>

    </html>
@endsection

