<x-filament-widgets::widget>

    <style>

        .staff-board {
            direction: rtl;
        }

        .staff-board-head {
            margin-bottom: 22px;
        }

        .staff-board-head h2 {
            font-size: 24px;
            font-weight: 900;
            margin: 0;
            color: #0f172a;
        }

        .staff-board-head p {
            margin-top: 8px;
            color: #64748b;
            font-size: 14px;
        }

        .staff-rank-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 22px;
        }

        .staff-rank-card {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            padding: 24px;
            background: #fff;
            border: 1px solid rgba(148,163,184,.25);
            box-shadow: 0 18px 45px rgba(15,23,42,.08);
        }

        .staff-rank-card::before {
            content: "";
            position: absolute;
            inset: 0;
            opacity: .13;
            pointer-events: none;
        }

        .staff-rank-card.gold::before {
            background: linear-gradient(
                135deg,
                #f59e0b,
                #fde68a,
                #f97316
            );
        }

        .staff-rank-card.silver::before {
            background: linear-gradient(
                135deg,
                #94a3b8,
                #f8fafc,
                #64748b
            );
        }

        .staff-rank-card.bronze::before {
            background: linear-gradient(
                135deg,
                #92400e,
                #fdba74,
                #b45309
            );
        }

        .staff-card-top {
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
        }

        .staff-rank-badge {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15,23,42,.06);
            font-size: 22px;
            font-weight: 900;
            margin-bottom: 12px;
        }

        .staff-rank-card h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 900;
            color: #334155;
        }

        .staff-rank-card p {
            margin: 7px 0 0;
            color: #64748b;
            font-size: 15px;
            font-weight: 800;
        }

        .staff-total {
            text-align: center;
            min-width: 90px;
            padding: 13px;
            border-radius: 22px;
            background: rgba(15,23,42,.045);
        }

        .staff-total strong {
            display: block;
            font-size: 42px;
            line-height: 1;
            font-weight: 950;
            color: #020617;
        }

        .staff-total span {
            display: block;
            margin-top: 7px;
            color: #64748b;
            font-weight: 900;
        }

        .staff-stats {
            position: relative;
            margin-top: 22px;
            display: grid;
            grid-template-columns: repeat(2,minmax(0,1fr));
            gap: 10px;
        }

        .staff-stat {
            border-radius: 18px;
            padding: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .staff-stat span {
            font-size: 13px;
            font-weight: 900;
        }

        .staff-stat strong {
            font-size: 20px;
            font-weight: 950;
        }

        .staff-stat.approved {
            color:#16a34a;
            background:rgba(34,197,94,.11)
        }

        .staff-stat.paused {
            color:#64748b;
            background:rgba(100,116,139,.12)
        }

        .staff-stat.new {
            color:#2563eb;
            background:rgba(37,99,235,.11)
        }

        .staff-stat.pending {
            color:#ea580c;
            background:rgba(249,115,22,.13)
        }

        .staff-stat.delivered {
            color:#0891b2;
            background:rgba(6,182,212,.13)
        }

        .staff-stat.rejected {
            color:#dc2626;
            background:rgba(239,68,68,.13)
        }

        .staff-rank-card.gold {
            border-color:rgba(245,158,11,.45)
        }

        .staff-rank-card.silver {
            border-color:rgba(148,163,184,.45)
        }

        .staff-rank-card.bronze {
            border-color:rgba(180,83,9,.38)
        }


        /*
        |--------------------------------------------------------------------------
        | Eye Button
        |--------------------------------------------------------------------------
        */

        .staff-eye-btn {
            position: absolute;
            top: 0;
            left: 0;

            width: 44px;
            height: 44px;

            border-radius: 14px;

            display: flex;
            align-items: center;
            justify-content: center;

            cursor: pointer;

            color: #64748b;

            background: rgba(255,255,255,.75);

            border: 1px solid rgba(148,163,184,.25);

            box-shadow:
                0 8px 20px rgba(15,23,42,.08);

            transition:
                .2s ease;
        }

        .staff-eye-btn:hover {
            transform: translateY(-2px) scale(1.04);

            color: #2563eb;

            background: #fff;

            box-shadow:
                0 12px 25px rgba(37,99,235,.18);
        }

        .staff-eye-btn svg {
            width: 21px;
            height: 21px;
        }


        /*
        |--------------------------------------------------------------------------
        | Login Modal
        |--------------------------------------------------------------------------
        */

        .login-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 99999;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 20px;

            background: rgba(2,6,23,.65);

            backdrop-filter: blur(8px);
        }

        .login-modal {
            width: min(850px, 100%);

            max-height: 90vh;

            overflow: hidden;

            border-radius: 28px;

            background: #fff;

            box-shadow:
                0 30px 100px rgba(0,0,0,.35);
        }

        .login-modal-header {
            padding: 24px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            border-bottom: 1px solid #e2e8f0;
        }

        .login-modal-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .login-modal-icon {
            width: 50px;
            height: 50px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 16px;

            background: rgba(37,99,235,.10);

            color: #2563eb;
        }

        .login-modal-title h3 {
            margin: 0;

            font-size: 20px;

            font-weight: 950;

            color: #0f172a;
        }

        .login-modal-title p {
            margin: 4px 0 0;

            color: #64748b;

            font-size: 13px;
        }

        .login-modal-close {
            width: 40px;
            height: 40px;

            border-radius: 12px;

            border: none;

            cursor: pointer;

            background: #f1f5f9;

            color: #64748b;

            font-size: 20px;
        }

        .login-modal-body {
            padding: 24px;

            max-height: calc(90vh - 100px);

            overflow-y: auto;
        }


        /*
        |--------------------------------------------------------------------------
        | Login Summary
        |--------------------------------------------------------------------------
        */

        .login-summary {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 12px;

            margin-bottom: 24px;
        }

        .login-summary-card {
            padding: 16px;

            border-radius: 18px;

            background: #f8fafc;

            border: 1px solid #e2e8f0;
        }

        .login-summary-card span {
            display: block;

            color: #64748b;

            font-size: 12px;

            font-weight: 800;

            margin-bottom: 7px;
        }

        .login-summary-card strong {
            display: block;

            color: #0f172a;

            font-size: 22px;

            font-weight: 950;
        }


        /*
        |--------------------------------------------------------------------------
        | Login List
        |--------------------------------------------------------------------------
        */

        .login-section-title {
            margin-bottom: 12px;

            font-size: 15px;

            font-weight: 950;

            color: #0f172a;
        }

        .login-list {
            display: flex;

            flex-direction: column;

            gap: 10px;
        }

        .login-row {
            display: grid;

            grid-template-columns:
                90px
                1fr
                1fr
                1fr;

            gap: 14px;

            align-items: center;

            padding: 14px 16px;

            border-radius: 18px;

            background: #f8fafc;

            border: 1px solid #e2e8f0;
        }

        .login-time {
            font-weight: 950;

            color: #2563eb;
        }

        .login-info small {
            display: block;

            color: #94a3b8;

            font-size: 11px;

            margin-bottom: 3px;
        }

        .login-info strong {
            display: block;

            color: #334155;

            font-size: 13px;
        }

        .same-device {
            display: inline-flex;

            align-items: center;

            gap: 5px;

            margin-top: 5px;

            padding: 4px 8px;

            border-radius: 8px;

            background: rgba(34,197,94,.10);

            color: #16a34a;

            font-size: 10px;

            font-weight: 900;
        }

        .different-device {
            display: inline-flex;

            align-items: center;

            gap: 5px;

            margin-top: 5px;

            padding: 4px 8px;

            border-radius: 8px;

            background: rgba(239,68,68,.10);

            color: #dc2626;

            font-size: 10px;

            font-weight: 900;
        }

        .login-empty {
            text-align: center;

            padding: 40px 20px;

            color: #94a3b8;
        }


        /*
        |--------------------------------------------------------------------------
        | Dark Mode
        |--------------------------------------------------------------------------
        */

        .dark .staff-board-head h2 {
            color:#f8fafc
        }

        .dark .staff-board-head p {
            color:#94a3b8
        }

        .dark .staff-rank-card {
            background:rgba(17,24,39,.96);
            border-color:rgba(255,255,255,.10)
        }

        .dark .staff-rank-card h3 {
            color:#f8fafc
        }

        .dark .staff-rank-card p {
            color:#cbd5e1
        }

        .dark .staff-rank-badge,
        .dark .staff-total {
            background:rgba(255,255,255,.07)
        }

        .dark .staff-total strong {
            color:#fff
        }

        .dark .staff-total span {
            color:#cbd5e1
        }

        .dark .staff-eye-btn {
            background:rgba(255,255,255,.08);
            border-color:rgba(255,255,255,.10);
            color:#cbd5e1
        }

        .dark .login-modal {
            background:#111827;
        }

        .dark .login-modal-header {
            border-color:rgba(255,255,255,.08);
        }

        .dark .login-modal-title h3 {
            color:#f8fafc;
        }

        .dark .login-modal-title p {
            color:#94a3b8;
        }

        .dark .login-modal-close {
            background:rgba(255,255,255,.08);
            color:#cbd5e1;
        }

        .dark .login-summary-card,
        .dark .login-row {
            background:rgba(255,255,255,.05);
            border-color:rgba(255,255,255,.08);
        }

        .dark .login-summary-card span,
        .dark .login-info small {
            color:#94a3b8;
        }

        .dark .login-summary-card strong,
        .dark .login-info strong,
        .dark .login-section-title {
            color:#f8fafc;
        }


        @media(max-width:1024px) {

            .staff-rank-grid {
                grid-template-columns:
                    repeat(2,minmax(0,1fr));
            }

            .login-summary {
                grid-template-columns:
                    repeat(2,minmax(0,1fr));
            }
        }

        @media(max-width:640px) {

            .staff-rank-grid {
                grid-template-columns:1fr;
            }

            .login-summary {
                grid-template-columns:1fr 1fr;
            }

            .login-row {
                grid-template-columns:1fr 1fr;
            }
        }

    </style>


    <x-filament::section>

        <div
            class="staff-board"
            x-data="{
                open: false,
                selected: null,

                showLogin(staff) {
                    this.selected = staff;
                    this.open = true;

                    document.body.style.overflow = 'hidden';
                },

                closeLogin() {
                    this.open = false;
                    this.selected = null;

                    document.body.style.overflow = '';
                }
            }"
        >

            <div class="staff-board-head">

                <h2>
                    ترتيب الموظفين
                </h2>

                <p>
                    حسب إجمالي طلبات الشهر الحالي، ولو متساويين بنحسب عدد الموافقات
                </p>

            </div>


            <div class="staff-rank-grid">

                @foreach ($staffList as $staff)

                    @php

                        $rank = $staff['rank'];

                        $rankClass = match ($rank) {
                            1 => 'gold',
                            2 => 'silver',
                            3 => 'bronze',
                            default => 'normal',
                        };

                        $badge = match ($rank) {
                            1 => '👑',
                            2 => '♛',
                            3 => '♕',
                            default => '🏅',
                        };

                        $title = match ($rank) {
                            1 => 'المركز الأول',
                            2 => 'المركز الثاني',
                            3 => 'المركز الثالث',
                            default => 'المركز '.$rank,
                        };

                    @endphp


                    <div class="staff-rank-card {{ $rankClass }}">

                        @if ($isHitler)

                            <button
                                type="button"
                                class="staff-eye-btn"
                                title="تفاصيل دخول الموظف"
                                @click='showLogin(@json($staff))'
                            >

                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="2"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M2.036 12.322a1.012 1.012 0 010-.644C3.423 7.51 7.36 4.5 12 4.5c4.64 0 8.577 3.01 9.964 7.178.06.18.06.376 0 .556C20.577 16.49 16.64 19.5 12 19.5c-4.64 0-8.577-3.01-9.964-7.178z"
                                    />

                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                    />
                                </svg>

                            </button>

                        @endif


                        <div class="staff-card-top">

                            <div>

                                <div class="staff-rank-badge">
                                    {{ $badge }}
                                </div>

                                <h3>
                                    {{ $title }}
                                </h3>

                                <p>
                                    {{ $staff['name'] }}
                                </p>

                            </div>


                            <div class="staff-total">

                                <strong>
                                    {{ $staff['total'] }}
                                </strong>

                                <span>
                                    طلب
                                </span>

                            </div>

                        </div>


                        <div class="staff-stats">

                            <div class="staff-stat approved">
                                <span>موافقة</span>
                                <strong>{{ $staff['approved'] }}</strong>
                            </div>

                            <div class="staff-stat paused">
                                <span>متوقف</span>
                                <strong>{{ $staff['paused'] }}</strong>
                            </div>

                            <div class="staff-stat new">
                                <span>جديد</span>
                                <strong>{{ $staff['new'] }}</strong>
                            </div>

                            <div class="staff-stat pending">
                                <span>استعلام</span>
                                <strong>{{ $staff['pending'] }}</strong>
                            </div>

                            <div class="staff-stat delivered">
                                <span>استلم المكنة</span>
                                <strong>{{ $staff['delivered'] }}</strong>
                            </div>

                            <div class="staff-stat rejected">
                                <span>مرفوض</span>
                                <strong>{{ $staff['rejected'] }}</strong>
                            </div>

                        </div>

                    </div>

                @endforeach

            </div>


            {{-- ========================================================= --}}
            {{-- LOGIN MODAL --}}
            {{-- ========================================================= --}}

            @if ($isHitler)

                <template x-teleport="body">

                    <div
                        x-show="open"
                        x-cloak
                        class="login-modal-overlay"
                        @click.self="closeLogin()"
                        @keydown.escape.window="closeLogin()"
                    >

                        <div
                            class="login-modal"
                            x-show="open"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                        >

                            <div class="login-modal-header">

                                <div class="login-modal-title">

                                    <div class="login-modal-icon">

                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke-width="2"
                                            stroke="currentColor"
                                            style="width:25px;height:25px"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                            />

                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                                            />
                                        </svg>

                                    </div>


                                    <div>

                                        <h3>
                                            تفاصيل دخول الموظف
                                        </h3>

                                        <p x-text="selected?.name"></p>

                                    </div>

                                </div>


                                <button
                                    type="button"
                                    class="login-modal-close"
                                    @click="closeLogin()"
                                >
                                    ×
                                </button>

                            </div>


                            <div class="login-modal-body">

                                {{-- Summary --}}

                                <div class="login-summary">

                                    <div class="login-summary-card">

                                        <span>
                                            دخول اليوم
                                        </span>

                                        <strong
                                            x-text="selected?.login?.today_count ?? 0"
                                        ></strong>

                                    </div>


                                    <div class="login-summary-card">

                                        <span>
                                            أجهزة مختلفة
                                        </span>

                                        <strong
                                            x-text="selected?.login?.devices_count ?? 0"
                                        ></strong>

                                    </div>


                                    <div class="login-summary-card">

                                        <span>
                                            آخر IP
                                        </span>

                                        <strong
                                            style="font-size:15px"
                                            x-text="selected?.login?.last_ip ?? '—'"
                                        ></strong>

                                    </div>


                                    <div class="login-summary-card">

                                        <span>
                                            آخر دخول
                                        </span>

                                        <strong
                                            style="font-size:15px"
                                            x-text="selected?.login?.last_login ?? '—'"
                                        ></strong>

                                    </div>

                                </div>


                                {{-- Login history --}}

                                <div class="login-section-title">
                                    سجل الدخول اليوم
                                </div>


                                <div
                                    class="login-list"
                                    x-show="selected?.login?.logs?.length"
                                >

                                    <template
                                        x-for="(log, index) in (selected?.login?.logs ?? [])"
                                        :key="index"
                                    >

                                        <div class="login-row">

                                            <div class="login-time">

                                                <span x-text="log.time"></span>

                                            </div>


                                            <div class="login-info">

                                                <small>
                                                    IP Address
                                                </small>

                                                <strong
                                                    x-text="log.ip"
                                                ></strong>

                                            </div>


                                            <div class="login-info">

                                                <small>
                                                    الجهاز
                                                </small>

                                                <strong
                                                    x-text="log.device_type"
                                                ></strong>

                                                <span
                                                    x-text="log.platform + ' • ' + log.browser"
                                                    style="
                                                        display:block;
                                                        margin-top:3px;
                                                        font-size:11px;
                                                        color:#94a3b8;
                                                    "
                                                ></span>

                                            </div>


                                            <div class="login-info">

                                                <small>
                                                    حالة الجهاز
                                                </small>

                                                <template
                                                    x-if="log.same_device"
                                                >

                                                    <span class="same-device">
                                                        ✓ نفس الجهاز
                                                    </span>

                                                </template>


                                                <template
                                                    x-if="!log.same_device"
                                                >

                                                    <span class="different-device">
                                                        ! جهاز مختلف
                                                    </span>

                                                </template>

                                            </div>

                                        </div>

                                    </template>

                                </div>


                                <div
                                    class="login-empty"
                                    x-show="!selected?.login?.logs?.length"
                                >

                                    <div style="font-size:35px;margin-bottom:10px">
                                        🔐
                                    </div>

                                    لا يوجد تسجيل دخول اليوم

                                </div>

                            </div>

                        </div>

                    </div>

                </template>

            @endif

        </div>

    </x-filament::section>

</x-filament-widgets::widget>
