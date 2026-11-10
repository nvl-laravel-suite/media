<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Nvl\Media\Tests\Fixtures\SerializesMediaProjection;

/** MediaDocument: type-specific payload for document media such as PDF files. */
final class MediaDocument implements Arrayable, JsonSerializable
{
    use SerializesMediaProjection;

    public function __construct(
        /** @var string|null */
        public readonly ?string $extension,

        /** @var string|null */
        public readonly ?string $mimeType,

        /** @var int */
        public readonly int $size,

        /** @var string */
        public readonly string $humanReadableSize,

        /** @var string */
        public readonly string $url,
    ) {}
}
