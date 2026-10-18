<?php

declare(strict_types=1);

namespace Nvl\Media\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Tenancy\MediaTenantParentResolver;

/**
 * Builds MediaAssociation fixture rows and their declared package parents.
 *
 * @extends Factory<MediaAssociation>
 *
 * @api
 */
final class MediaAssociationFactory extends Factory
{
    protected $model = MediaAssociation::class;

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
        })->afterMaking(function (MediaAssociation $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            $owner = FactoryGuard::owner($model, 'associable_type', 'associable_id');
            if (! $owner instanceof HasNvlMedia) {
                throw new InvalidArgumentException('Media association fixtures require a native Media-capable owner.');
            }
            if (config('nvl-tenancy.enabled') === true
                && (Container::getInstance()->make(MediaTenantParentResolver::class)->types()[$owner->getMorphClass()] ?? null) !== $owner::class) {
                throw new InvalidArgumentException('Media association fixtures require an allowlisted tenant owner.');
            }
            if ($model->getAttribute('media_id') !== null) {
                $parent = MediaModelRegistry::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('media_id')));
                FactoryGuard::parent($parent, $model);
                if (config('nvl-tenancy.enabled') === true && $owner->getRawOriginal('tenant_id') !== $parent->getRawOriginal('tenant_id')) {
                    throw new InvalidArgumentException('Fixture owner and parent tenancy must agree.');
                }
                FactoryGuard::inherit($model, $parent, true);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<MediaAssociation>, mixed>
     */
    public function definition(): array
    {
        return [
            'media_id' => MediaModelRegistry::newInstance()::factory(),
            'associable_type' => null,
            'associable_id' => null,
            'collection' => 'default',
            'order' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Associate an admitted persisted Media parent.
     *
     * @api
     */
    public function forMedia(Media $parent): static
    {
        FactoryGuard::parent($parent, new MediaAssociation);

        return $this->state([
            'media_id' => $parent->getKey(),
        ]);
    }

    /**
     * Associate a persisted host owner using its native morph identity.
     *
     * @api
     */
    public function forOwner(Model $owner): static
    {
        FactoryGuard::parent($owner, new MediaAssociation);

        return $this->state([
            'associable_type' => $owner->getMorphClass(),
            'associable_id' => (string) FactoryGuard::identifier($owner->getKey()),
        ]);
    }
}
