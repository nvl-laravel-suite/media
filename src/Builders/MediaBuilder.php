<?php

declare(strict_types=1);

namespace Nvl\Media\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Support\Ownership\Traits\ScopesOwnedBuilderWrites;

/**
 * MediaBuilder centralizes reusable media query constraints.
 *
 * @extends Builder<Media>
 */
final class MediaBuilder extends Builder
{
    use ScopesOwnedBuilderWrites;

    /**
     * Treat a malformed identifier as absent; PostgreSQL rejects a non-UUID value compared with the key.
     *
     * @param  mixed  $id
     */
    public function whereKey($id): static
    {
        is_string($id) && ! Str::isUuid($id) ? $this->whereRaw('1 = 0') : parent::whereKey($id);

        return $this;
    }

    /**
     * Restrict the query to public media.
     */
    public function publicOnly(): static
    {
        return $this->where('is_public', true);
    }

    /**
     * Restrict the query to private media.
     */
    public function privateOnly(): static
    {
        return $this->where('is_public', false);
    }

    /**
     * Restrict the query to a media type.
     */
    public function ofType(MediaType|string $type): static
    {
        $resolvedType = $type instanceof MediaType ? $type->value : $type;

        return $this->where('type', $resolvedType);
    }

    /**
     * Restrict the query to a storage disk.
     */
    public function onDisk(string $disk): static
    {
        return $this->where('disk', $disk);
    }

    /**
     * Restrict the query to media containing a tag.
     */
    public function withTag(string $tag): static
    {
        return $this->whereJsonContains('tags', $tag);
    }
}
