<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

/** MediaMimeResolver: single-authority bidirectional lookup between file extensions and MIME types. */
final class MediaMimeResolver
{
    /** @var array<string, string> Extension → MIME type mapping */
    private const array EXTENSION_TO_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'bmp' => 'image/bmp',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'mp4' => 'video/mp4',
        'mpeg' => 'video/mpeg',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
    ];

    /** @var array<string, string> MIME type → preferred extension mapping (inverse, first-match wins) */
    private const array MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        'image/bmp' => 'bmp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'video/mp4' => 'mp4',
        'video/mpeg' => 'mpeg',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/aac' => 'aac',
        'audio/flac' => 'flac',
        'application/zip' => 'zip',
        'application/gzip' => 'gz',
    ];

    /**
     * Resolve a MIME type from a file extension.
     *
     * @param  string  $extension  File extension without leading dot
     * @return string MIME type, defaults to 'application/octet-stream' for unknown extensions
     */
    public static function extensionToMime(string $extension): string
    {
        return self::EXTENSION_TO_MIME[strtolower(ltrim($extension, '.'))] ?? 'application/octet-stream';
    }

    /**
     * Resolve a preferred file extension from a MIME type.
     *
     * @param  string  $mimeType  Full MIME type string
     * @return string File extension without dot, defaults to 'bin' for unknown MIME types
     */
    public static function mimeToExtension(string $mimeType): string
    {
        return self::MIME_TO_EXTENSION[strtolower($mimeType)] ?? 'bin';
    }
}
