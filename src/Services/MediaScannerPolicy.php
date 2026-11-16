<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Exceptions\MediaUploadException;

/**
 * Validates scanner configuration before accepting an upload.
 *
 * @internal
 */
final readonly class MediaScannerPolicy
{
    /**
     * Create the scanner policy.
     */
    public function __construct(
        private MediaContentScanner $scanner,
    ) {}

    /**
     * Reject a required no-op scanner unless the consumer explicitly allows it.
     */
    public function assertReady(): void
    {
        if (! self::uploadsEnabled()) {
            throw new MediaUploadException('Untrusted Media uploads are disabled.');
        }
        $production = app()->isProduction();

        if ($this->scanner instanceof NullMediaContentScanner && ! self::permitsNoop($production)) {
            throw new MediaUploadException(
                'Media scanning is required but no production MediaContentScanner is bound.',
            );
        }
    }

    /** Determine whether materialized untrusted files may enter the ingestion pipeline. */
    public static function uploadsEnabled(): bool
    {
        return config('nvl-media.scanner.untrusted_uploads', true) !== false;
    }

    /** Use the production opt-out independently of the existing development bypass. */
    public static function permitsNoop(bool $production): bool
    {
        return $production
            ? config('nvl-media.scanner.allow_unscanned_production', false) === true
            : (! (bool) config('nvl-media.scanner.required', false) || config('nvl-media.scanner.allow_noop', false) === true);
    }
}
