<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Contracts\MediaHostResolver;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaSourceResolver;
use Nvl\Media\Services\MediaTemporaryFileRegistry;

beforeEach(function () {
    config(['media.sources.remote.enabled' => true]);
    config(['media.sources.remote.verify_connected_ip' => false]);
    $this->resolver = app(MediaSourceResolver::class);
});

/* =================================================================
 * fromBase64
 * ================================================================= */

describe('fromBase64', function () {

    it('creates UploadedFile from valid base64 data', function () {
        $data = base64_encode('hello world');

        $file = $this->resolver->fromBase64($data);

        expect($file)->toBeInstanceOf(UploadedFile::class);
        expect(file_get_contents($file->getRealPath()))->toBe('hello world');
    });

    it('throws on invalid base64', function () {
        expect(fn () => $this->resolver->fromBase64('not-valid-base64!!!'))
            ->toThrow(MediaUploadException::class, 'Invalid base64 data');
    });

    it('preflights decoded base64 size before creating a temporary file', function () {
        config(['media.max_file_size' => 4]);
        $registry = app(MediaTemporaryFileRegistry::class);

        expect(fn () => $this->resolver->fromBase64(base64_encode('12345')))
            ->toThrow(MediaUploadException::class, 'exceeds the maximum allowed size');
        expect($registry->count())->toBe(0);
    });

    it('validates MIME types when provided', function () {
        $textData = base64_encode('just plain text');

        expect(fn () => $this->resolver->fromBase64($textData, 'image/jpeg'))
            ->toThrow(FileUnacceptableForCollection::class);
        expect(app(MediaTemporaryFileRegistry::class)->count())->toBe(0);
    });

    it('generates filename with extension from MIME', function () {
        $data = base64_encode('plain text content');

        $file = $this->resolver->fromBase64($data);

        expect($file->getClientOriginalName())->toContain('media-');
    });
});

/* =================================================================
 * fromString
 * ================================================================= */

describe('fromString', function () {

    it('creates text/plain UploadedFile', function () {
        $file = $this->resolver->fromString('hello world');

        expect($file)->toBeInstanceOf(UploadedFile::class);
        expect($file->getClientOriginalName())->toBe('text.txt');
        expect($file->getClientMimeType())->toBe('text/plain');
        expect(file_get_contents($file->getRealPath()))->toBe('hello world');
    });

    it('handles empty string', function () {
        $file = $this->resolver->fromString('');

        expect($file)->toBeInstanceOf(UploadedFile::class);
    });
});

/* =================================================================
 * fromDisk
 * ================================================================= */

describe('fromDisk', function () {

    it('reads file from disk into UploadedFile', function () {
        Storage::fake('local');
        Storage::disk('local')->put('test/file.txt', 'disk content');

        $file = $this->resolver->fromDisk('test/file.txt', 'local');

        expect($file)->toBeInstanceOf(UploadedFile::class);
        expect($file->getClientOriginalName())->toBe('file.txt');
        expect(file_get_contents($file->getRealPath()))->toBe('disk content');
    });

    it('throws when file not found on disk', function () {
        Storage::fake('local');

        expect(fn () => $this->resolver->fromDisk('nonexistent.txt', 'local'))
            ->toThrow(MediaUploadException::class, 'not found on disk');
    });

    it('streams disk imports through the configured byte bound and releases failures', function () {
        Storage::fake('local');
        Storage::disk('local')->put('too-large.txt', '12345');
        config(['media.max_file_size' => 4]);

        expect(fn () => $this->resolver->fromDisk('too-large.txt', 'local'))
            ->toThrow(MediaUploadException::class, 'exceeds the maximum allowed size');
        expect(app(MediaTemporaryFileRegistry::class)->count())->toBe(0);
    });
});

/* =================================================================
 * fromRequest
 * ================================================================= */

describe('fromRequest', function () {

    it('extracts an UploadedFile from an explicit request', function () {
        $uploadedFile = UploadedFile::fake()->image('avatar.jpg');
        $request = Request::create('/media', 'POST', files: [
            'avatar' => $uploadedFile,
        ]);

        $file = $this->resolver->fromRequest($request, 'avatar');

        expect($file)->toBe($uploadedFile);
    });

    it('throws when request key is missing', function () {
        $request = Request::create('/media', 'POST');

        expect(fn () => $this->resolver->fromRequest($request, 'nonexistent'))
            ->toThrow(MediaUploadException::class, 'No file found in request');
    });
});

/* =================================================================
 * fromUrl — SSRF validation
 * ================================================================= */

