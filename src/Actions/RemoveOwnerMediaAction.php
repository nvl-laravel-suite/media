<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\RemoveOwnerMediaContract;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaLifecycleService;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Support\MediaBatchOwnerAdmission;
use Nvl\Support\Owners\OwnerBatch;

/** Authorizes targeted owner removal inside the native asset lifecycle lock.
 *
 * @api
 */
final readonly class RemoveOwnerMediaAction implements RemoveOwnerMediaContract
{
    /** Retain canonical owner admission, authorization and native lifecycle collaborators. */
    public function __construct(
        private MediaBatchOwnerAdmission $owners,
        private MediaAuthorization $authorization,
        private MediaMutationLock $lock,
        private MediaLifecycleService $lifecycle,
    ) {}

    /** Remove matching owner usages and authorize deletion only when native lifecycle selects an orphan. */
    public function execute(MediaActorData $actor, Model&HasNvlMedia $owner, string $mediaId, ?string $collection = null): void
    {
        if (! Str::isUuid($mediaId)) {
            throw new InvalidArgumentException('Owner Media removal requires a valid Media UUID.');
        }
        if ($collection !== null && (trim($collection) === '' || mb_strlen($collection) > 50)) {
            throw new InvalidArgumentException('Media collection names must be nonempty and at most fifty characters.');
        }
        $this->lock->execute($mediaId, function () use ($actor, $owner, $mediaId, $collection): void {
            MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($actor, $owner, $mediaId, $collection): void {
                $batch = $this->owners->admit(OwnerBatch::fromModels([$owner]));
                $canonical = $batch->owners()[0];
                if (! $canonical instanceof HasNvlMedia) {
                    throw new InvalidArgumentException('A canonical Media-capable owner is required.');
                }
                $media = MediaModelRegistry::query()->lockForUpdate()->findOrFail($mediaId);
                $selected = MediaAssociation::query()->where('media_id', $media->id);
                MediaBatchOwnerAdmission::exact($selected, 'associable_type', $canonical->getMorphClass());
                MediaBatchOwnerAdmission::exact($selected, 'associable_id', $batch->identities()[0]->id);
                if ($collection !== null) {
                    MediaBatchOwnerAdmission::exact($selected, 'collection', $collection);
                }
                $selected->firstOrFail();
                $this->authorize($actor, MediaAbility::Associate, $media, $canonical);
                $this->lifecycle->removeFromModel($canonical, $media, $collection, function (Media $orphan) use ($actor, $canonical): void {
                    $this->authorize($actor, MediaAbility::Delete, $orphan, $canonical);
                });
            }, 3);
        });
    }

    /** Require the host's current capability decision for the canonical owner and asset. */
    private function authorize(MediaActorData $actor, MediaAbility $ability, Media $media, Model $owner): void
    {
        if (! $this->authorization->allows($actor, $ability, $media, $owner)) {
            throw new AuthorizationException('The selected Media owner removal is not authorized.');
        }
    }
}
