<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Translatable\Services\ContentLocale;

/**
 * Provides a Media-facing adapter to the shared content-locale registry.
 */
final readonly class MediaLocaleResolver
{
    /**
     * Create the media locale adapter.
     */
    public function __construct(
        private ContentLocale $contentLocale,
        private LocaleCatalog $locales,
    ) {}

    /**
     * Resolve and validate an explicit or request-scoped media content locale.
     */
    public function resolve(?string $explicit = null): string
    {
        if (is_string($explicit) && $explicit !== '') {
            return $this->locales->assertSupported($explicit);
        }

        return $this->contentLocale->get();
    }

    /**
     * Return the first configured fallback locale.
     */
    public function fallback(): string
    {
        return $this->locales->fallbacks()[0] ?? $this->locales->default();
    }
}
