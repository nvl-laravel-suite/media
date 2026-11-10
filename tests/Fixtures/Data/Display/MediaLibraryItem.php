<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Nvl\Media\Tests\Fixtures\SerializesMediaProjection;

/**
 * Privileged media-library projection for authorized management clients.
 *
 * @api
 */
final class MediaLibraryItem implements Arrayable, JsonSerializable
{
    use SerializesMediaProjection;

    public function __construct(
        /** @var string */
        public readonly string $id,

        /** @var string */
        public readonly string $filename,

        /** @var string|null */
        public readonly ?string $title,

        /** @var string|null */
        public readonly ?string $extension,

        /** @var string|null */
        public readonly ?string $mimeType,

        /** @var int */
        public readonly int $size,

        /** @var string */
        public readonly string $humanReadableSize,

        /** @var string */
        public readonly string $disk,

        /** @var string|null */
        public readonly ?string $folder,

        /** @var string|null */
        public readonly ?string $collection,

        /** @var bool */
        public readonly bool $isPublic,

        /** @var string */
        public readonly string $type,

        /** @var array<int, string> */
        public readonly array $tags,

        /** @var int */
        public readonly int $associationsCount,

        /** @var string|null */
        public readonly ?string $createdAt,

        /** @var string|null */
        public readonly ?string $updatedAt,

        /** @var string|null */
        public readonly ?string $previewUrl,

        /** @var string|null */
        public readonly ?string $url,
    ) {}
}
