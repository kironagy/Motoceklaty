{{--
    The design's hero search widget, wired to the real calculator.
    tabs: [{label, image, categories[], brands[], browse_text}] filter the
    machine list; machine: lock the widget to one model (detail page).
    Numbers come from InstallmentController::calculate (InstallmentCalculator).
--}}
@props(['tabs' => [], 'title' => null, 'machine' => null, 'browseUrl' => null])

@php
    $labels = site('installment.calculator.labels', []);
    $result = site('installment.calculator.result', []);
    $tabs = array_values(array_filter($tabs, fn ($tab) => filled($tab['label'] ?? null)));
    $firstTab = $tabs[0] ?? null;
    $config = [
        'endpoint' => route('installment.calculate'),
        'applyUrl' => route('installments.apply.form'),
        'machineId' => $machine?->id,
        'labels' => $labels,
        'result' => $result,
    ];
@endphp

@once
    <script type="application/json" id="site-calc-catalog">@json(\App\Support\InstallmentCalculatorCatalog::payload())</script>
    @push('scripts')
        <script src="{{ site_asset('js/site-calculator.js') }}"></script>
    @endpush
@endonce

<div {{ $attributes->merge(['class' => 'twm-banner-search-tabs']) }} dir="rtl">

    @if ($title)
        <h3 class="twm-tabs-title">{{ $title }}</h3>
    @endif

    <div class="twm-banner-tabs-filter">
        @if (count($tabs) > 1)
            <nav>
                <div class="nav nav-tabs" style="grid-template-columns: repeat({{ count($tabs) }}, 1fr) !important;">
                    @foreach ($tabs as $i => $tab)
                        <div @class(['nav-link', 'active' => $i === 0]) role="button" tabindex="0"
                            data-calc-tab
                            data-categories='@json(array_values($tab['categories'] ?? []))'
                            data-brands='@json(array_map('intval', array_values($tab['brands'] ?? [])))'
                            data-browse-text="{{ $tab['browse_text'] ?? '' }}">
                            <div class="twm-tabs-bx">
                                @if (! empty($tab['image']))
                                    <div class="tabs-media"><img src="{{ site_url($tab['image']) }}" alt="{{ $tab['label'] }}"></div>
                                @endif
                                <div class="tabs-title">{{ $tab['label'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </nav>
        @endif

        <div class="tab-content" @if (count($tabs) <= 1) style="border-radius: 20px;" @endif>
            <div class="tab-pane fade show active">
                <div class="twm-tabs-search-section">
                    <form data-installment-calculator data-config='@json($config)'
                        @if ($firstTab && count($tabs) <= 1)
                            data-categories='@json(array_values($firstTab['categories'] ?? []))'
                            data-brands='@json(array_map('intval', array_values($firstTab['brands'] ?? [])))'
                        @endif
                        novalidate>
                        <div class="row m-b20">
                            @unless ($machine)
                                <div class="col-12 col-lg-6">
                                    <div class="form-group">
                                        <label>{{ $labels['brand'] ?? '' }}</label>
                                        <select class="form-select p-3 form-control" data-field="brand">
                                            <option value="">{{ $labels['brand_placeholder'] ?? '' }}</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <div class="form-group">
                                        <label>{{ $labels['machine'] ?? '' }}</label>
                                        <select class="form-select p-3 form-control" data-field="machine" disabled>
                                            <option value="">{{ $labels['machine_placeholder'] ?? '' }}</option>
                                        </select>
                                    </div>
                                </div>
                            @endunless
                            <div class="col-12 col-lg-6">
                                <div class="form-group">
                                    <label>{{ $labels['system'] ?? '' }}</label>
                                    <select class="form-select p-3 form-control" data-field="system" @unless ($machine) disabled @endunless>
                                        <option value="">{{ $labels['system_placeholder'] ?? '' }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <div class="form-group">
                                    <label>{{ $labels['months'] ?? '' }}</label>
                                    <select class="form-select p-3 form-control" data-field="months" disabled>
                                        <option value="">{{ $labels['months_placeholder'] ?? '' }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ $labels['down_payment'] ?? '' }}</label>
                                    <input class="form-control" type="number" min="0" step="any" inputmode="numeric"
                                        data-field="down_payment" placeholder="{{ $labels['down_payment_placeholder'] ?? '' }}">
                                </div>
                            </div>
                        </div>

                        <div class="site-calc-error" data-calc-error hidden></div>

                        <div class="twm-tabs-search-btn">
                            <button type="submit" class="site-button"><em>{{ $labels['submit'] ?? '' }}</em></button>
                        </div>

                        @if ($browseUrl)
                            <div class="site-calc-browse">
                                <a href="{{ $browseUrl }}" data-calc-browse>{{ $firstTab['browse_text'] ?? '' }} <i class="fa-solid fa-arrow-left"></i></a>
                            </div>
                        @endif

                        <div class="site-calc-result" data-calc-result hidden></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
