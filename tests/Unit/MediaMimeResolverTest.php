<?php

declare(strict_types=1);

use Nvl\Media\Support\MediaMimeResolver;

/* =================================================================
 * extensionToMime
 * ================================================================= */

it('resolves common image extensions to MIME types', function (string $ext, string $expected) {
    expect(MediaMimeResolver::extensionToMime($ext))->toBe($expected);
})->with([
    ['jpg', 'image/jpeg'],
    ['jpeg', 'image/jpeg'],
    ['png', 'image/png'],
    ['gif', 'image/gif'],
    ['webp', 'image/webp'],
    ['avif', 'image/avif'],
    ['svg', 'image/svg+xml'],
    ['bmp', 'image/bmp'],
]);

it('resolves document extensions to MIME types', function (string $ext, string $expected) {
    expect(MediaMimeResolver::extensionToMime($ext))->toBe($expected);
})->with([
    ['pdf', 'application/pdf'],
    ['txt', 'text/plain'],
    ['csv', 'text/csv'],
    ['json', 'application/json'],
    ['xml', 'application/xml'],
]);

it('resolves video and audio extensions to MIME types', function (string $ext, string $expected) {
    expect(MediaMimeResolver::extensionToMime($ext))->toBe($expected);
})->with([
    ['mp4', 'video/mp4'],
    ['webm', 'video/webm'],
    ['mov', 'video/quicktime'],
    ['mp3', 'audio/mpeg'],
    ['wav', 'audio/wav'],
    ['ogg', 'audio/ogg'],
    ['flac', 'audio/flac'],
]);

it('returns octet-stream for unknown extensions', function () {
    expect(MediaMimeResolver::extensionToMime('xyz'))->toBe('application/octet-stream');
    expect(MediaMimeResolver::extensionToMime(''))->toBe('application/octet-stream');
});

it('handles uppercase and leading dots', function () {
    expect(MediaMimeResolver::extensionToMime('JPG'))->toBe('image/jpeg');
    expect(MediaMimeResolver::extensionToMime('.png'))->toBe('image/png');
    expect(MediaMimeResolver::extensionToMime('.WEBP'))->toBe('image/webp');
});

/* =================================================================
 * mimeToExtension
 * ================================================================= */

it('resolves common MIME types to extensions', function (string $mime, string $expected) {
    expect(MediaMimeResolver::mimeToExtension($mime))->toBe($expected);
})->with([
    ['image/jpeg', 'jpg'],
    ['image/png', 'png'],
    ['image/gif', 'gif'],
    ['image/webp', 'webp'],
    ['image/avif', 'avif'],
    ['image/svg+xml', 'svg'],
    ['application/pdf', 'pdf'],
    ['text/plain', 'txt'],
    ['video/mp4', 'mp4'],
    ['audio/mpeg', 'mp3'],
    ['application/zip', 'zip'],
]);

it('returns bin for unknown MIME types', function () {
    expect(MediaMimeResolver::mimeToExtension('application/x-unknown'))->toBe('bin');
    expect(MediaMimeResolver::mimeToExtension(''))->toBe('bin');
});

it('handles case-insensitive MIME types', function () {
    expect(MediaMimeResolver::mimeToExtension('IMAGE/JPEG'))->toBe('jpg');
    expect(MediaMimeResolver::mimeToExtension('Application/PDF'))->toBe('pdf');
});