describe('fromUrl SSRF validation', function () {

    it('rejects private IP addresses', function () {
        expect(fn () => $this->resolver->fromUrl('http://192.168.1.1/file.jpg'))
            ->toThrow(MediaUploadException::class, 'private or reserved');
    });

    it('rejects localhost', function () {
        expect(fn () => $this->resolver->fromUrl('http://127.0.0.1/file.jpg'))
            ->toThrow(MediaUploadException::class, 'private or reserved');
    });

    it('rejects reserved IPv6 addresses', function () {
        expect(fn () => $this->resolver->fromUrl('http://[::1]/file.jpg'))
            ->toThrow(MediaUploadException::class, 'private or reserved');
    });

    it('rejects non-http schemes', function () {
        expect(fn () => $this->resolver->fromUrl('ftp://example.com/file.jpg'))
            ->toThrow(MediaUploadException::class, 'not allowed');
    });

    it('rejects invalid URLs', function () {
        expect(fn () => $this->resolver->fromUrl('not-a-url'))
            ->toThrow(MediaUploadException::class, 'Invalid URL');
    });

    it('rejects credentials embedded in remote URLs', function () {
        expect(fn () => $this->resolver->fromUrl('https://user:secret@93.184.216.34/file.jpg'))
            ->toThrow(MediaUploadException::class, 'Credentials are not allowed');
    });

    it('rejects ports outside the explicit remote allowlist', function () {
        expect(fn () => $this->resolver->fromUrl('https://93.184.216.34:8443/file.jpg'))
            ->toThrow(MediaUploadException::class, 'port [8443] is not allowed');
    });

    it('validates every resolved address from the injectable DNS boundary', function () {
        $resolver = new MediaSourceResolver(
            app(MediaDiskGateway::class),
            new class implements MediaHostResolver
            {
                public function resolve(string $host): array
                {
                    return ['93.184.216.34', '127.0.0.1'];
                }
            },
            app(MediaTemporaryFileRegistry::class),
        );

        expect(fn () => $resolver->fromUrl('https://uploads.example.test/file.jpg'))
            ->toThrow(MediaUploadException::class, 'private or reserved');
    });

    it('downloads from valid URL with Http::fake', function () {
        Http::fake([
            'https://93.184.216.34/photo.jpg' => Http::response('fake-image-content', 200),
        ]);

        $file = $this->resolver->fromUrl('https://93.184.216.34/photo.jpg');

        expect($file)->toBeInstanceOf(UploadedFile::class);
        expect($file->getClientOriginalName())->toBe('photo.jpg');
        expect(file_get_contents($file->getRealPath()))->toBe('fake-image-content');
    });

    it('fails closed when the transport cannot attest its connected IP', function () {
        config(['media.sources.remote.verify_connected_ip' => true]);
        Http::fake([
            'https://93.184.216.34/photo.jpg' => Http::response('fake-image-content', 200),
        ]);

        expect(fn () => $this->resolver->fromUrl('https://93.184.216.34/photo.jpg'))
            ->toThrow(MediaUploadException::class, 'could not attest its connected IP');
        expect(app(MediaTemporaryFileRegistry::class)->count())->toBe(0);
    });

    it('downloads through a safe public redirect chain', function () {
        Http::fake([
            'https://93.184.216.34/start.jpg' => Http::response('', 302, [
                'Location' => '/redirected/photo.jpg',
            ]),
            'https://93.184.216.34/redirected/photo.jpg' => Http::response('redirected-image-content', 200),
        ]);

        $file = $this->resolver->fromUrl('https://93.184.216.34/start.jpg');

        expect($file)->toBeInstanceOf(UploadedFile::class);
        expect($file->getClientOriginalName())->toBe('photo.jpg');
        expect(file_get_contents($file->getRealPath()))->toBe('redirected-image-content');
    });

    it('rejects redirects to private hosts', function () {
        Http::fake([
            'https://93.184.216.34/start.jpg' => Http::response('', 302, [
                'Location' => 'http://127.0.0.1/private.jpg',
            ]),
        ]);

        expect(fn () => $this->resolver->fromUrl('https://93.184.216.34/start.jpg'))
            ->toThrow(MediaUploadException::class, 'private or reserved');
    });

    it('rejects redirect loops', function () {
        Http::fake([
            'https://93.184.216.34/loop.jpg' => Http::response('', 302, [
                'Location' => '/loop.jpg',
            ]),
        ]);

        expect(fn () => $this->resolver->fromUrl('https://93.184.216.34/loop.jpg'))
            ->toThrow(MediaUploadException::class, 'Too many redirects');
    });

    it('rejects oversized downloads declared by content length', function () {
        config(['media.max_file_size' => 4]);

        Http::fake([
            'https://93.184.216.34/too-large.jpg' => Http::response('body', 200, [
                'Content-Length' => '5',
            ]),
        ]);

        expect(fn () => $this->resolver->fromUrl('https://93.184.216.34/too-large.jpg'))
            ->toThrow(MediaUploadException::class, 'exceeds the maximum allowed size');
    });

    it('rejects streamed bodies that exceed the max file size', function () {
        config(['media.max_file_size' => 4]);

        Http::fake([
            'https://93.184.216.34/stream-too-large.jpg' => Http::response('12345', 200),
        ]);

        expect(fn () => $this->resolver->fromUrl('https://93.184.216.34/stream-too-large.jpg'))
            ->toThrow(MediaUploadException::class, 'exceeds the maximum allowed size');
    });

    it('throws on HTTP error response', function () {
        Http::fake([
            'https://93.184.216.34/missing.jpg' => Http::response('Not Found', 404),
        ]);

        expect(fn () => $this->resolver->fromUrl('https://93.184.216.34/missing.jpg'))
            ->toThrow(MediaUploadException::class, 'Could not download');
    });

    it('validates MIME type on downloaded file', function () {
        Http::fake([
            'https://93.184.216.34/file.txt' => Http::response('plain text', 200),
        ]);

        expect(fn () => $this->resolver->fromUrl('https://93.184.216.34/file.txt', 'image/jpeg'))
            ->toThrow(FileUnacceptableForCollection::class);
    });
});
