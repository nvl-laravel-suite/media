<?php

declare(strict_types=1);

use Nvl\Media\Enums\MimeType;
use Nvl\Media\Support\MediaMimeResolver;

describe('MimeType Enum', function () {
    // ── Backed values ────────────────────────────────────────────────

    it('has correct MIME string values for image types', function () {
        expect(MimeType::Jpg->value)->toBe('image/jpeg')
            ->and(MimeType::Png->value)->toBe('image/png')
            ->and(MimeType::Webp->value)->toBe('image/webp')
            ->and(MimeType::Avif->value)->toBe('image/avif')
            ->and(MimeType::Gif->value)->toBe('image/gif')
            ->and(MimeType::Svg->value)->toBe('image/svg+xml')
            ->and(MimeType::Bmp->value)->toBe('image/bmp');
    });

    it('has correct MIME string values for video types', function () {
        expect(MimeType::Mp4->value)->toBe('video/mp4')
            ->and(MimeType::Webm->value)->toBe('video/webm')
            ->and(MimeType::Mov->value)->toBe('video/quicktime')
            ->and(MimeType::Mpeg->value)->toBe('video/mpeg');
    });

    it('has correct MIME string values for audio types', function () {
        expect(MimeType::Mp3->value)->toBe('audio/mpeg')
            ->and(MimeType::Wav->value)->toBe('audio/wav')
            ->and(MimeType::Ogg->value)->toBe('audio/ogg')
            ->and(MimeType::Aac->value)->toBe('audio/aac')
            ->and(MimeType::Flac->value)->toBe('audio/flac');
    });

    it('has correct MIME string values for document types', function () {
        expect(MimeType::Pdf->value)->toBe('application/pdf')
            ->and(MimeType::Csv->value)->toBe('text/csv')
            ->and(MimeType::Txt->value)->toBe('text/plain')
            ->and(MimeType::Json->value)->toBe('application/json')
            ->and(MimeType::Xml->value)->toBe('application/xml');
    });

    it('has correct MIME string values for archive types', function () {
        expect(MimeType::Zip->value)->toBe('application/zip')
            ->and(MimeType::Gz->value)->toBe('application/gzip');
    });

    // ── Group helpers ────────────────────────────────────────────────

    it('returns all image types from images()', function () {
        $images = MimeType::images();

        expect($images)->toHaveCount(7)
            ->and($images)->toContain(MimeType::Jpg, MimeType::Png, MimeType::Webp, MimeType::Avif, MimeType::Gif, MimeType::Svg, MimeType::Bmp);
    });

    it('returns raster images excluding SVG and GIF', function () {
        $raster = MimeType::rasterImages();

        expect($raster)->toHaveCount(5)
            ->and($raster)->toContain(MimeType::Jpg, MimeType::Png, MimeType::Webp, MimeType::Avif, MimeType::Bmp)
            ->and($raster)->not->toContain(MimeType::Svg, MimeType::Gif);
    });

    it('returns all video types from videos()', function () {
        $videos = MimeType::videos();

        expect($videos)->toHaveCount(4)
            ->and($videos)->toContain(MimeType::Mp4, MimeType::Webm, MimeType::Mov, MimeType::Mpeg);
    });

    it('returns all audio types from audio()', function () {
        $audio = MimeType::audio();

        expect($audio)->toHaveCount(5)
            ->and($audio)->toContain(MimeType::Mp3, MimeType::Wav, MimeType::Ogg, MimeType::Aac, MimeType::Flac);
    });

    it('returns all document types from documents()', function () {
        $docs = MimeType::documents();

        expect($docs)->toHaveCount(5)
            ->and($docs)->toContain(MimeType::Pdf, MimeType::Csv, MimeType::Txt, MimeType::Json, MimeType::Xml);
    });

    it('returns all archive types from archives()', function () {
        $archives = MimeType::archives();

        expect($archives)->toHaveCount(2)
            ->and($archives)->toContain(MimeType::Zip, MimeType::Gz);
    });

    // ── Instance checks ──────────────────────────────────────────────

    it('correctly identifies image types', function () {
        expect(MimeType::Jpg->isImage())->toBeTrue()
            ->and(MimeType::Svg->isImage())->toBeTrue()
            ->and(MimeType::Mp4->isImage())->toBeFalse()
            ->and(MimeType::Pdf->isImage())->toBeFalse();
    });

    it('correctly identifies video types', function () {
        expect(MimeType::Mp4->isVideo())->toBeTrue()
            ->and(MimeType::Webm->isVideo())->toBeTrue()
            ->and(MimeType::Jpg->isVideo())->toBeFalse()
            ->and(MimeType::Mp3->isVideo())->toBeFalse();
    });

    it('correctly identifies audio types', function () {
        expect(MimeType::Mp3->isAudio())->toBeTrue()
            ->and(MimeType::Flac->isAudio())->toBeTrue()
            ->and(MimeType::Mp4->isAudio())->toBeFalse()
            ->and(MimeType::Pdf->isAudio())->toBeFalse();
    });

    it('correctly identifies document types', function () {
        expect(MimeType::Pdf->isDocument())->toBeTrue()
            ->and(MimeType::Csv->isDocument())->toBeTrue()
            ->and(MimeType::Jpg->isDocument())->toBeFalse()
            ->and(MimeType::Zip->isDocument())->toBeFalse();
    });

    it('correctly identifies conversion support for raster images only', function () {
        expect(MimeType::Jpg->supportsConversion())->toBeTrue()
            ->and(MimeType::Webp->supportsConversion())->toBeTrue()
            ->and(MimeType::Svg->supportsConversion())->toBeFalse()
            ->and(MimeType::Gif->supportsConversion())->toBeFalse()
            ->and(MimeType::Mp4->supportsConversion())->toBeFalse()
            ->and(MimeType::Pdf->supportsConversion())->toBeFalse();
    });

    // ── Extension mapping ────────────────────────────────────────────

    it('returns correct extension for each case', function () {
        expect(MimeType::Jpg->extension())->toBe('jpg')
            ->and(MimeType::Png->extension())->toBe('png')
            ->and(MimeType::Webp->extension())->toBe('webp')
            ->and(MimeType::Avif->extension())->toBe('avif')
            ->and(MimeType::Gif->extension())->toBe('gif')
            ->and(MimeType::Svg->extension())->toBe('svg')
            ->and(MimeType::Mp4->extension())->toBe('mp4')
            ->and(MimeType::Mov->extension())->toBe('mov')
            ->and(MimeType::Mp3->extension())->toBe('mp3')
            ->and(MimeType::Pdf->extension())->toBe('pdf')
            ->and(MimeType::Zip->extension())->toBe('zip')
            ->and(MimeType::Gz->extension())->toBe('gz');
    });

    // ── Factory: fromExtension ───────────────────────────────────────

    it('resolves MimeType from extension', function () {
        expect(MimeType::fromExtension('jpg'))->toBe(MimeType::Jpg)
            ->and(MimeType::fromExtension('png'))->toBe(MimeType::Png)
            ->and(MimeType::fromExtension('webp'))->toBe(MimeType::Webp)
            ->and(MimeType::fromExtension('mp4'))->toBe(MimeType::Mp4)
            ->and(MimeType::fromExtension('pdf'))->toBe(MimeType::Pdf);
    });

    it('resolves jpeg alias to Jpg case', function () {
        expect(MimeType::fromExtension('jpeg'))->toBe(MimeType::Jpg);
    });

    it('handles leading dot in extension', function () {
        expect(MimeType::fromExtension('.jpg'))->toBe(MimeType::Jpg)
            ->and(MimeType::fromExtension('.pdf'))->toBe(MimeType::Pdf);
    });

    it('is case-insensitive for extension lookup', function () {
        expect(MimeType::fromExtension('JPG'))->toBe(MimeType::Jpg)
            ->and(MimeType::fromExtension('WEBP'))->toBe(MimeType::Webp)
            ->and(MimeType::fromExtension('Pdf'))->toBe(MimeType::Pdf);
    });

    it('returns null for unknown extension', function () {
        expect(MimeType::fromExtension('xyz'))->toBeNull()
            ->and(MimeType::fromExtension('exe'))->toBeNull()
            ->and(MimeType::fromExtension(''))->toBeNull();
    });

    // ── Factory: fromMimeString ──────────────────────────────────────

    it('resolves MimeType from MIME string', function () {
        expect(MimeType::fromMimeString('image/jpeg'))->toBe(MimeType::Jpg)
            ->and(MimeType::fromMimeString('image/png'))->toBe(MimeType::Png)
            ->and(MimeType::fromMimeString('video/mp4'))->toBe(MimeType::Mp4)
            ->and(MimeType::fromMimeString('application/pdf'))->toBe(MimeType::Pdf);
    });

    it('is case-insensitive for MIME string lookup', function () {
        expect(MimeType::fromMimeString('IMAGE/JPEG'))->toBe(MimeType::Jpg)
            ->and(MimeType::fromMimeString('Video/Mp4'))->toBe(MimeType::Mp4);
    });

    it('returns null for unknown MIME string', function () {
        expect(MimeType::fromMimeString('application/octet-stream'))->toBeNull()
            ->and(MimeType::fromMimeString('text/html'))->toBeNull()
            ->and(MimeType::fromMimeString(''))->toBeNull();
    });

    // ── toStrings helper ─────────────────────────────────────────────

    it('converts enum array to string values', function () {
        $strings = MimeType::toStrings([MimeType::Jpg, MimeType::Png, MimeType::Webp]);

        expect($strings)->toBe(['image/jpeg', 'image/png', 'image/webp']);
    });

    it('returns empty array for empty input', function () {
        expect(MimeType::toStrings([]))->toBe([]);
    });

    // ── Parity with MediaMimeResolver ────────────────────────────────

    it('extension output matches MediaMimeResolver for all shared types', function () {
        $resolver = MediaMimeResolver::class;

        foreach (MimeType::cases() as $case) {
            $resolverMime = $resolver::extensionToMime($case->extension());

            expect($resolverMime)->toBe($case->value, "MimeType::{$case->name}->extension() '{$case->extension()}' resolved to '{$resolverMime}' via MediaMimeResolver, expected '{$case->value}'");
        }
    });
});
