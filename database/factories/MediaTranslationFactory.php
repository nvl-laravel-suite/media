<?php

declare(strict_types=1);

namespace Nvl\Media\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaTranslation;

/**
 * Builds MediaTranslation fixture rows and their declared package parents.
 *
 * @extends Factory<MediaTranslation>
 *
 * @api
 */
final class MediaTranslationFactory extends Factory
{
    protected $model = MediaTranslation::class;

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
        })->afterMaking(function (MediaTranslation $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('media_id') !== null) {
                $parent = Media::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('media_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent, true);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<MediaTranslation>, mixed>
     */
    public function definition(): array
    {
        return [
            'media_id' => Media::factory(),
            'locale' => 'en',
            'title' => $this->faker->sentence(3),
        ];
    }

    /**
     * Associate an admitted persisted Media parent.
     *
     * @api
     */
    public function forMedia(Media $parent): static
    {
        FactoryGuard::parent($parent, new MediaTranslation);

        return $this->state([
            'media_id' => $parent->getKey(),
        ]);
    }
}
