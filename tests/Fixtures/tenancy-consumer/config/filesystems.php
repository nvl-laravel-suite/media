<?php

declare(strict_types=1);

return [
    'default' => 'tenant-disk',
    'disks' => [
        'tenant-disk' => [
            'driver' => 'local',
            'root' => env('MEDIA_STORAGE_ROOT', storage_path('app/media')),
            'throw' => true,
        ],
    ],
];
