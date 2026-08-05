<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * MimeType: developer-facing enum for common MIME types used in media slot
 * configuration, upload validation, and format conversion.
 *
 * Provides type-safe MIME references, grouping helpers for slot constraints,
 * and bidirectional factory methods for extension/string lookups.
 */
#[TypeScript]
enum MimeType: string
{
    // ── Images ───────────────────────────────────────────────────────
    case Jpg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Avif = 'image/avif';
    case Gif = 'image/gif';
    case Svg = 'image/svg+xml';
    case Bmp = 'image/bmp';

    // ── Video ────────────────────────────────────────────────────────
    case Mp4 = 'video/mp4';
    case Webm = 'video/webm';
    case Mov = 'video/quicktime';
    case Mpeg = 'video/mpeg';

    // ── Audio ────────────────────────────────────────────────────────
    case Mp3 = 'audio/mpeg';
    case Wav = 'audio/wav';
    case Ogg = 'audio/ogg';
    case Aac = 'audio/aac';
    case Flac = 'audio/flac';

    // ── Documents ────────────────────────────────────────────────────
    case Pdf = 'application/pdf';
    case Csv = 'text/csv';
    case Txt = 'text/plain';
    case Json = 'application/json';
    case Xml = 'application/xml';

    // ── Archives ─────────────────────────────────────────────────────
    case Zip = 'application/zip';
    case Gz = 'application/gzip';

    // ── Group helpers ────────────────────────────────────────────────

    /**
     * All image MIME types including vector (SVG) and animated (GIF).
     *
     * @return array<int, self>
     */
    public static function images(): array
    {
        return [self::Jpg, self::Png, self::Webp, self::Avif, self::Gif, self::Svg, self::Bmp];
    }

    /**
     * Raster image types that support pixel-level conversions (excludes SVG and GIF).
     *
     * @return array<int, self>
     */
    public static function rasterImages(): array
    {
        return [self::Jpg, self::Png, self::Webp, self::Avif, self::Bmp];
    }

    /**
     * All video MIME types.
     *
     * @return array<int, self>
     */
    public static function videos(): array
    {
        return [self::Mp4, self::Webm, self::Mov, self::Mpeg];
    }

    /**
     * All audio MIME types.
     *
     * @return array<int, self>
     */
    public static function audio(): array
    {
        return [self::Mp3, self::Wav, self::Ogg, self::Aac, self::Flac];
    }

    /**
     * Document MIME types (PDF, CSV, plain text, structured data).
     *
     * @return array<int, self>
     */
    public static function documents(): array
    {
        return [self::Pdf, self::Csv, self::Txt, self::Json, self::Xml];
    }

    /**
     * Archive/compressed MIME types.
     *
     * @return array<int, self>
     */
    public static function archives(): array
    {
        return [self::Zip, self::Gz];
    }

    // ── Instance checks ──────────────────────────────────────────────

    /**
     * Whether this MIME type is an image format.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->value, 'image/');
    }

    /**
     * Whether this MIME type is a video format.
     */
    public function isVideo(): bool
    {
        return str_starts_with($this->value, 'video/');
    }

    /**
     * Whether this MIME type is an audio format.
     */
    public function isAudio(): bool
    {
        return str_starts_with($this->value, 'audio/');
    }

    /**
     * Whether this MIME type is a document format.
     */
    public function isDocument(): bool
    {
        return in_array($this, self::documents(), true);
    }

    /**
     * Whether this MIME type supports image conversions (raster images only).
     */
    public function supportsConversion(): bool
    {
        return in_array($this, self::rasterImages(), true);
    }

    /**
     * Whether this MIME type can be raster-optimized (resize, quality, format convert).
     *
     * True for pixel-based formats (JPG, PNG, BMP, WebP, AVIF).
     * False for vector (SVG), animated (GIF), and non-image types.
     */
    public function isRasterOptimizable(): bool
    {
        return in_array($this, self::rasterImages(), true);
    }

    /**
     * Whether converting from this type to the target would be a lossy re-encode with no benefit.
     *
     * Re-encoding WebP→WebP or JPG→JPG degrades quality without reducing size.
     * Cross-format conversions (JPG→WebP, PNG→AVIF) are always beneficial.
     *
     * @param  self  $target  Target format to convert to
     * @return bool True when source and target are the same lossy format
     */
    public function isLossyReEncode(self $target): bool
    {
        return $this === $target;
    }

    // ── Conversion helpers ───────────────────────────────────────────

    /**
     * Preferred file extension for this MIME type (without leading dot).
     */
    public function extension(): string
    {
        return match ($this) {
            self::Jpg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Avif => 'avif',
            self::Gif => 'gif',
            self::Svg => 'svg',
            self::Bmp => 'bmp',
            self::Mp4 => 'mp4',
            self::Webm => 'webm',
            self::Mov => 'mov',
            self::Mpeg => 'mpeg',
            self::Mp3 => 'mp3',
            self::Wav => 'wav',
            self::Ogg => 'ogg',
            self::Aac => 'aac',
            self::Flac => 'flac',
            self::Pdf => 'pdf',
            self::Csv => 'csv',
            self::Txt => 'txt',
            self::Json => 'json',
            self::Xml => 'xml',
            self::Zip => 'zip',
            self::Gz => 'gz',
        };
    }

    // ── Factory methods ──────────────────────────────────────────────

    /**
     * Resolve a MimeType from a file extension string.
     *
     * @param  string  $extension  File extension with or without leading dot
     * @return self|null Null if extension is unknown
     */
    public static function fromExtension(string $extension): ?self
    {
        $ext = strtolower(ltrim($extension, '.'));

        // Handle jpeg alias
        if ($ext === 'jpeg') {
            return self::Jpg;
        }

        foreach (self::cases() as $case) {
            if ($case->extension() === $ext) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Resolve a MimeType from a raw MIME string.
     *
     * @param  string  $mimeString  Full MIME type string (e.g. 'image/jpeg')
     * @return self|null Null if MIME string is unknown
     */
    public static function fromMimeString(string $mimeString): ?self
    {
        return self::tryFrom(strtolower($mimeString));
    }

    /**
     * Extract MIME type string values from an array of MimeType enums.
     *
     * @param  array<int, self>  $types  Enum cases to convert
     * @return array<int, string> Raw MIME type strings
     */
    public static function toStrings(array $types): array
    {
        return array_map(static fn (self $type): string => $type->value, $types);
    }
}
