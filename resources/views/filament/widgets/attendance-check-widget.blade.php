<x-filament-widgets::widget>
    <x-filament::section>
        <div
            dir="rtl"
            x-data="{
                loading: false,

                getLocation(action) {
                    if (! navigator.geolocation) {
                        alert('المتصفح لا يدعم تحديد الموقع');
                        return;
                    }

                    this.loading = true;

                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            const lat = position.coords.latitude;
                            const lng = position.coords.longitude;

                            if (action === 'checkIn') {
                                $wire.checkIn(lat, lng);
                            }

                            if (action === 'checkOut') {
                                $wire.checkOut(lat, lng);
                            }

                            this.loading = false;
                        },
                        (error) => {
                            this.loading = false;

                            if (error.code === 1) {
                                alert('لازم تسمح باللوكيشن عشان تقدر تبصم');
                            } else {
                                alert('حصلت مشكلة في تحديد الموقع، جرب تاني');
                            }
                        },
                        {
                            enableHighAccuracy: true,
                            timeout: 15000,
                            maximumAge: 0
                        }
                    );
                }
            }"
            class="attendance-box"
        >
            <style>
                .attendance-points { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-top: 1rem; }
                .attendance-point { font-size: .85rem; font-weight: 700; padding: .35rem .8rem; border-radius: 999px; background: rgba(255,255,255,.08); border: 1px solid rgba(148,163,184,.3); }
                .attendance-points-hint { flex-basis: 100%; font-size: .78rem; opacity: .7; }
                .attendance-box {
                    display: grid;
                    gap: 18px;
                }

                .attendance-head {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 16px;
                    flex-wrap: wrap;
                }

                .attendance-title {
                    font-size: 22px;
                    font-weight: 900;
                    color: #0f172a;
                    margin: 0;
                }

                .dark .attendance-title {
                    color: #f8fafc;
                }

                .attendance-subtitle {
                    color: #64748b;
                    margin-top: 6px;
                    font-size: 14px;
                }

                .dark .attendance-subtitle {
                    color: #cbd5e1;
                }

                .attendance-status {
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                }

                .attendance-pill {
                    padding: 10px 14px;
                    border-radius: 999px;
                    font-size: 13px;
                    font-weight: 800;
                    background: #f1f5f9;
                    color: #334155;
                    border: 1px solid rgba(148,163,184,.35);
                }

                .dark .attendance-pill {
                    background: rgba(15,23,42,.7);
                    color: #e2e8f0;
                    border-color: rgba(148,163,184,.25);
                }

                .attendance-actions {
                    display: grid;
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    gap: 14px;
                }

                .attendance-btn {
                    border: 0;
                    border-radius: 22px;
                    padding: 18px;
                    font-size: 18px;
                    font-weight: 900;
                    cursor: pointer;
                    color: white;
                    transition: .2s ease;
                    box-shadow: 0 16px 35px rgba(15, 23, 42, .18);
                }

                .attendance-btn:hover {
                    transform: translateY(-2px);
                }

                .attendance-btn:disabled {
                    opacity: .55;
                    cursor: not-allowed;
                    transform: none;
                }

                .attendance-in {
                    background: linear-gradient(135deg, #16a34a, #22c55e);
                }

                .attendance-out {
                    background: linear-gradient(135deg, #ea580c, #f97316);
                }

                .attendance-note {
                    padding: 14px 16px;
                    border-radius: 18px;
                    background: #fff7ed;
                    color: #9a3412;
                    font-weight: 700;
                    font-size: 14px;
                    border: 1px solid #fed7aa;
                }

                .dark .attendance-note {
                    background: rgba(154, 52, 18, .18);
                    color: #fdba74;
                    border-color: rgba(251, 146, 60, .25);
                }

                @media (max-width: 768px) {
                    .attendance-actions {
                        grid-template-columns: 1fr;
                    }
                }
            </style>

            <div class="attendance-head">
                <div>
                    <h2 class="attendance-title">بصمة الحضور والانصراف</h2>
                    <div class="attendance-subtitle">
                        لازم تسمح باللوكيشن عشان البصمة تتسجل من مكان الفرع بتاعك.
                    </div>
                </div>

                <div class="attendance-status">
                    <div class="attendance-pill">
                        الحضور:
                        {{ $this->todayAttendance?->check_in_at ? $this->todayAttendance->check_in_at->format('h:i A') : 'لسه' }}
                    </div>

                    <div class="attendance-pill">
                        الانصراف:
                        {{ $this->todayAttendance?->check_out_at ? $this->todayAttendance->check_out_at->format('h:i A') : 'لسه' }}
                    </div>

                    @if($this->todayAttendance && ! $this->todayAttendance->attendance_date->isToday())
                        <div class="attendance-pill">وردية {{ $this->todayAttendance->attendance_date->format('Y-m-d') }} لسه مفتوحة</div>
                    @endif

                    @if(($this->todayAttendance?->overtime_minutes ?? 0) > 0)
                        <div class="attendance-pill">
                            وقت زيادة:
                            {{ \App\Filament\Widgets\AttendanceCheckWidget::hoursText((int) $this->todayAttendance->overtime_minutes) }}
                        </div>
                    @endif

                    @if(($this->todayAttendance?->deduction_amount ?? 0) > 0)
                        <div class="attendance-pill">
                            الخصم:
                            {{ number_format($this->todayAttendance->deduction_amount, 2) }} ج
                        </div>
                    @endif
                </div>
            </div>

            @php($points = $this->monthPoints)
            <div class="attendance-points" title="نقطك الشهر ده">
                <span class="attendance-point">🟢 {{ $points['green'] }} خضرا متاحة</span>
                <span class="attendance-point">🔴 {{ $points['red'] }} حمرا</span>
                <span class="attendance-point">⚫ {{ $points['black'] }} سودا</span>
                @if($points['canceled'])
                    <span class="attendance-point">✅ {{ $points['canceled'] }} اتلغت</span>
                @endif
                <span class="attendance-points-hint">
                    {{ \App\Domain\Attendance\AttendancePoints::greensNeeded('red') }} خضرا بعد الحمرا يلغوها، و{{ \App\Domain\Attendance\AttendancePoints::greensNeeded('black') }} خضرا بعد السودا يلغوها - من صفحة النقط.
                </span>
            </div>

            <div class="attendance-actions">
                <button
                    type="button"
                    class="attendance-btn attendance-in"
                    x-on:click="getLocation('checkIn')"
                    x-bind:disabled="loading || @js((bool) $this->todayAttendance?->check_in_at)"
                >
                    بصمة حضور
                </button>

                <button
                    type="button"
                    class="attendance-btn attendance-out"
                    x-on:click="getLocation('checkOut')"
                    x-bind:disabled="loading || @js(! $this->todayAttendance?->check_in_at || (bool) $this->todayAttendance?->check_out_at)"
                >
                    بصمة انصراف
                </button>
            </div>

            <div class="attendance-note">
                لو في تأخير، السيستم هيحسب عدد الدقايق ويطبق قاعدة الخصم المتسجلة للموظف تلقائيًا.
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
