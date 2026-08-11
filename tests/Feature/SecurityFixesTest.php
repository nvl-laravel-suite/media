<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Http\Rules\AllowedMimeTypes;
use Nvl\Media\Http\Rules\MaxFileSize;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaDeduplicationLock;
use Nvl\Media\Tests\Stubs\TestMediaModel;

beforeEach(function () {
    Storage::fake('public');

    config([
        'filesystems.default' => 'public',
        'media.auto_generate_variations' => false,
        'media.auto_generate_conversions' => false,
        'media.allowed_disks' => ['public'],
        'media.sources.remote.enabled' => true,
        'media.sources.remote.verify_connected_ip' => false,
    ]);
});

function securityTestModel(array $overrides = []): TestMediaModel
{
    return TestMediaModel::create(array_merge([
        'name' => 'Security Test',
    ], $overrides));
}

/* =================================================================
 * SSRF Protection — addMediaFromUrl (Task 14)
 * ================================================================= */

describe('SSRF protection', function () {

    it('rejects non-http schemes', function () {
        $model = securityTestModel();

        $model->addMediaFromUrl('ftp://example.com/file.jpg');
    })->throws(MediaUploadException::class, 'URL scheme [ftp] is not allowed');

    it('rejects file:// scheme', function () {
        $model = securityTestModel();

        $model->addMediaFromUrl('file:///etc/passwd');
    })->throws(MediaUploadException::class);

    it('rejects invalid URLs', function () {
        $model = securityTestModel();

        $model->addMediaFromUrl('not-a-url');
    })->throws(MediaUploadException::class, 'Invalid URL');

    it('rejects URLs without host', function () {
        $model = securityTestModel();

        $model->addMediaFromUrl('http://');
    })->throws(MediaUploadException::class);

    it('rejects private IP 127.0.0.1', function () {
        $model = securityTestModel();

        $model->addMediaFromUrl('http://127.0.0.1/file.jpg');
    })->throws(MediaUploadException::class, 'private or reserved IP');

    it('rejects private IP 10.0.0.1', function () {
        $model = securityTestModel();

        $model->addMediaFromUrl('http://10.0.0.1/file.jpg');
    })->throws(MediaUploadException::class, 'private or reserved IP');

    it('rejects private IP 192.168.1.1', function () {
        $model = securityTestModel();

        $model->addMediaFromUrl('http://192.168.1.1/file.jpg');
    })->throws(MediaUploadException::class, 'private or reserved IP');

    it('rejects localhost', function () {
        $model = securityTestModel();

        $model->addMediaFromUrl('http://localhost/file.jpg');
    })->throws(MediaUploadException::class, 'private or reserved IP');

    it('rejects redirect targets that resolve to private IPs', function () {
        Http::fake([
            'https://93.184.216.34/photo.jpg' => Http::response('', 302, [
                'Location' => 'http://127.0.0.1/private.jpg',
            ]),
        ]);

        $model = securityTestModel();

        $model->addMediaFromUrl('https://93.184.216.34/photo.jpg');
    })->throws(MediaUploadException::class, 'private or reserved IP');

    it('rejects oversized remote files before creating media records', function () {
        config(['media.max_file_size' => 4]);
        Http::fake([
            'https://93.184.216.34/large.jpg' => Http::response('12345', 200),
        ]);

        $model = securityTestModel();

        try {
            $model->addMediaFromUrl('https://93.184.216.34/large.jpg');
        } finally {
            expect(Media::query()->count())->toBe(0);
        }
    })->throws(MediaUploadException::class, 'exceeds the maximum allowed size');

    it('accepts safe public redirects', function () {
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        Http::fake([
            'https://93.184.216.34/start.jpg' => Http::response('', 302, [
                'Location' => '/images/photo.jpg',
            ]),
            'https://93.184.216.34/images/photo.jpg' => Http::response($jpeg, 200),
        ]);

        $model = securityTestModel();

        $media = $model->addMediaFromUrl('https://93.184.216.34/start.jpg')
            ->withoutVariations()
            ->toCollection('gallery')
            ->slot('gallery');

        expect($media->filename)->toBe('photo.jpg')
            ->and(Media::query()->count())->toBe(1);
    });
});

