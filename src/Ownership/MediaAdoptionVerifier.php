<?php

declare(strict_types=1);

namespace Nvl\Media\Ownership;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\Services\OwnershipCanonicalResolver;
use Nvl\Ownership\Services\OwnershipRecordReader;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Rechecks physical binaries and attachment targets before publishing an adoption barrier. @internal */
final readonly class MediaAdoptionVerifier
{
    public function __construct(
        private OwnershipResourceRegistry $resources,
        private OwnershipAdoptionStore $mappings,
        private OwnershipCanonicalResolver $canonical,
        private OwnershipRecordReader $records,
        private OwnerIdentityResolver $identities,
        private OwnerRegistry $owners,
        private MediaDiskGateway $disks,
        private MediaOwnershipParentResolver $targets,
    ) {}

    /** Stream canonical source rows without admitting an ordinary consumer during maintenance. */
    public function verify(OwnershipAdoptionPlan $plan): void
    {
        $assets = $this->resources->get('media.assets');
        $asset = new $assets->model;
        $associations = new ($this->resources->get('media.associations')->model);
        foreach ($asset->getConnection()->table($asset->getTable())->useWritePdo()->orderBy('id')->cursor() as $row) {
            $fresh = $this->records->hydrate($asset, $row);
            $reference = $this->canonical->fromPersisted($fresh, $assets);
            $this->binary($plan, 'media.assets', $fresh, $fresh->getRawOriginal('disk'));
            foreach ($associations->getConnection()->table($associations->getTable())->useWritePdo()->where('media_id', $fresh->getKey())->cursor() as $association) {
                if (! is_string($association->associable_type) || (! is_string($association->associable_id) && ! is_int($association->associable_id))) {
                    throw new OwnershipViolation('Historical Media attachment identities must be canonical.');
                }
                $class = $this->targets->classForIdentity($association->associable_type);
                $target = new $class;
                if ($target->getConnection() !== $asset->getConnection()) {
                    throw new OwnershipViolation('Historical Media attachment sources must retain their exact connection.');
                }
                $targetRow = $target->getConnection()->table($target->getTable())->useWritePdo()->where($target->getKeyName(), $association->associable_id)->first();
                if ($targetRow === null) {
                    throw new OwnershipViolation('Historical Media attachments cannot name absent host records.');
                }
                $target = $this->records->hydrate($target, $targetRow);
                $actual = $this->targetReference($target);
                if ($reference === null || $actual->toArray() !== $reference->toArray()) {
                    throw new OwnershipViolation('Historical Media attachments disagree with their canonical primary owner.');
                }
            }
        }
        $variations = new ($this->resources->get('media.variations')->model);
        foreach ($variations->getConnection()->table($variations->getTable())->useWritePdo()->orderBy('id')->cursor() as $row) {
            $fresh = $this->records->hydrate($variations, $row);
            $disk = $asset->getConnection()->table($asset->getTable())->useWritePdo()->where('id', $fresh->getRawOriginal('media_id'))->value('disk');
            $this->binary($plan, 'media.variations', $fresh, $disk);
        }
    }

    /** Resolve only an owned resource or the exact admitted native owner anchor. */
    private function targetReference(Model $target): OwnerReference
    {
        foreach ($this->resources->all() as $definition) {
            if ($definition->model === $target::class && $definition->ownership->strategy !== 'unscoped') {
                return $this->canonical->reference($target, $definition) ?? throw new OwnershipViolation('Central assets cannot be attached to an owned host.');
            }
        }
        $owner = $this->resources->get('media.assets')->ownership->owner;
        if ($owner === null || $target::class !== $this->owners->model($owner)) {
            throw new OwnershipViolation('Historical attachment targets need a canonical owned declaration.');
        }

        return $this->identities->reference($target);
    }

    /** Verify reviewed bytes and refuse physical object sharing across ownership partitions. */
    private function binary(OwnershipAdoptionPlan $plan, string $resource, Model $record, mixed $disk): void
    {
        $id = $record->getKey();
        $path = $record->getRawOriginal('storage_path');
        $size = $record->getRawOriginal('size');
        if (! is_string($id) || ! is_string($disk) || ! is_string($path) || $path === ''
            || str_starts_with($path, '/') || in_array('..', explode('/', str_replace('\\', '/', $path)), true)
            || (! is_int($size) && (! is_string($size) || ! ctype_digit($size)))) {
            throw new OwnershipViolation('Historical Media binaries require canonical disk, path and size facts.');
        }
        $mapping = $this->mappings->forRecord($plan, $resource, $id);
        $digest = $mapping->metadata['expected_digest'] ?? null;
        if (! is_string($digest) || ! hash_equals($digest, $this->disks->checksum($disk, $path))
            || (string) $size !== (string) $this->disks->size($disk, $path)
            || ($resource === 'media.assets' && $record->getRawOriginal('digest') !== $digest)) {
            throw new OwnershipViolation('Reviewed historical Media bytes are missing or changed.');
        }
        $asset = new ($this->resources->get('media.assets')->model);
        $variations = new ($this->resources->get('media.variations')->model);
        $scope = $record->getRawOriginal('owner_scope_key');
        $sharedAsset = $asset->getConnection()->table($asset->getTable())->where('disk', $disk)->where('storage_path', $path)->where('owner_scope_key', '!=', $scope)->exists();
        $sharedVariation = $variations->getConnection()->table($variations->getTable().' as variation')
            ->join($asset->getTable().' as asset', 'asset.id', '=', 'variation.media_id')
            ->where('asset.disk', $disk)->where('variation.storage_path', $path)->where('variation.owner_scope_key', '!=', $scope)->exists();
        if ($sharedAsset || $sharedVariation) {
            throw new OwnershipViolation('Historical private objects must be split before assigning different owners.');
        }
    }
}
