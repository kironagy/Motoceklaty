<?php

return [
    'enabled' => (bool) env('OCR_ENABLED', true),
    'url' => rtrim((string) env('PADDLE_OCR_URL', 'http://127.0.0.1:8100'), '/'),
    'token' => (string) env('PADDLE_OCR_TOKEN', ''),
    'timeout' => (int) env('PADDLE_OCR_TIMEOUT', 90),
    'connect_timeout' => (int) env('PADDLE_OCR_CONNECT_TIMEOUT', 5),
    'max_file_size_kb' => (int) env('OCR_MAX_FILE_SIZE_KB', 10240),
    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/bmp',
        'image/tiff',
        'application/pdf',
    ],
];
