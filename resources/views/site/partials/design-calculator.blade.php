@php
$uid = 'calc-' . \Illuminate\Support\Str::random(8);
$labels = site('installment.calculator.labels', []);
$config = ['endpoint' => route('installment.calculate'), 'applyUrl' => route('installments.apply.form'), 'machineId' => null, 'labels' => $labels, 'result' => site('installment.calculator.result', [])];
@endphp
@once
<script type="application/json" id="site-calc-catalog">@json(\App\Support\InstallmentCalculatorCatalog::payload())</script>
@push('scripts')<script src="{{ site_asset('js/site-calculator.js') }}"></script>@endpush
@endonce
<form data-config="{{ json_encode($config) }}" data-installment-calculator="">
<div class="calculator-fields">
<label for="{{ $uid }}-brand"><span class="visually-hidden">{{ $labels['brand'] ?? '' }}</span><img alt="" aria-hidden="true" class="icon bi bi-bicycle" height="24" src="{{ asset('motogate-v2/assets/images/icons/bicycle.svg') }}" width="24"/><select data-field="brand" id="{{ $uid }}-brand" required=""><option value="">{{ $labels['brand_placeholder'] ?? '' }}</option></select></label><label for="{{ $uid }}-machine"><span class="visually-hidden">{{ $labels['machine'] ?? '' }}</span><img alt="" aria-hidden="true" class="icon bi bi-bicycle" height="24" src="{{ asset('motogate-v2/assets/images/icons/bicycle.svg') }}" width="24"/><select data-field="machine" disabled="" id="{{ $uid }}-machine" required=""><option value="">{{ $labels['machine_placeholder'] ?? '' }}</option></select></label><label for="{{ $uid }}-system"><span class="visually-hidden">{{ $labels['system'] ?? '' }}</span><img alt="" aria-hidden="true" class="icon bi bi-cash-coin" height="24" src="{{ asset('motogate-v2/assets/images/icons/cash-coin.svg') }}" width="24"/><select data-field="system" disabled="" id="{{ $uid }}-system" required=""><option value="">{{ $labels['system_placeholder'] ?? '' }}</option></select></label><label for="{{ $uid }}-months"><span class="visually-hidden">{{ $labels['months'] ?? '' }}</span><img alt="" aria-hidden="true" class="icon bi bi-calendar3" height="24" src="{{ asset('motogate-v2/assets/images/icons/calendar3.svg') }}" width="24"/><select data-field="months" disabled="" id="{{ $uid }}-months" required=""><option value="">{{ $labels['months_placeholder'] ?? '' }}</option></select></label><label for="{{ $uid }}-down_payment"><span class="visually-hidden">{{ $labels['down_payment'] ?? '' }}</span><img alt="" aria-hidden="true" class="icon bi bi-wallet2" height="24" src="{{ asset('motogate-v2/assets/images/icons/wallet2.svg') }}" width="24"/><input data-field="down_payment" id="{{ $uid }}-down_payment" min="0" placeholder="المقدم" step="100" type="number"/></label>
</div>
<button class="button button-orange" type="submit">
                احسب قسطك
                <img alt="" aria-hidden="true" class="icon bi bi-calculator" height="24" src="{{ asset('motogate-v2/assets/images/icons/calculator.svg') }}" width="24"/></button>
<output id="installmentResult" data-calc-result aria-live="polite"></output></form>
