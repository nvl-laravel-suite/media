<?php

declare(strict_types=1);

use Nvl\Media\Tests\Stubs\TestMediaModel;

return [
    'disk' => 'tenant-disk',
    'default_path' => 'owners/{model_id}',
    'migrations' => ['enabled' => false],
    'routes' => ['api_enabled' => false, 'assets_enabled' => false],
    'file_types' => ['png' => 'image/png'],
    'group_types' => ['image' => ['png']],
    'tenancy' => ['owner_types' => [TestMediaModel::class]],
    'queue' => [
        'enabled' => true,
        'connection' => env('QUEUE_CONNECTION', 'database'),
        'name' => env('MEDIA_QUEUE', 'default'),
        'jobs' => [
            'generate' => [
                'tries' => 2,
                'timeout' => 30,
                'backoff' => [0],
                'unique_for' => 60,
            ],
        ],
    ],
    'image_variation_presets' => [
        'proof' => [
            'width' => 1,
            'height' => 1,
            'format' => 'png',
            'quality' => 80,
            'enabled' => true,
            'queued' => true,
        ],
    ],
];
