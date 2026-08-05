<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\DiskNotDefinedException;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaFileOperator;
use Nvl\Media\Services\MediaLocalFileMaterializer;
use Nvl\Media\Services\MediaTemporaryFileRegistry;

function mediaFileOperator(): MediaFileOperator
{
    return app(MediaFileOperator::class);
}

function mediaFileExistence(): MediaFileExistence
{
    return app(MediaFileExistence::class);
}

function mediaDiskGateway(): MediaDiskGateway
{
    return app(MediaDiskGateway::class);
}

function mediaLocalFileMaterializer(): MediaLocalFileMaterializer
{
    return app(MediaLocalFileMaterializer::class);
}

describe('MediaFileOperator', function () {
    it('stores an UploadedFile on disk', function () {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $result = mediaFileOperator()->store($file, 'public', 'uploads', 'photo.jpg');

        expect($result)->not->toBeFalse();
        Storage::disk('public')->assertExists('uploads/photo.jpg');
    });

    it('stores string content on disk', function () {
        Storage::fake('public');

        $result = mediaFileOperator()->store('file contents here', 'public', 'uploads', 'data.txt');

        expect($result)->toBe('uploads/data.txt');
        Storage::disk('public')->assertExists('uploads/data.txt');
    });

    it('stores raw contents at the given path and invalidates existence cache', function () {
        Storage::fake('public');
        config(['media.cache_file_existence' => true, 'media.cache_ttl' => 60]);

        expect(mediaFileExistence()->exists('public', 'test/file.txt'))->toBeFalse();

        $result = mediaFileOperator()->put('public', 'test/file.txt', 'hello world');

        expect($result)->toBeTrue()
            ->and(mediaFileExistence()->exists('public', 'test/file.txt'))->toBeTrue()
            ->and(Storage::disk('public')->get('test/file.txt'))->toBe('hello world');
    });

    it('removes a file from disk and invalidates existence cache', function () {
        Storage::fake('public');
        config(['media.cache_file_existence' => true, 'media.cache_ttl' => 60]);

        Storage::disk('public')->put('uploads/photo.jpg', 'content');

        expect(mediaFileExistence()->exists('public', 'uploads/photo.jpg'))->toBeTrue();

        $result = mediaFileOperator()->delete('public', 'uploads/photo.jpg');

        expect($result)->toBeTrue()
            ->and(mediaFileExistence()->exists('public', 'uploads/photo.jpg'))->toBeFalse();
        Storage::disk('public')->assertMissing('uploads/photo.jpg');
    });

    it('cleans empty local directories when config enabled', function () {
        Storage::fake('public');
        config(['media.clean_empty_directories' => true]);

        Storage::disk('public')->put('uploads/subdir/file.txt', 'content');

        mediaFileOperator()->delete('public', 'uploads/subdir/file.txt');

        expect(Storage::disk('public')->exists('uploads/subdir'))->toBeFalse();
    });

    it('preserves non-empty directories', function () {
        Storage::fake('public');
        config(['media.clean_empty_directories' => true]);

        Storage::disk('public')->put('uploads/file1.txt', 'content1');
        Storage::disk('public')->put('uploads/file2.txt', 'content2');

        mediaFileOperator()->delete('public', 'uploads/file1.txt');

        Storage::disk('public')->assertExists('uploads/file2.txt');
    });

    it('moves file on same disk', function () {
        Storage::fake('public');
        Storage::disk('public')->put('old/file.txt', 'content');

        $result = mediaFileOperator()->move('public', 'old/file.txt', 'public', 'new/file.txt');

        expect($result)->toBeTrue();
        Storage::disk('public')->assertMissing('old/file.txt');
        Storage::disk('public')->assertExists('new/file.txt');
    });

    it('moves file across disks using streams', function () {
        Storage::fake('public');
        Storage::fake('cloudflare-r2');
        config(['filesystems.disks.cloudflare-r2.driver' => 's3']);

        Storage::disk('public')->put('source/file.txt', 'content');

        $result = mediaFileOperator()->move('public', 'source/file.txt', 'cloudflare-r2', 'dest/file.txt');

        expect($result)->toBeTrue();
        Storage::disk('public')->assertMissing('source/file.txt');
        Storage::disk('cloudflare-r2')->assertExists('dest/file.txt');
    });

    it('copies file between disks using streams', function () {
        Storage::fake('public');
        Storage::fake('cloudflare-r2');
        config(['filesystems.disks.cloudflare-r2.driver' => 's3']);

        Storage::disk('public')->put('source/file.txt', 'content');

        $result = mediaFileOperator()->copy('public', 'source/file.txt', 'cloudflare-r2', 'dest/file.txt');

        expect($result)->toBeTrue();
        Storage::disk('public')->assertExists('source/file.txt');
        Storage::disk('cloudflare-r2')->assertExists('dest/file.txt');
    });

    it('returns false when source file cannot be streamed', function () {
        Storage::fake('public');
        Storage::fake('cloudflare-r2');

        $result = mediaFileOperator()->copy('public', 'nonexistent.txt', 'cloudflare-r2', 'dest.txt');

        expect($result)->toBeFalse();
    });

    it('creates nested directories on local disks', function () {
        Storage::fake('public');

        mediaFileOperator()->ensureDirectoryExists('public', 'new/nested/dir');

        expect(Storage::disk('public')->exists('new/nested/dir'))->toBeTrue();
    });

    it('skips empty directory strings', function () {
        Storage::fake('public');

        mediaFileOperator()->ensureDirectoryExists('public', '');
        mediaFileOperator()->ensureDirectoryExists('public', '.');

        expect(true)->toBeTrue();
    });

    it('does not create folder-marker objects on S3-compatible disks', function () {
        Storage::fake('s3');
        config(['filesystems.disks.s3.driver' => 's3']);

        mediaFileOperator()->ensureDirectoryExists('s3', 'empty/folder');

        Storage::disk('s3')->assertMissing('empty/folder');
    });

    it('keeps S3 objects private at rest unless ACL visibility is explicitly enabled', function () {
        config(['filesystems.disks.s3.driver' => 's3']);
        $adapter = Mockery::mock(FilesystemAdapter::class);
        Storage::shouldReceive('disk')->twice()->with('s3')->andReturn($adapter);
        $adapter->shouldReceive('put')
            ->once()
            ->with('media/public.txt', 'public', [])
            ->andReturnTrue();
        $adapter->shouldReceive('put')
            ->once()
            ->with('media/acl-public.txt', 'public', ['visibility' => 'public'])
            ->andReturnTrue();

        mediaFileOperator()->put('s3', 'media/public.txt', 'public', MediaVisibility::Public);

        config(['media.s3.use_acl_visibility' => true]);
        mediaFileOperator()->put('s3', 'media/acl-public.txt', 'public', MediaVisibility::Public);
    });
});

