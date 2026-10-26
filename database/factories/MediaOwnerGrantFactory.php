<?php

declare(strict_types=1);

namespace Nvl\Media\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaOwnerGrant;

/** @extends Factory<MediaOwnerGrant> */
final class MediaOwnerGrantFactory extends Factory
{
    protected $model = MediaOwnerGrant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['source_revision' => 1, 'revision' => 1, 'enabled' => true];
    }

    public function forMedia(Media $source): static
    {
        if (! $source->exists || $source->getRawOriginal('owner_scope_key') !== 'central' || ! $source->isAvailable()) {
            throw new \InvalidArgumentException('Catalog fixtures require a persisted available central source.');
        }

        return $this->state(['media_id' => $source->getKey(), 'source_revision' => $source->revision]);
    }
}
