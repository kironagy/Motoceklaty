<x-filament-panels::page>
    <div dir="rtl" class="salary-page space-y-6">

        <style>
            .salary-page{
                --card-bg:#ffffff;
                --border:#e2e8f0;
                --muted:#64748b;
            }

            .dark .salary-page{
                --card-bg:#111827;
                --border:#1f2937;
                --muted:#94a3b8;
            }

            .salary-filter-card{
                background:var(--card-bg);
                border:1px solid var(--border);
                border-radius:28px;
                padding:28px;
                box-shadow:0 10px 30px rgba(15,23,42,.05);
            }

            .salary-filter-title{
                font-size:26px;
                font-weight:900;
                color:#0f172a;
                margin-bottom:18px;
            }

            .dark .salary-filter-title{
                color:#fff;
            }

            .salary-month-box{
                max-width:280px;
                margin-right:auto;
            }

            .salary-table-card{
                overflow:hidden;
                border-radius:30px;
                border:1px solid var(--border);
                background:var(--card-bg);
                box-shadow:0 10px 30px rgba(15,23,42,.05);
            }

            .salary-table{
                width:100%;
                border-collapse:collapse;
            }

            .salary-table thead{
                background:
                    linear-gradient(
                        135deg,
                        rgba(59,130,246,.08),
                        rgba(16,185,129,.08)
                    );
            }

            .dark .salary-table thead{
                background:
                    linear-gradient(
                        135deg,
                        rgba(59,130,246,.14),
                        rgba(16,185,129,.10)
                    );
            }

            .salary-table th{
                padding:22px 18px;
                font-size:15px;
                font-weight:900;
                color:#0f172a;
                text-align:center;
                white-space:nowrap;
            }

            .dark .salary-table th{
                color:#fff;
            }

            .salary-table td{
                padding:22px 16px;
                text-align:center;
                border-top:1px solid var(--border);
                font-size:15px;
                font-weight:700;
                color:#1e293b;
            }

            .dark .salary-table td{
                color:#e5e7eb;
            }

            .salary-table tbody tr{
                transition:.18s ease;
            }

            .salary-table tbody tr:hover{
                background:rgba(59,130,246,.04);
            }

            .dark .salary-table tbody tr:hover{
                background:rgba(255,255,255,.03);
            }

            .salary-user{
                display:flex;
                flex-direction:column;
                align-items:center;
                gap:6px;
            }

            .salary-user-name{
                font-size:18px;
                font-weight:900;
                color:#0f172a;
            }

            .dark .salary-user-name{
                color:#fff;
            }

            .salary-warehouse{
                color:var(--muted);
                font-size:13px;
                font-weight:700;
            }

            .salary-pill{
                display:inline-flex;
                align-items:center;
                justify-content:center;
                padding:12px 18px;
                border-radius:16px;
                font-size:14px;
                font-weight:900;
                min-width:120px;
                border:1px solid rgba(124,58,237,.22);
                box-shadow:0 10px 24px rgba(124,58,237,.10);
                white-space:nowrap;
            }

            .salary-basic{
                background:rgba(59,130,246,.12);
                color:#2563eb;
            }

            .salary-day{
                background:rgba(14,165,233,.12);
                color:#0284c7;
            }

            .salary-late{
                background:rgba(245,158,11,.12);
                color:#d97706;
            }

            .salary-deduction{
                background:rgba(239,68,68,.12);
                color:#dc2626;
            }

            .salary-advance{
                background:rgba(249,115,22,.12);
                color:#ea580c;
            }

            .salary-net{
                background:rgba(34,197,94,.14);
                color:#16a34a;
                font-size:16px;
                min-width:120px;
            }

            .salary-bonus{
                background:rgba(168,85,247,.13);
                color:#9333ea;
            }

            .salary-bonus-btn{
                border:0;
                border-radius:16px;
                padding:12px 18px;
                background:linear-gradient(135deg,#6d28d9,#9333ea,#a855f7);
                color:white;
                font-size:14px;
                font-weight:900;
                cursor:pointer;
                display:inline-flex;
                align-items:center;
                justify-content:center;
                gap:8px;
                white-space:nowrap;
                min-width:120px;
                box-shadow:0 12px 28px rgba(124,58,237,.25);
                transition:.2s ease;
            }

            .salary-bonus-btn:hover{
                transform:translateY(-2px);
                box-shadow:0 18px 35px rgba(124,58,237,.35);
            }

            .salary-cancel-btn{
                border:0;
                border-radius:16px;
                padding:12px 18px;
                background:#e5e7eb;
                color:#111827;
                font-size:14px;
                font-weight:900;
                cursor:pointer;
                min-width:100px;
                transition:.2s ease;
            }

            .salary-cancel-btn:hover{
                background:#d1d5db;
            }

            .dark .salary-cancel-btn{
                background:#374151;
                color:#fff;
            }

            .salary-modal-backdrop{
                position:fixed;
                inset:0;
                background:rgba(15,23,42,.55);
                z-index:9998;
                display:flex;
                align-items:center;
                justify-content:center;
                padding:20px;
            }

            .salary-modal{
                width:100%;
                max-width:520px;
                background:#fff;
                border-radius:28px;
                padding:26px;
                box-shadow:0 30px 80px rgba(15,23,42,.35);
            }

            .dark .salary-modal{
                background:#111827;
            }

            .salary-modal-title{
                font-size:22px;
                font-weight:900;
                margin-bottom:18px;
                color:#0f172a;
            }

            .dark .salary-modal-title{
                color:#fff;
            }

            .salary-modal-actions{
                display:flex;
                gap:10px;
                margin-top:18px;
            }

            .salary-empty{
                padding:70px 20px;
                text-align:center;
                color:var(--muted);
                font-weight:800;
                font-size:15px;
            }

            @media (max-width: 1400px){
                .salary-table-card{
                    overflow:auto;
                }

                .salary-table{
                    min-width:1200px;
                }
            }
        </style>

        <div class="salary-filter-card">
            <div class="salary-filter-title">
                المرتبات الشهرية
            </div>

            <div class="salary-month-box">
                <label class="block mb-3 font-black text-gray-900 dark:text-white">
                    اختر الشهر
                </label>

                <input
                    type="month"
                    wire:model.live="month"
                    class="w-full rounded-2xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                >
            </div>
        </div>

        <div class="salary-table-card">
            <table class="salary-table">
                <thead>
                    <tr>
                        <th>الموظف</th>
                        <th>المرتب</th>
                        <th>أجر اليوم</th>
                        <th>الحضور</th>
                        <th>التأخير</th>
                        <th>دقائق التأخير</th>
                        <th>النقط</th>
                        <th>وقت زيادة</th>
                        <th>خصومات التأخير</th>
                        <th>السلف</th>
                        <th>البونص</th>
                        <th>الصافي</th>
                        <th>إجراء</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($this->salaryRows as $row)
                        <tr>
                            <td>
                                <div class="salary-user">
                                    <div class="salary-user-name">
                                        {{ $row['user']->name }}
                                    </div>

                                    <div class="salary-warehouse">
                                        {{ $row['warehouse'] }}
                                    </div>
                                </div>
                            </td>

                            <td>
                                <span class="salary-pill salary-basic">
                                    {{ number_format($row['monthly_salary'], 2) }} ج
                                </span>
                            </td>

                            <td>
                                <span class="salary-pill salary-day">
                                    {{ number_format($row['day_salary'], 2) }} ج
                                </span>
                            </td>

                            <td>
                                {{ $row['present_days'] }} يوم
                            </td>

                            <td>
                                {{ $row['late_days'] }} مرة
                            </td>

                            <td>
                                {{ $row['late_minutes'] }} دقيقة
                            </td>

                            <td style="white-space: nowrap;" title="خضرا متاحة / حمرا / سودا قائمة - واللي اتلغت">
                                🟢 {{ $row['points']['green'] }}
                                🔴 {{ $row['points']['red'] }}
                                ⚫ {{ $row['points']['black'] }}
                                @if($row['points']['canceled'])
                                    <span style="opacity:.7">✅ {{ $row['points']['canceled'] }}</span>
                                @endif
                            </td>

                            <td style="white-space: nowrap;">{{ $row['overtime'] }}</td>

                            <td>
                                <span class="salary-pill salary-deduction">
                                    {{ number_format($row['attendance_deductions'], 2) }} ج
                                </span>
                            </td>

                            <td>
                                <span class="salary-pill salary-advance">
                                    {{ number_format($row['approved_advances'], 2) }} ج
                                </span>
                            </td>

                            <td>
                                <span class="salary-pill salary-bonus">
                                    {{ number_format($row['bonuses'], 2) }} ج
                                </span>
                            </td>

                            <td>
                                <span class="salary-pill salary-net">
                                    {{ number_format($row['net_salary'], 2) }} ج
                                </span>
                            </td>

                            <td>
                                <button
                                    type="button"
                                    wire:click="openBonusModal({{ $row['user']->id }})"
                                    class="salary-bonus-btn"
                                >
                                    ＋ إضافة بونص
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11">
                                <div class="salary-empty">
                                    لا يوجد بيانات مرتبات لهذا الشهر.
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($showBonusModal)
            <div class="salary-modal-backdrop">
                <div class="salary-modal">
                    <div class="salary-modal-title">
                        ＋ إضافة بونص لـ {{ $bonusEmployeeName }}
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block mb-2 font-bold">قيمة البونص</label>
                            <input type="number" wire:model="bonusAmount" class="w-full rounded-xl border-gray-300 dark:bg-gray-800 dark:text-white">
                            @error('bonusAmount') <div class="text-red-600 text-sm mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="block mb-2 font-bold">تاريخ البونص</label>
                            <input type="date" wire:model="bonusDate" class="w-full rounded-xl border-gray-300 dark:bg-gray-800 dark:text-white">
                            @error('bonusDate') <div class="text-red-600 text-sm mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="block mb-2 font-bold">سبب البونص</label>
                            <textarea wire:model="bonusReason" rows="4" class="w-full rounded-xl border-gray-300 dark:bg-gray-800 dark:text-white"></textarea>
                        </div>
                    </div>

                    <div class="salary-modal-actions">
                        <button type="button" wire:click="saveBonus" class="salary-bonus-btn">
                            حفظ البونص
                        </button>

                        <button type="button" wire:click="closeBonusModal" class="salary-cancel-btn">
                            إلغاء
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
