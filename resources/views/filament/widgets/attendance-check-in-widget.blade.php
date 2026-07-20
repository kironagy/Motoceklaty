<x-filament-widgets::widget>
    <x-filament::section>
        <div
            wire:key="attendance-checkin-gps"
            x-data="{
                lat: @entangle('lat').live,
                lng: @entangle('lng').live,
                loadingGps: false,
                gpsError: null,

                getLocation() {
                    this.gpsError = null;

                    if (!navigator.geolocation) {
                        this.gpsError = 'المتصفح لا يدعم GPS';
                        return;
                    }

                    this.loadingGps = true;

                    navigator.geolocation.getCurrentPosition(
                        (pos) => {
                            this.lat = pos.coords.latitude;
                            this.lng = pos.coords.longitude;

                            // ✅ احتياطي عشان لو entangle اتأخر لحظة
                            $wire.set('lat', this.lat, true);
                            $wire.set('lng', this.lng, true);

                            this.loadingGps = false;
                        },
                        (err) => {
                            console.log('Geolocation error:', err);
                            this.loadingGps = false;

                            if (err.code === 1) this.gpsError = 'لازم تسمح بالموقع من إعدادات المتصفح.';
                            else if (err.code === 2) this.gpsError = 'تعذر تحديد الموقع. جرّب تفعيل GPS في الجهاز.';
                            else if (err.code === 3) this.gpsError = 'انتهت مهلة تحديد الموقع. جرّب تاني.';
                            else this.gpsError = 'حصل خطأ في تحديد الموقع.';
                        },
                        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
                    );
                }
            }"
            x-init="$nextTick(() => getLocation())"
        >
            @php($last = $this->lastAttendance())
            @php($canCheckIn = $this->canCheckIn())
            @php($nextOpen = $last ? $last->checked_in_at->copy()->addDay()->setTime(13, 0, 0) : null)
            @php($locationMissing = $this->locationRequiredButMissing())

            <div dir="rtl" class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <div class="text-lg font-semibold">تسجيل الحضور</div>

                    <div class="text-sm text-gray-500 mt-1">
                        @if($last)
                            آخر تسجيل: {{ $last->checked_in_at->format('Y-m-d h:i A') }}
                            <br>
                            يفتح تاني: {{ $nextOpen->format('Y-m-d h:i A') }}
                        @else
                            لم يتم التسجيل بعد
                        @endif

                        <div class="mt-2">
                            <span class="font-medium">GPS:</span>
                            <span x-text="(lat !== null && lng !== null) ? (lat.toFixed(6) + ', ' + lng.toFixed(6)) : 'غير متاح'"></span>
                        </div>

                        <template x-if="gpsError">
                            <div class="mt-2 text-danger-600" x-text="gpsError"></div>
                        </template>

                        @if($locationMissing)
                            <div class="mt-2 text-warning-600">
                                ⚠️ لازم تحدد الموقع الأول عشان تقدر تسجل حضور.
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <x-filament::button
                        color="gray"
                        x-on:click="getLocation()"
                        x-bind:disabled="loadingGps"
                    >
                        <span x-show="!loadingGps">تحديث الموقع</span>
                        <span x-show="loadingGps">جاري التحديد...</span>
                    </x-filament::button>

                    <x-filament::button
                        :disabled="(! $canCheckIn) || $locationMissing"
                        wire:click="checkIn"
                        wire:loading.attr="disabled"
                    >
                        {{ $canCheckIn ? 'تسجيل حضور' : 'تم التسجيل' }}
                    </x-filament::button>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

