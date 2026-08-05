<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Data\Ingestion\MediaFileTypeData;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Support\FileNameSanitizer;
use Nvl\Media\Support\MediaMimeResolver;

/**
 * Resolves canonical extensions and MIME types while rejecting dangerous filename claims.
 */
final class MediaFileTypePolicy
{
    /**
     * Extensions that must never appear in a caller-controlled media filename.
     *
     * @var list<string>
     */
    private const array DANGEROUS_EXTENSIONS = [
        'asp',
        'aspx',
        'asa',
        'ascx',
        'ashx',
        'bat',
        'bash',
        'cer',
        'cgi',
        'cfc',
        'cfm',
        'cmd',
        'com',
        'dll',
        'exe',
        'fcgi',
        'htaccess',
        'jar',
        'jsp',
        'jspx',
        'phar',
        'php',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'phps',
        'phpt',
        'pht',
        'phtm',
        'phtml',
        'pl',
        'py',
        'rb',
        'shtml',
        'sh',
        'war',
    ];

    /**
     * Resolve trusted type metadata from a display filename and detected MIME type.
     *
     * @throws FileUnacceptableForCollection
     */
    public function resolve(string $filename, string $detectedMimeType): MediaFileTypeData
    {
        $displayFilename = $this->sanitizeBasename($filename);
        $mimeType = $this->normalizeMimeType($detectedMimeType);
        $configuredTypes = $this->configuredTypes();

        if ($displayFilename === '') {
            throw new FileUnacceptableForCollection('Uploaded media requires a valid filename.');
        }

        $this->assertNoDangerousExtension($displayFilename);

        $extension = mb_strtolower(pathinfo($displayFilename, PATHINFO_EXTENSION));

        if ($extension === '') {
            $extension = $this->canonicalExtensionForMime($mimeType, $configuredTypes);
            $displayFilename .= '.'.$extension;
        }

        if (! isset($configuredTypes[$extension])) {
            throw new FileUnacceptableForCollection(
                "File extension [{$extension}] is not enabled by the media package.",
            );
        }

        if (! in_array($mimeType, $configuredTypes[$extension], true)) {
            throw new FileUnacceptableForCollection(
                "Detected MIME type [{$mimeType}] does not match file extension [{$extension}].",
            );
        }

        $baseName = pathinfo($displayFilename, PATHINFO_FILENAME);

        if ($baseName === '' || mb_strlen($extension) > 10) {
            throw new FileUnacceptableForCollection('Uploaded media requires a valid filename and extension.');
        }

        return new MediaFileTypeData(
            displayFilename: $baseName.'.'.$extension,
            extension: $extension,
            mimeType: $mimeType,
            type: MediaType::fromExtension($extension),
        );
    }

    /**
     * Return the normalized configured extension-to-MIME allowlist.
     *
     * @return array<string, list<string>>
     *
     * @throws FileUnacceptableForCollection
     */
    public function configuredTypes(): array
    {
        $configured = config('media.file_types', []);

        if (! is_array($configured)) {
            throw new FileUnacceptableForCollection('Media file type configuration must be an array.');
        }

        $normalized = [];

        foreach ($configured as $extension => $mimeTypes) {
            if (is_int($extension) && is_string($mimeTypes)) {
                $extension = MediaMimeResolver::mimeToExtension($mimeTypes);
            }

            if (! is_string($extension) || $extension === '') {
                continue;
            }

            $extension = mb_strtolower(ltrim($extension, '.'));
            $values = is_array($mimeTypes) ? $mimeTypes : [$mimeTypes];

            foreach ($values as $mimeType) {
                if (! is_string($mimeType) || trim($mimeType) === '') {
                    continue;
                }

                $normalized[$extension][] = $this->normalizeMimeType($mimeType);
            }

            if (isset($normalized[$extension])) {
                $normalized[$extension] = array_values(array_unique($normalized[$extension]));
            }
        }

        if ($normalized === []) {
            throw new FileUnacceptableForCollection('No media file types are enabled.');
        }

        return $normalized;
    }

    /**
     * Sanitize a caller-provided filename without retaining path components.
     */
    private function sanitizeBasename(string $filename): string
    {
        $filename = str_replace('\\', '/', trim($filename));

        return FileNameSanitizer::sanitize(basename($filename));
    }

    /**
     * Normalize a MIME type to its lowercase media type without parameters.
     */
    private function normalizeMimeType(string $mimeType): string
    {
        $mimeType = explode(';', mb_strtolower(trim($mimeType)), 2)[0];

        return trim($mimeType);
    }

    /**
     * Reject executable or server-handled suffixes anywhere in the filename.
     *
     * @throws FileUnacceptableForCollection
     */
    private function assertNoDangerousExtension(string $filename): void
    {
        $segments = array_slice(explode('.', mb_strtolower($filename)), 1);

        foreach ($segments as $segment) {
            if (in_array($segment, self::DANGEROUS_EXTENSIONS, true)
                || preg_match('/^php[0-9]+$/', $segment) === 1) {
                throw new FileUnacceptableForCollection(
                    "File extension [{$segment}] is forbidden for media uploads.",
                );
            }
        }
    }

    /**
     * Resolve the first configured canonical extension for a detected MIME type.
     *
     * @param  array<string, list<string>>  $configuredTypes
     *
     * @throws FileUnacceptableForCollection
     */
    private function canonicalExtensionForMime(string $mimeType, array $configuredTypes): string
    {
        $preferredExtension = MediaMimeResolver::mimeToExtension($mimeType);

        if (isset($configuredTypes[$preferredExtension])
            && in_array($mimeType, $configuredTypes[$preferredExtension], true)) {
            return $preferredExtension;
        }

        foreach ($configuredTypes as $extension => $mimeTypes) {
            if (in_array($mimeType, $mimeTypes, true)) {
                return $extension;
            }
        }

        throw new FileUnacceptableForCollection(
            "Detected MIME type [{$mimeType}] is not enabled by the media package.",
        );
    }
}
