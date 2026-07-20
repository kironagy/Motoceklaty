@extends('layouts.app')

@section('content')
    <!-- Installment Section -->
   <section class="installment-section mt-3" id="installment">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-12">
                <div class="installment-box">
                    <h4 class="text-center mb-4">احسب قسط مكنتك</h4>
                    <form id="calcForm">
                        @csrf
                        <div class="row g-3">

                            <div class="col-6">
                                <label>ماركة المكنة</label>
                                <select class="form-control" id="calc_brandSelect">
                                    <option value="">اختر...</option>
                                    @foreach ($brands as $brand)
                                        <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-6">
                                <label>المكنة</label>
                                <select class="form-control" id="calc_machineSelect" disabled>
                                    <option value="">اختر...</option>
                                    @foreach ($machines as $m)
                                        <option value="{{ $m->id }}" data-brand="{{ $m->brand_id }}"
                                            data-price="{{ $m->installment_price ?? 0 }}">
                                            {{ $m->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-6">
                                <label>نظام التقسيط</label>
                                <select class="form-control" id="calc_systemSelect">
                                    <option value="">اختر النظام...</option>
                                    @foreach ($installmentSystems as $system)
                                        <option value="{{ $system->id }}"
                                            data-plans='@json($system->plans)'
                                            data-fees="{{ $system->administrative_fees }}">
                                            {{ $system->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-6">
                                <label>عدد الشهور</label>
                                <select class="form-control" id="calc_monthsSelect" disabled>
                                    <option value="">اختر...</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label>المقدم</label>
                                <input type="number" class="form-control" id="calc_downPayment"
                                    placeholder="0">
                            </div>

                            <div class="col-12 text-center mt-3">
                                <button type="submit" class="calc-btn">احسب القسط</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

@endsection
