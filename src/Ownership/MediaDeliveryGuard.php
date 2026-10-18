<?php

declare(strict_types=1);

namespace Nvl\Media\Ownership;

use Closure;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

/** Rejects forged carried delivery facts as well as foreign canonical owners. @internal */
final class MediaDeliveryGuard
{
    /** @var array<int, array<string, mixed>> */
    private array $batchFacts = [];

    private ?OwnershipSnapshot $batchSnapshot = null;

    public function __construct(private readonly OwnershipBoundary $boundary, private readonly OwnershipContext $context, private readonly OwnershipResourceRegistry $resources) {}

    public function assertAsset(Media $media): void
    {
        if (! MediaOwnership::declared()) {
            return;
        }
        $facts = $this->batchFacts[spl_object_id($media)] ?? null;
        if ($facts !== null && config('nvl-ownership.enabled') === true && $this->context->snapshot() === $this->batchSnapshot) {
            $this->assertFacts($media, $facts);

            return;
        }
        $this->boundary->assertRecord($media, 'media.assets');
        $canonical = MediaModelRegistry::query()->useWritePdo()->find($media->id) ?? throw new OwnershipViolation;
        $this->assertFacts($media, $canonical->getAttributes());
    }

    /** Format a bounded internal projection with canonical bulk proof, discarded before returning.
     * @param  list<Media>  $assets
     * @param  Closure(Media): string  $url
     * @return array<string, string>
     */
    public function publicUrls(array $assets, Closure $url): array
    {
        $public = array_values(array_filter($assets, static fn (Media $media): bool => $media->is_public));
        if ($public === []) {
            return [];
        }
        if (! MediaOwnership::declared()) {
            $urls = [];
            foreach ($public as $asset) {
                $urls[$asset->id] = $url($asset);
            }

            return $urls;
        }
        $key = $this->boundary->key('media.assets', 'batch-public-delivery');
        $prototype = MediaModelRegistry::newInstance();
        foreach ($public as $asset) {
            if ($asset::class !== $prototype::class || $asset->getTable() !== $prototype->getTable() || $asset->getConnection() !== $prototype->getConnection()) {
                throw new OwnershipViolation('Media batch delivery requires exact canonical model storage.');
            }
        }
        $canonical = MediaModelRegistry::query()->useWritePdo()->whereKey(array_map(static fn (Media $asset): string => $asset->id, $public))->limit(20000)->get()->keyBy('id');
        $previous = $this->batchFacts;
        $snapshot = $this->batchSnapshot;
        $this->batchFacts = [];
        $this->batchSnapshot = $this->context->snapshot();
        try {
            foreach ($public as $asset) {
                $fresh = $canonical->get($asset->id) ?? throw new OwnershipViolation('A public Media projection changed ownership.');
                $this->assertFacts($asset, $fresh->getAttributes());
                if (! $fresh->isAvailable()) {
                    throw new OwnershipViolation('A public Media projection is unavailable.');
                }
                $this->batchFacts[spl_object_id($asset)] = $fresh->getAttributes();
            }
            $urls = [];
            foreach ($public as $asset) {
                $urls[$asset->id] = $url($asset);
            }
            if ($this->context->snapshot() !== $this->batchSnapshot || $this->boundary->key('media.assets', 'batch-public-delivery') !== $key) {
                throw new OwnershipViolation('Media batch admission changed during projection.');
            }

            return $urls;
        } finally {
            $this->batchFacts = $previous;
            $this->batchSnapshot = $snapshot;
        }
    }

    /** Compare canonical delivery and primary owner fields across native PDO representations.
     * @param  array<string, mixed>  $facts
     */
    private function assertFacts(Media $media, array $facts): void
    {
        $owner = $this->resources->get('media.assets')->ownership->foreignKey;
        $fields = ['id', 'owner_scope_key', 'disk', 'storage_path', 'hash', 'folder', 'revision', 'status', 'visibility', 'is_public', ...($owner === null ? [] : [$owner])];
        foreach ($fields as $field) {
            $actual = $media->getAttributes()[$field] ?? null;
            $expected = $facts[$field] ?? null;
            if ($field === 'is_public') {
                $actual = match ($actual) {
                    false, 0, '0' => false, true, 1, '1' => true, default => throw new OwnershipViolation('Invalid carried Media visibility.')
                };
                $expected = match ($expected) {
                    false, 0, '0' => false, true, 1, '1' => true, default => throw new OwnershipViolation('Invalid persisted Media visibility.')
                };
            }
            if ($actual !== $expected && (! is_scalar($actual) || ! is_scalar($expected) || (string) $actual !== (string) $expected)) {
                throw new OwnershipViolation('Media delivery requires canonical persisted storage and revision facts.');
            }
        }
    }

    public function assertVariation(MediaImageVariation $variation, Media $media): void
    {
        if (! MediaOwnership::declared()) {
            return;
        }
        $this->boundary->assertRecord($variation, 'media.variations');
        $canonical = MediaImageVariation::query()->useWritePdo()->find($variation->id) ?? throw new OwnershipViolation;
        foreach (['media_id', 'storage_path', 'label', 'format', 'source_revision', 'status'] as $field) {
            if ($variation->getAttribute($field) !== $canonical->getAttribute($field)) {
                throw new OwnershipViolation('Media variation delivery requires its canonical source facts.');
            }
        }
        if ($canonical->media_id !== $media->id) {
            throw new OwnershipViolation;
        }
    }
}
