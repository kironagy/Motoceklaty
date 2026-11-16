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

    // Which request tabs go to Aman (installment_requests.request_type): the main ones and the
    // bot ones - never the fake ones (owner 2026-11-16).
    'request_types' => ['normal', 'bot'],

    // installment_requests.status values that count as "new".
    'new_statuses' => ['new_request'],

    // A claim nobody finished is free again after this many minutes.
    'claim_minutes' => 15,
];
