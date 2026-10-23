@once
@push('styles')
<link rel="stylesheet" href="{{ asset('motogate-v2/assets/css/payment-result.css') . '?v=' . filemtime(public_path('motogate-v2/assets/css/payment-result.css')) }}">
@endpush
@endonce
<div class="modal fade glass-modal payment-receipt" id="paymentResult" tabindex="-1" aria-labelledby="paymentResultTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" dir="rtl">
            <div class="modal-header receipt-header">
                <div><span class="receipt-kicker">MOTOGATE / YOUR PAYMENT PLAN</span><h2 class="modal-title" id="paymentResultTitle">خطتك لأول مشوار.</h2></div>
                <button type="button" class="receipt-close" data-bs-dismiss="modal" aria-label="إغلاق">×</button>
            </div>
            <div class="modal-body" id="paymentSummary" aria-live="polite">
                <div class="receipt-bike"><span class="receipt-bike-icon" aria-hidden="true">↗</span><div><span>الموتوسيكل اللي اخترته</span><h3 data-payment="machine">—</h3></div><span class="receipt-system" data-payment="system">—</span></div>
                <div class="receipt-monthly"><span class="receipt-watermark" aria-hidden="true">LET’S RIDE.</span><span class="receipt-amount-label">القسط الشهري</span><div class="receipt-amount"><strong data-payment="monthly">—</strong><span>جنيه <small>/ شهر</small></span></div><span class="receipt-monthly-caption">خطوة أقرب لمكنتك… بخطة تناسبك.</span></div>
                <div class="receipt-facts">
                    <div><span>مدة التقسيط</span><p><strong data-payment="months">—</strong><small>شهر</small></p></div>
                    <div><span>المقدم</span><p><strong data-payment="deposit">—</strong><small>جنيه</small></p></div>
                    <div><span>إجمالي الأقساط</span><p><strong data-payment="total">—</strong><small>جنيه</small></p></div>
                    <div class="receipt-fees"><span>المصاريف الإدارية</span><p><strong data-payment="fees">—</strong><small>جنيه</small></p></div>
                </div>
                <div class="receipt-note"><span aria-hidden="true">i</span><p>المصاريف الإدارية بتتدفع <strong>مرة واحدة عند الاستلام</strong>، بصورة منفصلة عن إجمالي الأقساط.</p></div>
                <a class="button button-orange receipt-apply" data-payment-apply href="{{ route('installments.apply.form') }}"><span>قدّم على التقسيط الآن</span><span aria-hidden="true">←</span></a>
                <p class="receipt-footnote">راجع تفاصيل النظام مع فريقنا قبل إتمام الطلب.</p>
            </div>
        </div>
    </div>
</div>
