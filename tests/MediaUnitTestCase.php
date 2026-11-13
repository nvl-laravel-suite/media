<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Tests\Fixtures\ConsumerMediaProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Lightweight container and configuration test case for database-free Media unit tests.
 */
abstract class MediaUnitTestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LocaleServiceProvider::class,
            FilterableServiceProvider::class,
            SupportServiceProvider::class,
            TranslatableServiceProvider::class,
            MediaServiceProvider::class,
            ConsumerMediaProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'nvl-media.migrations.enabled' => false,
            'nvl-translatable.locales' => ['en', 'bg'],
            'nvl-translatable.default_locale' => 'en',
            'nvl-translatable.fallback_locales' => ['en'],
            'filesystems.default' => 'public',
            'cache.default' => 'array',
            'nvl-media.disk' => 'public',
            'nvl-media.delivery.disks.public.public.mode' => 'direct',
            'nvl-media.routes.api_enabled' => true,
            'nvl-media.routes.management_middleware' => ['auth'],
            'nvl-media.default_path' => '{model_type}/{model_id}',
            'nvl-media.conversions_folder' => 'conversions',
            'nvl-media.deduplication_lock.enabled' => true,
            'nvl-media.locks.deduplication.store' => 'array',
            'nvl-media.deduplication_lock.seconds' => 10,
            'nvl-media.deduplication_lock.wait_seconds' => 1,
            'nvl-media.delete_files_on_media_delete' => true,
            'nvl-media.clean_empty_directories' => true,
            'nvl-media.max_file_size' => 10 * 1024 * 1024,
            'nvl-media.file_types' => [
                'svg' => 'image/svg+xml', 'bmp' => 'image/bmp', 'gif' => 'image/gif',
                'png' => 'image/png', 'ico' => 'image/vnd.microsoft.icon', 'jpeg' => 'image/jpeg',
                'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif',
                'csv' => 'text/csv', 'doc' => 'application/msword', 'pdf' => 'application/pdf',
                'txt' => 'text/plain', 'json' => 'application/json', 'xml' => 'application/xml',
                'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'zip' => 'application/zip',
            ],
            'nvl-media.group_types' => [
                'image' => ['svg', 'bmp', 'gif', 'png', 'ico', 'jpeg', 'jpg', 'webp', 'avif'],
                'document' => ['doc', 'docx', 'pdf', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt'],
                'video' => ['mp4', 'mpeg', 'webm', 'mov'],
                'audio' => ['mp3', 'wav', 'ogg', 'aac', 'flac'],
                'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
                'code' => ['json', 'xml'],
            ],
        ]);
    }
}
