<?php

declare(strict_types=1);

namespace Nvl\Media\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaModelRegistry;

/**
 * Builds MediaImageVariation fixture rows and their declared package parents.
 *
 * @extends Factory<MediaImageVariation>
 *
 * @api
 */
final class MediaImageVariationFactory extends Factory
{
    protected $model = MediaImageVariation::class;

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (MediaImageVariation $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('media_id') !== null) {
                $parent = MediaModelRegistry::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('media_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent, true);
                if ($model->source_revision !== $parent->revision) {
                    throw new InvalidArgumentException('Fixture source revisions must match their exact persisted parent.');
                }
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<MediaImageVariation>, mixed>
     */
    public function definition(): array
    {
        return [
            'media_id' => MediaModelRegistry::newInstance()::factory()->state(['extension' => 'jpg', 'mime_type' => 'image/jpeg', 'type' => 'image']),
            'label' => 'thumbnail',
            'width' => 100,
            'height' => 100,
            'size' => 0,
            'format' => 'jpg',
            'quality' => 80,
            'status' => 'pending',
            'source_revision' => fn (array $attributes): int => $attributes['media_id'] === null ? 1 : MediaModelRegistry::query()->findOrFail(FactoryGuard::identifier($attributes['media_id']))->revision,
        ];
    }

    /**
     * Associate an admitted persisted Media parent.
     *
     * @api
     */
    public function forMedia(Media $parent): static
    {
        FactoryGuard::parent($parent, new MediaImageVariation);

        return $this->state([
            'media_id' => $parent->getKey(),
            'source_revision' => $parent->revision,
        ]);
    }
}
