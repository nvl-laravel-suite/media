<?php

declare(strict_types=1);

namespace Nvl\Media\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Builds MediaTenantGrant fixture rows and their declared package parents.
 *
 * @extends Factory<MediaTenantGrant>
 *
 * @api
 */
final class MediaTenantGrantFactory extends Factory
{
    protected $model = MediaTenantGrant::class;

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
        })->afterMaking(function (MediaTenantGrant $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('media_id') !== null) {
                $parent = Media::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('media_id')));
                FactoryGuard::parent($parent, $model);
                if ($model->source_revision !== $parent->revision) {
                    throw new InvalidArgumentException('Fixture source revisions must match their exact persisted parent.');
                }
                if ($parent->tenant_id !== null || $parent->ownership_key !== 'platform' || $parent->status->value !== 'available') {
                    throw new InvalidArgumentException('Catalog fixtures require a platform-owned source.');
                }
            }
            $recipient = $model->getAttribute('tenant_id');
            if (! is_string($recipient)) {
                throw new InvalidArgumentException('Catalog fixtures require an explicit recipient tenant.');
            }
            FactoryGuard::recipient(new TenantId($recipient));
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<MediaTenantGrant>, mixed>
     */
    public function definition(): array
    {
        return [
            'media_id' => Media::factory(),
            'tenant_id' => null,
            'source_revision' => fn (array $attributes): int => $attributes['media_id'] === null ? 1 : Media::query()->findOrFail(FactoryGuard::identifier($attributes['media_id']))->revision,
            'revision' => 1,
            'enabled' => true,
        ];
    }

    /**
     * Associate an admitted persisted Media parent.
     *
     * @api
     */
    public function forMedia(Media $parent): static
    {
        FactoryGuard::parent($parent, new MediaTenantGrant);

        return $this->state([
            'media_id' => $parent->getKey(),
            'source_revision' => $parent->revision,
        ]);
    }

    /** Associate an active recipient in the explicit platform context.
     *
     * @api
     */
    public function forRecipient(TenantId $recipient): static
    {
        FactoryGuard::recipient($recipient);

        return $this->state(['tenant_id' => $recipient->value]);
    }
}
