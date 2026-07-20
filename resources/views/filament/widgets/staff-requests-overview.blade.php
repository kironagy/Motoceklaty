<x-filament-widgets::widget>
    <style>
        .staff-board{direction:rtl}
        .staff-board-head{margin-bottom:22px}
        .staff-board-head h2{font-size:24px;font-weight:900;margin:0;color:#0f172a}
        .staff-board-head p{margin-top:8px;color:#64748b;font-size:14px}

        .staff-rank-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px}

        .staff-rank-card{
            position:relative;overflow:hidden;border-radius:28px;padding:24px;background:#fff;
            border:1px solid rgba(148,163,184,.25);box-shadow:0 18px 45px rgba(15,23,42,.08)
        }

        .staff-rank-card::before{content:"";position:absolute;inset:0;opacity:.13;pointer-events:none}
        .staff-rank-card.gold::before{background:linear-gradient(135deg,#f59e0b,#fde68a,#f97316)}
        .staff-rank-card.silver::before{background:linear-gradient(135deg,#94a3b8,#f8fafc,#64748b)}
        .staff-rank-card.bronze::before{background:linear-gradient(135deg,#92400e,#fdba74,#b45309)}

        .staff-card-top{position:relative;display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
        .staff-rank-badge{
            width:46px;height:46px;border-radius:16px;display:flex;align-items:center;justify-content:center;
            background:rgba(15,23,42,.06);font-size:22px;font-weight:900;margin-bottom:12px
        }

        .staff-rank-card h3{margin:0;font-size:18px;font-weight:900;color:#334155}
        .staff-rank-card p{margin:7px 0 0;color:#64748b;font-size:15px;font-weight:800}

        .staff-total{text-align:center;min-width:90px;padding:13px;border-radius:22px;background:rgba(15,23,42,.045)}
        .staff-total strong{display:block;font-size:42px;line-height:1;font-weight:950;color:#020617}
        .staff-total span{display:block;margin-top:7px;color:#64748b;font-weight:900}

        .staff-stats{position:relative;margin-top:22px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .staff-stat{border-radius:18px;padding:12px;display:flex;align-items:center;justify-content:space-between}
        .staff-stat span{font-size:13px;font-weight:900}
        .staff-stat strong{font-size:20px;font-weight:950}

        .staff-stat.approved{color:#16a34a;background:rgba(34,197,94,.11)}
        .staff-stat.paused{color:#64748b;background:rgba(100,116,139,.12)}
        .staff-stat.new{color:#2563eb;background:rgba(37,99,235,.11)}
        .staff-stat.pending{color:#ea580c;background:rgba(249,115,22,.13)}
        .staff-stat.delivered{color:#0891b2;background:rgba(6,182,212,.13)}
        .staff-stat.rejected{color:#dc2626;background:rgba(239,68,68,.13)}

        .staff-rank-card.gold{border-color:rgba(245,158,11,.45)}
        .staff-rank-card.silver{border-color:rgba(148,163,184,.45)}
        .staff-rank-card.bronze{border-color:rgba(180,83,9,.38)}

        .dark .staff-board-head h2{color:#f8fafc}
        .dark .staff-board-head p{color:#94a3b8}
        .dark .staff-rank-card{background:rgba(17,24,39,.96);border-color:rgba(255,255,255,.10)}
        .dark .staff-rank-card h3{color:#f8fafc}
        .dark .staff-rank-card p{color:#cbd5e1}
        .dark .staff-rank-badge,.dark .staff-total{background:rgba(255,255,255,.07)}
        .dark .staff-total strong{color:#fff}
        .dark .staff-total span{color:#cbd5e1}

        @media(max-width:1024px){.staff-rank-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:640px){.staff-rank-grid{grid-template-columns:1fr}}
    </style>

    <x-filament::section>
        <div class="staff-board">
            <div class="staff-board-head">
                <h2>ترتيب الموظفين</h2>
                <p>حسب إجمالي طلبات الشهر الحالي، ولو متساويين بنحسب عدد الموافقات</p>
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
                        <div class="staff-card-top">
                            <div>
                                <div class="staff-rank-badge">{{ $badge }}</div>
                                <h3>{{ $title }}</h3>
                                <p>{{ $staff['name'] }}</p>
                            </div>

                            <div class="staff-total">
                                <strong>{{ $staff['total'] }}</strong>
                                <span>طلب</span>
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
                                <strong>{{ $staff['delivered'] ?? 0 }}</strong>
                            </div>

                            <div class="staff-stat rejected">
                                <span>مرفوض</span>
                                <strong>{{ $staff['rejected'] ?? 0 }}</strong>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