/* =================================================================
 * MIME Validation — AllowedMimeTypes rule (Task 13)
 * ================================================================= */

describe('AllowedMimeTypes rule', function () {

    it('passes for allowed MIME type', function () {
        $rule = new AllowedMimeTypes(['image/jpeg', 'image/png']);
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $failed = false;

        $rule->validate('file', $file, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });

    it('fails for disallowed MIME type', function () {
        $rule = new AllowedMimeTypes(['image/png']);
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $message = '';

        $rule->validate('file', $file, function ($msg) use (&$message) {
            $message = $msg;
        });

        expect($message)->toContain('unsupported MIME type');
    });

    it('fails for non-file value', function () {
        $rule = new AllowedMimeTypes(['image/jpeg']);
        $message = '';

        $rule->validate('file', 'not-a-file', function ($msg) use (&$message) {
            $message = $msg;
        });

        expect($message)->toContain('must be a file');
    });

    it('falls back to config when no types specified', function () {
        config(['media.file_types' => ['image/jpeg']]);
        $rule = new AllowedMimeTypes;
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $failed = false;

        $rule->validate('file', $file, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });
});

/* =================================================================
 * MaxFileSize rule
 * ================================================================= */

describe('MaxFileSize rule', function () {

    it('passes for files under the limit', function () {
        $rule = new MaxFileSize(10 * 1024 * 1024);
        $file = UploadedFile::fake()->image('small.jpg', 10, 10);
        $failed = false;

        $rule->validate('file', $file, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });

    it('fails for non-file value', function () {
        $rule = new MaxFileSize;
        $message = '';

        $rule->validate('file', 'not-a-file', function ($msg) use (&$message) {
            $message = $msg;
        });

        expect($message)->toContain('must be a file');
    });
});

/* =================================================================
 * Duplicate Detection via digest (Task 19)
 * ================================================================= */

describe('duplicate detection', function () {

    it('returns existing media when digest matches', function () {
        $model = securityTestModel();
        $model->addMediaSlot('gallery')->publicReusable();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $first = $model->addMedia($file)
            ->withoutVariations()
            ->slot('gallery');

        // Create another model and upload the same file content
        $model2 = securityTestModel(['name' => 'Model Two']);
        $model2->addMediaSlot('gallery')->publicReusable();
        $file2 = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $second = $model2->addMedia($file2)
            ->withoutVariations()
            ->slot('gallery');

        // Both should resolve to the same Media record if content matches
        // Note: fake files have same content for same dimensions
        expect($second->id)->toBe($first->id);
    });

    it('creates distinct media rows when duplicate uploads are explicitly allowed', function () {
        $firstModel = securityTestModel(['name' => 'First Duplicate Owner']);
        $secondModel = securityTestModel(['name' => 'Second Duplicate Owner']);

        $firstModel->addMediaSlot('duplicate-gallery')->shared();
        $secondModel->addMediaSlot('duplicate-gallery')->shared();

        $first = $firstModel->addMedia(UploadedFile::fake()->createWithContent('shared.txt', 'same-content'))
            ->allowingDuplicates()
            ->withoutVariations()
            ->slot('duplicate-gallery');

        $second = $secondModel->addMedia(UploadedFile::fake()->createWithContent('shared.txt', 'same-content'))
            ->allowingDuplicates()
            ->withoutVariations()
            ->slot('duplicate-gallery');

        expect($second->id)->not->toBe($first->id)
            ->and(Media::query()->where('digest', $first->digest)->count())->toBe(2);
    });

    it('maps deduplication lock timeouts to media upload failures', function () {
        config(['media.deduplication_lock.wait_seconds' => 0]);

        $media = Media::create([
            'filename' => 'locked.jpg',
            'hash' => 'locked.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'disk' => 'public',
            'folder' => 'test',
            'is_public' => true,
            'type' => MediaType::IMAGE,
            'digest' => hash('sha256', 'locked-content'),
        ]);

        $lock = app(MediaDeduplicationLock::class);

        $result = $lock->execute(
            $media->digest,
            $media->disk,
            $media->is_public,
            $media->uploaded_by,
            $media->uploaded_by_type,
            function () use ($lock, $media): Media {
                expect(fn () => $lock->execute(
                    $media->digest,
                    $media->disk,
                    $media->is_public,
                    $media->uploaded_by,
                    $media->uploaded_by_type,
                    fn (): Media => $media,
                ))->toThrow(MediaUploadException::class, 'Timed out while waiting for a media deduplication lock.');

                return $media;
            },
        );

        expect($result->id)->toBe($media->id);
    });
});

/* =================================================================
 * MediaAdder MIME validation uses content detection (Task 13)
 * ================================================================= */

describe('MediaAdder MIME validation', function () {

    it('rejects file with wrong MIME via content inspection', function () {
        $model = securityTestModel();
        $model->addMediaSlot('images')
            ->acceptsMimeTypes(['image/png']);

        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $model->addMedia($file)
            ->withoutVariations()
            ->slot('images');
    })->throws(FileUnacceptableForCollection::class);
});

/* =================================================================
 * Temporary URL crash protection (Task 22)
 * ================================================================= */

describe('getTemporaryUrl crash protection', function () {

    it('falls back to regular URL on local disk', function () {
        $model = securityTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->asPublic()
            ->withoutVariations()
            ->slot('gallery');

        // Local disk doesn't support temporaryUrl — should fall back gracefully
        $url = $media->getTemporaryUrl(now()->addMinutes(5));

        expect($url)->toBeString()
            ->and($url)->not->toBeEmpty();
    });
});

/* =================================================================
 * Base64 and string media sources
 * ================================================================= */

describe('addMediaFromBase64', function () {

    it('creates media from valid base64 data', function () {
        $model = securityTestModel();

        // Create a tiny valid PNG
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $png_data = ob_get_clean();
        imagedestroy($image);

        $base64 = base64_encode($png_data);

        $media = $model->addMediaFromBase64($base64)
            ->withoutVariations()
            ->slot('gallery');

        expect($media)->toBeInstanceOf(Media::class)
            ->and($media->mime_type)->toBe('image/png');
    });

    it('rejects invalid base64 data', function () {
        $model = securityTestModel();

        $model->addMediaFromBase64('!!!not-valid-base64!!!');
    })->throws(MediaUploadException::class, 'Invalid base64 data');

    it('rejects disallowed MIME types', function () {
        $model = securityTestModel();

        // Create a tiny valid PNG
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $png_data = ob_get_clean();
        imagedestroy($image);

        $base64 = base64_encode($png_data);

        $model->addMediaFromBase64($base64, 'image/jpeg');
    })->throws(FileUnacceptableForCollection::class);
});

describe('addMediaFromString', function () {

    it('creates media from string content', function () {
        $model = securityTestModel();

        $media = $model->addMediaFromString('Hello, World!')
            ->withoutVariations()
            ->slot('docs');

        expect($media)->toBeInstanceOf(Media::class)
            ->and($media->mime_type)->toBe('text/plain');
    });
});

describe('addMediaFromDisk', function () {

    it('creates media from file on another disk', function () {
        Storage::fake('source');
        Storage::disk('source')->put('imports/data.txt', 'Test file content');

        $model = securityTestModel();

        $media = $model->addMediaFromDisk('imports/data.txt', 'source')
            ->withoutVariations()
            ->slot('imports');

        expect($media)->toBeInstanceOf(Media::class)
            ->and($media->filename)->toBe('data.txt');
    });

    it('throws when file not found on disk', function () {
        Storage::fake('source');

        $model = securityTestModel();

        $model->addMediaFromDisk('nonexistent.txt', 'source');
    })->throws(MediaUploadException::class);
});
