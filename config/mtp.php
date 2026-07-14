<?php

return [
    'server' => [
        'host' => env('MTP_SERVER_HOST'),
        'port' => env('MTP_SERVER_PORT'),
    ],
    'ssh' => [
        'host' => env('MTP_SSH_HOST'),
        'port' => env('MTP_SSH_PORT'),
        'user' => env('MTP_SSH_USER'),
        'key_path' => env('MTP_SSH_KEY_PATH'),
    ],
];