describe('MediaFileExistence', function () {
    it('returns true when file exists', function () {
        Storage::fake('public');
        Storage::disk('public')->put('test/file.txt', 'content');
        config(['media.cache_file_existence' => false]);

        expect(mediaFileExistence()->exists('public', 'test/file.txt'))->toBeTrue();
    });

    it('returns false when file does not exist', function () {
        Storage::fake('public');
        config(['media.cache_file_existence' => false]);

        expect(mediaFileExistence()->exists('public', 'nonexistent.txt'))->toBeFalse();
    });

    it('caches result when caching is enabled', function () {
        Storage::fake('public');
        Storage::disk('public')->put('cached/file.txt', 'content');
        config(['media.cache_file_existence' => true, 'media.cache_ttl' => 60]);

        expect(mediaFileExistence()->exists('public', 'cached/file.txt'))->toBeTrue();

        Storage::disk('public')->delete('cached/file.txt');

        expect(mediaFileExistence()->exists('public', 'cached/file.txt'))->toBeTrue();
    });

    it('treats the configured cache ttl as seconds', function () {
        Storage::fake('public');
        Storage::disk('public')->put('ttl/file.txt', 'content');
        config(['media.cache_file_existence' => true, 'media.cache_ttl' => 1]);

        expect(mediaFileExistence()->exists('public', 'ttl/file.txt'))->toBeTrue();

        Storage::disk('public')->delete('ttl/file.txt');
        $this->travel(2)->seconds();

        expect(mediaFileExistence()->exists('public', 'ttl/file.txt'))->toBeFalse();
    });

    it('supports an authoritative existence check that bypasses cached state', function () {
        Storage::fake('public');
        Storage::disk('public')->put('fresh/file.txt', 'content');
        config(['media.cache_file_existence' => true, 'media.cache_ttl' => 60]);

        expect(mediaFileExistence()->exists('public', 'fresh/file.txt'))->toBeTrue();

        Storage::disk('public')->delete('fresh/file.txt');

        expect(mediaFileExistence()->exists('public', 'fresh/file.txt'))->toBeTrue()
            ->and(mediaFileExistence()->existsFresh('public', 'fresh/file.txt'))->toBeFalse();
    });

    it('skips cache when caching is disabled', function () {
        Storage::fake('public');
        Storage::disk('public')->put('nocache/file.txt', 'content');
        config(['media.cache_file_existence' => false]);

        expect(mediaFileExistence()->exists('public', 'nocache/file.txt'))->toBeTrue();

        Storage::disk('public')->delete('nocache/file.txt');

        expect(mediaFileExistence()->exists('public', 'nocache/file.txt'))->toBeFalse();
    });
});

