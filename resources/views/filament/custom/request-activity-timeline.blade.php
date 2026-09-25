@php
    $record = $getRecord();

    $ignoreUntil = $record?->created_at?->copy()->addSeconds(10);

    $activities = $record
        ? \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', get_class($record))
            ->where('subject_id', $record->id)
            ->where('event', '!=', 'created')
            ->when(
                $ignoreUntil,
                fn ($q) => $q->where('created_at', '>', $ignoreUntil)
            )
            ->where(function ($q) {
                $q->whereNotNull('properties->attributes')
                  ->orWhereNotNull('properties->old');
            })
            ->with('causer')
            ->latest()
            ->limit(30)
            ->get()
        : collect();


    /*
    |--------------------------------------------------------------------------
    | الحقول
    |--------------------------------------------------------------------------
    */

    $labels = [
        'status'                    => 'حالة الطلب',
        'checks_report'             => 'السبب',
        'staff_id'                  => 'الموظف',
        'installment_type'          => 'نوع النظام',
        'machine_id'                => 'المكنة',
        'machine_installment_price' => 'سعر المكنة',
        'deposit'                   => 'المقدم',
        'applicant_name'            => 'اسم العميل',
        'applicant_phone'           => 'رقم الهاتف',
        'notes'                     => 'الملاحظات',
    ];


    /*
    |--------------------------------------------------------------------------
    | الحالات
    |--------------------------------------------------------------------------
    */

    $statusLabels = [
        'new'         => 'انتظار',
        'new_request' => 'طلب جديد',
        'pending'     => 'تحت الاستعلام',
        'work_check'  => 'استعلام عمل',
        'approved'    => 'موافقة',
        'rejected'    => 'رفض',
        'paused'      => 'متوقف',
        'transferred' => 'محول',
        'delivered'   => 'استلم المكنة',
        'canceled'    => 'الطلب ملغي',
    ];


    /*
    |--------------------------------------------------------------------------
    | استخراج التعديلات الحقيقية فقط
    |--------------------------------------------------------------------------
    */

    $visibleActivities = collect();

    foreach ($activities as $activity) {

        $attributes = $activity->properties['attributes'] ?? [];
        $old = $activity->properties['old'] ?? [];

        $changes = collect($attributes)
            ->only(array_keys($labels))
            ->filter(function ($newValue, $field) use ($old) {
                return ($old[$field] ?? null) != $newValue;
            });

        if ($changes->isNotEmpty()) {
            $visibleActivities->push([
                'activity' => $activity,
                'changes'  => $changes,
                'old'      => $old,
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Format values
    |--------------------------------------------------------------------------
    */

    $formatValue = function ($field, $value) use ($statusLabels) {

        if ($value === null || $value === '') {
            return '—';
        }

        if ($field === 'status') {
            return $statusLabels[$value] ?? $value;
        }

        if ($field === 'staff_id') {
            return \App\Models\Staff::find($value)?->name ?? $value;
        }

        if ($field === 'machine_id') {
            return \App\Models\Machine::find($value)?->name ?? $value;
        }

        if (
            $field === 'deposit' ||
            $field === 'machine_installment_price'
        ) {
            if (is_numeric($value)) {
                return number_format((float) $value) . ' ج.م';
            }
        }

        if (is_array($value)) {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return $value;
    };
@endphp


<style>
    /* =========================================================
       MOTOGATE ACTIVITY LOG
    ========================================================= */

    .mg-history {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        border: 1px solid rgba(96, 165, 250, .18);
        background:
            radial-gradient(
                circle at 82% 8%,
                rgba(59, 130, 246, .10),
                transparent 28%
            ),
            radial-gradient(
                circle at 10% 92%,
                rgba(37, 99, 235, .08),
                transparent 25%
            ),
            linear-gradient(
                145deg,
                #0d1422 0%,
                #0b111c 48%,
                #080d16 100%
            );
        box-shadow:
            0 25px 70px rgba(0,0,0,.20),
            inset 0 1px 0 rgba(255,255,255,.025);
    }

    .mg-history::before {
        content: "";
        position: absolute;
        inset: 0;
        pointer-events: none;
        background:
            linear-gradient(
                90deg,
                transparent 0%,
                rgba(255,255,255,.018) 50%,
                transparent 100%
            );
    }

    .mg-history-header {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        padding: 24px 28px;
        border-bottom: 1px solid rgba(148,163,184,.10);
        background: rgba(255,255,255,.018);
        backdrop-filter: blur(12px);
    }

    .mg-history-title {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .mg-history-icon {
        width: 54px;
        height: 54px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 17px;
        color: #dbeafe;
        background:
            linear-gradient(
                145deg,
                rgba(59,130,246,.95),
                rgba(37,99,235,.65)
            );
        box-shadow:
            0 0 0 1px rgba(96,165,250,.28),
            0 10px 30px rgba(37,99,235,.20);
    }

    .mg-history-icon svg {
        width: 27px;
        height: 27px;
    }

    .mg-history-heading {
        color: #f8fafc;
        font-size: 19px;
        font-weight: 900;
        line-height: 1.4;
        letter-spacing: -.3px;
    }

    .mg-history-subtitle {
        margin-top: 3px;
        color: #94a3b8;
        font-size: 12px;
    }

    .mg-live-pill {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 9px 14px;
        border: 1px solid rgba(96,165,250,.15);
        border-radius: 999px;
        background: rgba(30,41,59,.55);
        color: #cbd5e1;
        font-size: 11px;
        font-weight: 800;
    }

    .mg-live-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #22c55e;
        box-shadow: 0 0 12px rgba(34,197,94,.75);
    }


    /* =========================================================
       CONTENT
    ========================================================= */

    .mg-history-content {
        position: relative;
        padding: 38px 34px 42px;
    }


    /* =========================================================
       EMPTY STATE
    ========================================================= */

    .mg-empty {
        position: relative;
        min-height: 410px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        text-align: center;
        overflow: hidden;
    }

    .mg-empty::before {
        content: "";
        position: absolute;
        width: 360px;
        height: 220px;
        border-radius: 50%;
        background: rgba(37,99,235,.08);
        filter: blur(65px);
        top: 55px;
        left: 50%;
        transform: translateX(-50%);
    }

    .mg-empty-art {
        position: relative;
        width: 150px;
        height: 130px;
        margin-bottom: 20px;
    }

    .mg-document {
        position: absolute;
        width: 86px;
        height: 104px;
        left: 28px;
        top: 5px;
        transform: rotate(-7deg);
        border-radius: 17px;
        border: 1px solid rgba(147,197,253,.30);
        background:
            linear-gradient(
                145deg,
                rgba(96,165,250,.38),
                rgba(30,64,175,.18)
            );
        box-shadow:
            0 25px 45px rgba(0,0,0,.25),
            inset 0 1px 0 rgba(255,255,255,.10);
        backdrop-filter: blur(10px);
    }

    .mg-document::before,
    .mg-document::after {
        content: "";
        position: absolute;
        left: 17px;
        height: 7px;
        border-radius: 10px;
        background: rgba(191,219,254,.55);
    }

    .mg-document::before {
        width: 54px;
        top: 28px;
    }

    .mg-document::after {
        width: 43px;
        top: 45px;
    }

    .mg-document-line {
        position: absolute;
        left: 17px;
        top: 62px;
        width: 29px;
        height: 7px;
        border-radius: 10px;
        background: rgba(191,219,254,.40);
    }

    .mg-clock {
        position: absolute;
        right: 17px;
        bottom: 0;
        width: 55px;
        height: 55px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 5px solid #0d1422;
        background: linear-gradient(145deg,#dbeafe,#93c5fd);
        color: #0f172a;
        box-shadow:
            0 12px 30px rgba(0,0,0,.35),
            0 0 25px rgba(96,165,250,.20);
    }

    .mg-clock svg {
        width: 28px;
        height: 28px;
    }

    .mg-empty-title {
        position: relative;
        color: #f8fafc;
        font-size: 24px;
        font-weight: 900;
        letter-spacing: -.5px;
    }

    .mg-empty-text {
        position: relative;
        max-width: 480px;
        margin-top: 8px;
        color: #94a3b8;
        font-size: 13px;
        line-height: 2;
    }

    .mg-tip {
        position: relative;
        display: flex;
        align-items: center;
        gap: 13px;
        max-width: 530px;
        margin-top: 26px;
        padding: 14px 18px;
        border-radius: 16px;
        border: 1px solid rgba(96,165,250,.20);
        background:
            linear-gradient(
                135deg,
                rgba(30,58,138,.22),
                rgba(15,23,42,.55)
            );
        color: #94a3b8;
        text-align: right;
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.025);
    }

    .mg-tip-icon {
        width: 36px;
        height: 36px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 11px;
        color: #60a5fa;
        background: rgba(37,99,235,.12);
    }

    .mg-tip-title {
        color: #dbeafe;
        font-size: 12px;
        font-weight: 900;
        margin-bottom: 2px;
    }

    .mg-tip-text {
        font-size: 11px;
        line-height: 1.8;
    }


    /* =========================================================
       TIMELINE
    ========================================================= */

    .mg-timeline {
        position: relative;
        max-width: 1050px;
        margin: auto;
    }

    .mg-timeline-line {
        position: absolute;
        top: 30px;
        bottom: 30px;
        right: 25px;
        width: 1px;
        background:
            linear-gradient(
                to bottom,
                rgba(96,165,250,.55),
                rgba(96,165,250,.12),
                transparent
            );
    }

    .mg-event {
        position: relative;
        display: flex;
        gap: 20px;
        margin-bottom: 32px;
    }

    .mg-event:last-child {
        margin-bottom: 0;
    }

    .mg-event-dot {
        position: relative;
        z-index: 2;
        width: 52px;
        height: 52px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 17px;
        border: 1px solid rgba(96,165,250,.25);
        background:
            linear-gradient(
                145deg,
                rgba(30,58,138,.90),
                rgba(15,23,42,.95)
            );
        color: #93c5fd;
        box-shadow:
            0 0 0 7px rgba(11,17,28,.9),
            0 8px 25px rgba(0,0,0,.25);
    }

    .mg-event-dot svg {
        width: 20px;
        height: 20px;
    }

    .mg-event-body {
        flex: 1;
        min-width: 0;
    }

    .mg-event-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        margin-bottom: 12px;
    }

    .mg-user {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .mg-avatar {
        width: 34px;
        height: 34px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 11px;
        color: #bfdbfe;
        background:
            linear-gradient(
                145deg,
                rgba(37,99,235,.35),
                rgba(30,41,59,.8)
            );
        border: 1px solid rgba(96,165,250,.18);
        font-size: 12px;
        font-weight: 900;
    }

    .mg-user-name {
        color: #f1f5f9;
        font-size: 13px;
        font-weight: 900;
    }

    .mg-user-action {
        margin-top: 2px;
        color: #64748b;
        font-size: 10px;
    }

    .mg-time {
        padding: 7px 11px;
        border-radius: 999px;
        border: 1px solid rgba(148,163,184,.10);
        background: rgba(15,23,42,.55);
        color: #64748b;
        font-size: 10px;
        font-weight: 800;
        white-space: nowrap;
    }


    /* =========================================================
       CHANGE CARD
    ========================================================= */

    .mg-change-card {
        overflow: hidden;
        border-radius: 19px;
        border: 1px solid rgba(148,163,184,.11);
        background:
            linear-gradient(
                145deg,
                rgba(15,23,42,.90),
                rgba(10,15,25,.90)
            );
        box-shadow:
            0 15px 40px rgba(0,0,0,.16),
            inset 0 1px 0 rgba(255,255,255,.018);
    }

    .mg-change-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 13px 16px;
        border-bottom: 1px solid rgba(148,163,184,.08);
        background: rgba(255,255,255,.015);
    }

    .mg-change-label {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #cbd5e1;
        font-size: 11px;
        font-weight: 900;
    }

    .mg-change-label svg {
        width: 15px;
        height: 15px;
        color: #60a5fa;
    }

    .mg-change-count {
        padding: 5px 9px;
        border-radius: 999px;
        background: rgba(37,99,235,.10);
        color: #60a5fa;
        font-size: 9px;
        font-weight: 900;
    }

    .mg-field {
        padding: 16px;
        border-bottom: 1px solid rgba(148,163,184,.07);
    }

    .mg-field:last-child {
        border-bottom: 0;
    }

    .mg-field-name {
        margin-bottom: 10px;
        color: #e2e8f0;
        font-size: 11px;
        font-weight: 900;
    }

    .mg-values {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: center;
        gap: 9px;
    }

    .mg-value {
        min-width: 0;
        padding: 11px 13px;
        border-radius: 13px;
    }

    .mg-value-before {
        border: 1px solid rgba(248,113,113,.10);
        background: rgba(127,29,29,.09);
    }

    .mg-value-after {
        border: 1px solid rgba(52,211,153,.12);
        background: rgba(6,78,59,.10);
    }

    .mg-value-label {
        margin-bottom: 4px;
        font-size: 9px;
        font-weight: 800;
    }

    .mg-value-before .mg-value-label {
        color: #f87171;
    }

    .mg-value-after .mg-value-label {
        color: #34d399;
    }

    .mg-value-text {
        overflow-wrap: anywhere;
        color: #cbd5e1;
        font-size: 12px;
        font-weight: 800;
    }

    .mg-value-after .mg-value-text {
        color: #a7f3d0;
    }

    .mg-arrow {
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        border: 1px solid rgba(148,163,184,.12);
        background: rgba(15,23,42,.8);
        color: #64748b;
    }

    .mg-arrow svg {
        width: 14px;
        height: 14px;
    }


    /* =========================================================
       FOOTER
    ========================================================= */

    .mg-history-footer {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 8px;
        padding: 0 34px 24px;
        color: #475569;
        font-size: 10px;
        font-weight: 700;
    }

    .mg-history-footer-line {
        width: 30px;
        height: 1px;
        background: #334155;
    }


    /* =========================================================
       MOBILE
    ========================================================= */

    @media (max-width: 640px) {

        .mg-history {
            border-radius: 20px;
        }

        .mg-history-header {
            padding: 19px;
        }

        .mg-history-icon {
            width: 46px;
            height: 46px;
            border-radius: 14px;
        }

        .mg-history-heading {
            font-size: 16px;
        }

        .mg-history-subtitle {
            font-size: 10px;
        }

        .mg-live-pill {
            display: none;
        }

        .mg-history-content {
            padding: 28px 17px 32px;
        }

        .mg-timeline-line {
            right: 20px;
        }

        .mg-event {
            gap: 14px;
        }

        .mg-event-dot {
            width: 42px;
            height: 42px;
            border-radius: 14px;
        }

        .mg-event-top {
            align-items: flex-start;
            flex-direction: column;
            gap: 8px;
        }

        .mg-values {
            grid-template-columns: 1fr;
        }

        .mg-arrow {
            display: none;
        }

        .mg-value {
            width: 100%;
        }

        .mg-empty {
            min-height: 350px;
        }

        .mg-empty-title {
            font-size: 20px;
        }

        .mg-tip {
            margin-left: 5px;
            margin-right: 5px;
        }

        .mg-history-footer {
            padding: 0 20px 20px;
        }
    }
</style>


<div class="mg-history" dir="rtl">

    {{-- =========================================================
         HEADER
    ========================================================== --}}

    <div class="mg-history-header">

        <div class="mg-history-title">

            <div class="mg-history-icon">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                >
                    <circle cx="12" cy="12" r="9"/>
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M12 7v5l3 2"
                    />
                    <path
                        stroke-linecap="round"
                        d="M4.5 5.5 3 4"
                    />
                </svg>

            </div>

            <div>

                <div class="mg-history-heading">
                    سجل تعديلات الطلب
                </div>

                <div class="mg-history-subtitle">
                    كل التغييرات التي تمت على الطلب بعد إنشائه
                </div>

            </div>

        </div>


        @if($visibleActivities->isNotEmpty())

            <div class="mg-live-pill">

                <span class="mg-live-dot"></span>

                {{ $visibleActivities->count() }} عملية تعديل

            </div>

        @endif

    </div>


    {{-- =========================================================
         CONTENT
    ========================================================== --}}

    <div class="mg-history-content">

        @if($visibleActivities->isEmpty())

            {{-- =================================================
                 EMPTY STATE
            ================================================== --}}

            <div class="mg-empty">

                <div class="mg-empty-art">

                    <div class="mg-document">
                        <div class="mg-document-line"></div>
                    </div>

                    <div class="mg-clock">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                        >
                            <circle cx="12" cy="12" r="8.5"/>
                            <path
                                stroke-linecap="round"
                                d="M12 7v5l3 2"
                            />
                        </svg>

                    </div>

                </div>


                <div class="mg-empty-title">
                    مفيش تعديلات لسه
                </div>

                <div class="mg-empty-text">
                    أي تعديل يحصل على الطلب بعد إنشائه
                    هيظهر هنا بالتفصيل وبالترتيب الزمني.
                </div>


                <div class="mg-tip">

                    <div class="mg-tip-icon">

                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M9 18h6"
                            />

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M10 22h4"
                            />

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M8.5 15.5C7.55 14.7 7 13.45 7 12a5 5 0 1 1 10 0c0 1.45-.55 2.7-1.5 3.5-.8.68-1.2 1.1-1.35 2.5h-4.3c-.15-1.4-.55-1.82-1.35-2.5Z"
                            />

                        </svg>

                    </div>


                    <div>

                        <div class="mg-tip-title">
                            معلومة بسيطة
                        </div>

                        <div class="mg-tip-text">
                            هنا هتلاقي سجل كامل لكل التعديلات
                            اللي تمت على الطلب بالترتيب الزمني.
                        </div>

                    </div>

                </div>

            </div>

        @else

            {{-- =================================================
                 TIMELINE
            ================================================== --}}

            <div class="mg-timeline">

                <div class="mg-timeline-line"></div>


                @foreach($visibleActivities as $item)

                    @php
                        $activity = $item['activity'];
                        $changes = $item['changes'];
                        $old = $item['old'];

                        $causerName = $activity->causer?->name ?? 'غير معروف';

                        $initial = mb_substr(
                            $causerName,
                            0,
                            1,
                            'UTF-8'
                        );
                    @endphp


                    <div class="mg-event">

                        {{-- Timeline Icon --}}
                        <div class="mg-event-dot">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M12 20h9"
                                />

                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5Z"
                                />
                            </svg>

                        </div>


                        <div class="mg-event-body">

                            {{-- User / Date --}}
                            <div class="mg-event-top">

                                <div class="mg-user">

                                    <div class="mg-avatar">
                                        {{ $initial }}
                                    </div>

                                    <div>

                                        <div class="mg-user-name">
                                            {{ $causerName }}
                                        </div>

                                        <div class="mg-user-action">
                                            قام بتعديل بيانات الطلب
                                        </div>

                                    </div>

                                </div>


                                <div class="mg-time">

                                    {{ $activity->created_at->format('d/m/Y') }}

                                    <span style="opacity:.4;">
                                        •
                                    </span>

                                    {{ $activity->created_at->format('h:i A') }}

                                </div>

                            </div>


                            {{-- Changes --}}
                            <div class="mg-change-card">

                                <div class="mg-change-header">

                                    <div class="mg-change-label">

                                        <svg
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M12 5v14M5 12h14"
                                            />
                                        </svg>

                                        تفاصيل التعديل

                                    </div>


                                    <div class="mg-change-count">
                                        {{ $changes->count() }} تغيير
                                    </div>

                                </div>


                                @foreach($changes as $field => $newValue)

                                    @php
                                        $oldValue = $old[$field] ?? null;

                                        $oldFormatted = $formatValue(
                                            $field,
                                            $oldValue
                                        );

                                        $newFormatted = $formatValue(
                                            $field,
                                            $newValue
                                        );
                                    @endphp


                                    <div class="mg-field">

                                        <div class="mg-field-name">
                                            {{ $labels[$field] ?? $field }}
                                        </div>


                                        <div class="mg-values">

                                            {{-- BEFORE --}}
                                            <div class="mg-value mg-value-before">

                                                <div class="mg-value-label">
                                                    قبل التعديل
                                                </div>

                                                <div class="mg-value-text">
                                                    {{ $oldFormatted }}
                                                </div>

                                            </div>


                                            {{-- Arrow --}}
                                            <div class="mg-arrow">

                                                <svg
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="1.8"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="M5 12h14"
                                                    />

                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="m13 6 6 6-6 6"
                                                    />
                                                </svg>

                                            </div>


                                            {{-- AFTER --}}
                                            <div class="mg-value mg-value-after">

                                                <div class="mg-value-label">
                                                    بعد التعديل
                                                </div>

                                                <div class="mg-value-text">
                                                    {{ $newFormatted }}
                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                @endforeach

                            </div>

                        </div>

                    </div>

                @endforeach

            </div>

        @endif

    </div>


    {{-- =========================================================
         FOOTER
    ========================================================== --}}

    <div class="mg-history-footer">

        <span class="mg-history-footer-line"></span>

        <span>
            MotoGate · إدارة الطلبات
        </span>

    </div>

</div>
