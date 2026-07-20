<div class="p-3 rounded" style="background:#e6f8ff;">
    <h6 class="fw-bold mb-3">📄 نتائج القراءة من المستندات:</h6>

    <table class="table table-bordered table-sm bg-white mb-3">
        <tr>
            <th>حالة الطلب</th>
            <td><span class="badge bg-secondary">pending</span></td>
        </tr>
        <tr>
            <th>الرقم القومي (عميل)</th>
            <td>{{ $extra['applicantNationalId'] ?? 'غير متوفر' }}</td>
        </tr>
        <tr>
            <th>تاريخ ميلاد (عميل)</th>
            <td>{{ $extra['appBirthdate'] ?? 'غير متوفر' }}</td>
        </tr>
        <tr>
            <th>الرقم القومي (ضامن)</th>
            <td>{{ $extra['guarantorNationalId'] ?? 'غير متوفر' }}</td>
        </tr>
        <tr>
            <th>تاريخ ميلاد (ضامن)</th>
            <td>{{ $extra['guaBirthdate'] ?? 'غير متوفر' }}</td>
        </tr>
    </table>

    <ul class="mb-3">
        <li><b>اسم العميل من البطاقة:</b> {{ $extra['applicantNameFromId'] ?? 'غير محدد' }}</li>
        <li><b>اسم الضامن من البطاقة:</b> {{ $extra['guarantorNameFromId'] ?? 'غير محدد' }}</li>

        @if(!empty($extra['salaryName']))
            <li><b>الاسم من مفردات المرتب:</b> {{ $extra['salaryName'] }}</li>
        @endif
        @if(!empty($extra['salaryNid']))
            <li><b>الرقم القومي من مفردات المرتب:</b> {{ $extra['salaryNid'] }}</li>
        @endif
    </ul>

    <h6 class="fw-bold">نتيجة الفحوصات:</h6>
    <ul class="ps-3">
        @foreach ($checks as $check)
            @php
                $cls = match($check['type']) {
                    'ok' => 'text-success',
                    'warn' => 'text-warning',
                    'error' => 'text-danger',
                    'info' => 'text-info',
                    default => 'text-muted'
                };
            @endphp
            <li class="{{ $cls }}">{!! $check['msg'] !!}</li>
        @endforeach
    </ul>

    @if(!empty($extra['salaryText']))
        <div class="mt-3">
            <b>📜 النص الكامل المقروء من مفردات المرتب:</b>
            <pre style="background:#fff;border-radius:8px;padding:10px;white-space:pre-wrap;">{{ $extra['salaryText'] }}</pre>
        </div>
    @endif
</div>
