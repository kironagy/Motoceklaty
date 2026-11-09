<?php

/*
 * Only the temporary-upload settings differ from Livewire's defaults (the rest is merged in from the
 * package config). Staff upload many photos at once (daily task proofs, documents): allow files up to
 * the 20 MB the forms accept, more uploads per minute, and more time on a slow phone connection.
 */
return [
    'temporary_file_upload' => [
        'disk' => null,
        'rules' => ['required', 'file', 'max:20480'],
        'directory' => null,
        'middleware' => 'throttle:240,1',
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 15,
        'cleanup' => true,
    ],
];
