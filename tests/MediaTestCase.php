<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for Media module feature tests.
 *
 * Uses the externally configured test database and runs only the migrations
 * required by Media and its package foundations.
 */
abstract class MediaTestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            FilterableServiceProvider::class,
            SupportServiceProvider::class,
            TranslatableServiceProvider::class,
            MediaServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('test_media_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'filesystems.default' => 'public',
            'cache.default' => 'array',
            'media.disk' => 'public',
            'media.routes.api_enabled' => true,
            'media.routes.management_middleware' => ['auth'],
            'media.default_path' => '{model_type}/{model_id}',
            'media.conversions_folder' => 'conversions',
            'media.deduplication_lock.enabled' => true,
            'media.deduplication_lock.store' => 'array',
            'media.deduplication_lock.seconds' => 10,
            'media.deduplication_lock.wait_seconds' => 1,
            'media.delete_files_on_media_delete' => true,
            'media.clean_empty_directories' => true,
            'media.max_file_size' => 10 * 1024 * 1024,
            'media.file_types' => [
                'svg' => 'image/svg+xml',
                'bmp' => 'image/bmp',
                'gif' => 'image/gif',
                'png' => 'image/png',
                'ico' => 'image/vnd.microsoft.icon',
                'jpeg' => 'image/jpeg',
                'jpg' => 'image/jpeg',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                'csv' => 'text/csv',
                'doc' => 'application/msword',
                'pdf' => 'application/pdf',
                'txt' => 'text/plain',
                'json' => 'application/json',
                'xml' => 'application/xml',
                'mp4' => 'video/mp4',
                'mp3' => 'audio/mpeg',
                'zip' => 'application/zip',
            ],
            'media.group_types' => [
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