describe('MediaDiskGateway', function () {
    it('validates configured disks', function () {
        expect(mediaDiskGateway()->ensureDefined('local'))->toBeTrue();
    });

    it('throws for undefined disks', function () {
        mediaDiskGateway()->ensureDefined('nonexistent_disk_xyz');
    })->throws(DiskNotDefinedException::class);

    it('returns file metadata and contents', function () {
        Storage::fake('public');
        Storage::disk('public')->put('test.txt', 'hello world');

        expect(mediaDiskGateway()->size('public', 'test.txt'))->toBe(11)
            ->and(mediaDiskGateway()->get('public', 'test.txt'))->toBe('hello world');
    });

    it('rejects local path resolution for remote disks', function () {
        config([
            'filesystems.disks.cloudflare-r2.driver' => 's3',
        ]);

        mediaDiskGateway()->localPath('cloudflare-r2', 'media/file.jpg');
    })->throws(RuntimeException::class, 'does not support local path resolution');

    it('identifies S3-compatible disks by configured driver', function () {
        config(['filesystems.disks.assets.driver' => 's3']);

        expect(mediaDiskGateway()->isS3('assets'))->toBeTrue()
            ->and(mediaDiskGateway()->driver('assets'))->toBe('s3');
    });
});

describe('MediaLocalFileMaterializer', function () {
    it('returns the native path for local disks', function () {
        Storage::fake('public');
        Storage::disk('public')->put('media/file.txt', 'content');

        $path = mediaLocalFileMaterializer()->materialize('public', 'media/file.txt');

        expect($path)->toBe(Storage::disk('public')->path('media/file.txt'));
    });

    it('leases and releases remote files as temporary local paths', function () {
        Storage::fake('cloudflare-r2');
        config(['filesystems.disks.cloudflare-r2.driver' => 's3']);
        Storage::disk('cloudflare-r2')->put('media/file.txt', 'remote content');

        $lease = mediaLocalFileMaterializer()->lease('cloudflare-r2', 'media/file.txt');
        $path = $lease->path();

        expect(is_file($path))->toBeTrue()
            ->and(file_get_contents($path))->toBe('remote content');

        $lease->release();

        expect(is_file($path))->toBeFalse();
    });

    it('tracks materialized remote files for request cleanup', function () {
        Storage::fake('cloudflare-r2');
        config(['filesystems.disks.cloudflare-r2.driver' => 's3']);
        Storage::disk('cloudflare-r2')->put('media/file.txt', 'remote content');

        $path = mediaLocalFileMaterializer()->materialize('cloudflare-r2', 'media/file.txt');

        expect(is_file($path))->toBeTrue();

        app(MediaTemporaryFileRegistry::class)->releaseAll();

        expect(is_file($path))->toBeFalse();
    });
});
