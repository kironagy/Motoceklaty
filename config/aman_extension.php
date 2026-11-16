<?php

return [
    // Request types the extension knows. Keys are installment_requests.work_status values.
    'types' => [
        'no_income_proof' => 'دخل حر',
        'employee' => 'موظف / صاحب عمل',
        'pension' => 'صاحب معاش',
        'self_employed' => 'صاحب نشاط',
    ],

    // Only requests for Aman companies (installment_type) go to the Aman portal, on top of what
    // the employee may see (InstallmentRequest::scopeVisibleTo).
    'installment_type_like' => 'امان%',

    // installment_requests.status values that count as "new".
    'new_statuses' => ['new_request'],

    // A claim nobody finished is free again after this many minutes.
    'claim_minutes' => 15,
];
