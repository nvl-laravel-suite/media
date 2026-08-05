<?php

declare(strict_types=1);

use Nvl\Media\Enums\MediaType;

/* =================================================================
 * MediaType Enum
 * ================================================================= */

describe('MediaType', function () {

    /* ---------------------------------------------------------------
     * fromExtension()
     * ------------------------------------------------------------- */

    describe('fromExtension', function () {

        beforeEach(function () {
            config(['media.group_types' => [
                'image' => ['svg', 'bmp', 'gif', 'png', 'ico', 'jpeg', 'jpg', 'webp', 'avif'],
                'document' => ['doc', 'docx', 'pdf', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt'],
                'video' => ['mp4', 'mpeg', 'webm', 'mov'],
                'audio' => ['mp3', 'wav', 'ogg', 'aac', 'flac'],
                'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
                'code' => ['json', 'xml'],
            ]]);
        });

        it('resolves image extensions', function () {
            expect(MediaType::fromExtension('jpg'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromExtension('png'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromExtension('gif'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromExtension('webp'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromExtension('avif'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromExtension('svg'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromExtension('bmp'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromExtension('ico'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromExtension('jpeg'))->toBe(MediaType::IMAGE);
        });

        it('resolves document extensions', function () {
            expect(MediaType::fromExtension('pdf'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::fromExtension('doc'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::fromExtension('docx'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::fromExtension('xls'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::fromExtension('xlsx'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::fromExtension('csv'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::fromExtension('txt'))->toBe(MediaType::DOCUMENT);
        });

        it('resolves video extensions', function () {
            expect(MediaType::fromExtension('mp4'))->toBe(MediaType::VIDEO);
            expect(MediaType::fromExtension('mpeg'))->toBe(MediaType::VIDEO);
            expect(MediaType::fromExtension('webm'))->toBe(MediaType::VIDEO);
            expect(MediaType::fromExtension('mov'))->toBe(MediaType::VIDEO);
        });

        it('resolves audio extensions', function () {
            expect(MediaType::fromExtension('mp3'))->toBe(MediaType::AUDIO);
            expect(MediaType::fromExtension('wav'))->toBe(MediaType::AUDIO);
            expect(MediaType::fromExtension('ogg'))->toBe(MediaType::AUDIO);
            expect(MediaType::fromExtension('aac'))->toBe(MediaType::AUDIO);
            expect(MediaType::fromExtension('flac'))->toBe(MediaType::AUDIO);
        });

        it('resolves archive extensions', function () {
            expect(MediaType::fromExtension('zip'))->toBe(MediaType::ARCHIVE);
            expect(MediaType::fromExtension('rar'))->toBe(MediaType::ARCHIVE);
            expect(MediaType::fromExtension('7z'))->toBe(MediaType::ARCHIVE);
            expect(MediaType::fromExtension('tar'))->toBe(MediaType::ARCHIVE);
            expect(MediaType::fromExtension('gz'))->toBe(MediaType::ARCHIVE);
        });

        it('resolves code extensions', function () {
            expect(MediaType::fromExtension('json'))->toBe(MediaType::CODE);
            expect(MediaType::fromExtension('xml'))->toBe(MediaType::CODE);
        });

        it('returns OTHER for unknown extensions', function () {
            expect(MediaType::fromExtension('xyz'))->toBe(MediaType::OTHER);
            expect(MediaType::fromExtension('abc'))->toBe(MediaType::OTHER);
            expect(MediaType::fromExtension('unknown'))->toBe(MediaType::OTHER);
        });

        it('handles leading dot in extension', function () {
            expect(MediaType::fromExtension('.jpg'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromExtension('.pdf'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::fromExtension('.mp4'))->toBe(MediaType::VIDEO);
        });

        it('is case-insensitive', function () {
            expect(MediaType::fromExtension('JPG'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromExtension('Pdf'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::fromExtension('MP4'))->toBe(MediaType::VIDEO);
            expect(MediaType::fromExtension('JSON'))->toBe(MediaType::CODE);
            expect(MediaType::fromExtension('ZIP'))->toBe(MediaType::ARCHIVE);
        });

        it('handles leading dot combined with uppercase', function () {
            expect(MediaType::fromExtension('.PNG'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromExtension('.CSV'))->toBe(MediaType::DOCUMENT);
        });

        it('returns OTHER when config has no group_types', function () {
            config(['media.group_types' => []]);

            expect(MediaType::fromExtension('jpg'))->toBe(MediaType::OTHER);
        });
    });

    /* ---------------------------------------------------------------
     * fromMimeType()
     * ------------------------------------------------------------- */

    describe('fromMimeType', function () {

        it('resolves image MIME types', function () {
            expect(MediaType::fromMimeType('image/jpeg'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromMimeType('image/png'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromMimeType('image/gif'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromMimeType('image/webp'))->toBe(MediaType::IMAGE);
            expect(MediaType::fromMimeType('image/svg+xml'))->toBe(MediaType::IMAGE);
        });

        it('resolves video MIME types', function () {
            expect(MediaType::fromMimeType('video/mp4'))->toBe(MediaType::VIDEO);
            expect(MediaType::fromMimeType('video/webm'))->toBe(MediaType::VIDEO);
            expect(MediaType::fromMimeType('video/quicktime'))->toBe(MediaType::VIDEO);
        });

        it('resolves audio MIME types', function () {
            expect(MediaType::fromMimeType('audio/mpeg'))->toBe(MediaType::AUDIO);
            expect(MediaType::fromMimeType('audio/wav'))->toBe(MediaType::AUDIO);
            expect(MediaType::fromMimeType('audio/ogg'))->toBe(MediaType::AUDIO);
        });

        it('resolves application archive MIME types', function () {
            expect(MediaType::fromMimeType('application/zip'))->toBe(MediaType::ARCHIVE);
            expect(MediaType::fromMimeType('application/vnd.rar'))->toBe(MediaType::ARCHIVE);
            expect(MediaType::fromMimeType('application/x-7z-compressed'))->toBe(MediaType::ARCHIVE);
            expect(MediaType::fromMimeType('application/x-tar'))->toBe(MediaType::ARCHIVE);
            expect(MediaType::fromMimeType('application/gzip'))->toBe(MediaType::ARCHIVE);
        });

        it('resolves application code MIME types', function () {
            expect(MediaType::fromMimeType('application/json'))->toBe(MediaType::CODE);
            expect(MediaType::fromMimeType('application/xml'))->toBe(MediaType::CODE);
        });

        it('resolves application/* fallback to DOCUMENT', function () {
            expect(MediaType::fromMimeType('application/pdf'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::fromMimeType('application/msword'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::fromMimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->toBe(MediaType::DOCUMENT);
        });

        it('resolves text/csv and text/plain as DOCUMENT', function () {
            expect(MediaType::fromMimeType('text/csv'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::fromMimeType('text/plain'))->toBe(MediaType::DOCUMENT);
        });

        it('resolves text/html as OTHER', function () {
            expect(MediaType::fromMimeType('text/html'))->toBe(MediaType::OTHER);
        });

        it('returns OTHER for completely unknown MIME types', function () {
            expect(MediaType::fromMimeType('something/weird'))->toBe(MediaType::OTHER);
            expect(MediaType::fromMimeType('x-custom/binary'))->toBe(MediaType::OTHER);
        });
    });

    /* ---------------------------------------------------------------
     * isVisual()
     * ------------------------------------------------------------- */

    describe('isVisual', function () {

        it('returns true for IMAGE', function () {
            expect(MediaType::IMAGE->isVisual())->toBeTrue();
        });

        it('returns true for VIDEO', function () {
            expect(MediaType::VIDEO->isVisual())->toBeTrue();
        });

        it('returns false for non-visual types', function () {
            expect(MediaType::DOCUMENT->isVisual())->toBeFalse();
            expect(MediaType::AUDIO->isVisual())->toBeFalse();
            expect(MediaType::ARCHIVE->isVisual())->toBeFalse();
            expect(MediaType::CODE->isVisual())->toBeFalse();
            expect(MediaType::OTHER->isVisual())->toBeFalse();
        });
    });

    /* ---------------------------------------------------------------
     * supportsConversions()
     * ------------------------------------------------------------- */

    describe('supportsConversions', function () {

        it('returns true only for IMAGE', function () {
            expect(MediaType::IMAGE->supportsConversions())->toBeTrue();
        });

        it('returns false for all other types', function () {
            expect(MediaType::DOCUMENT->supportsConversions())->toBeFalse();
            expect(MediaType::VIDEO->supportsConversions())->toBeFalse();
            expect(MediaType::AUDIO->supportsConversions())->toBeFalse();
            expect(MediaType::ARCHIVE->supportsConversions())->toBeFalse();
            expect(MediaType::CODE->supportsConversions())->toBeFalse();
            expect(MediaType::OTHER->supportsConversions())->toBeFalse();
        });
    });

    /* ---------------------------------------------------------------
     * Backing values
     * ------------------------------------------------------------- */

    describe('backing values', function () {

        it('has the correct string values for all cases', function () {
            expect(MediaType::IMAGE->value)->toBe('image');
            expect(MediaType::DOCUMENT->value)->toBe('document');
            expect(MediaType::VIDEO->value)->toBe('video');
            expect(MediaType::AUDIO->value)->toBe('audio');
            expect(MediaType::ARCHIVE->value)->toBe('archive');
            expect(MediaType::CODE->value)->toBe('code');
            expect(MediaType::OTHER->value)->toBe('other');
        });

        it('can be constructed with tryFrom for valid values', function () {
            expect(MediaType::tryFrom('image'))->toBe(MediaType::IMAGE);
            expect(MediaType::tryFrom('document'))->toBe(MediaType::DOCUMENT);
            expect(MediaType::tryFrom('video'))->toBe(MediaType::VIDEO);
        });

        it('returns null from tryFrom for invalid values', function () {
            expect(MediaType::tryFrom('invalid'))->toBeNull();
            expect(MediaType::tryFrom(''))->toBeNull();
        });
    });
});
