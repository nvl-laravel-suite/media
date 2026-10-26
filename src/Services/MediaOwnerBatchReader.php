<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\Exceptions\MediaBatchReadException;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Ownership\MediaDeliveryGuard;
use Nvl\Media\Ownership\MediaOwnership;
use Nvl\Media\Support\MediaActors;
use Nvl\Media\Support\MediaBatchOwnerAdmission;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Translatable\Services\TranslationResolver;
use stdClass;

/** Finite canonical owner admission and authorized bounded original-asset projection. */
final readonly class MediaOwnerBatchReader
{
    /** Retain explicit policy, canonical storage and pure locale projection dependencies. */
    public function __construct(
        private MediaBatchOwnerAdmission $owners,
        private MediaBatchQueryBuilder $queries,
        private MediaBatchAuthorization $policy,
        private MediaLocaleResolver $locales,
        private LocaleCatalog $catalog,
        private TranslationResolver $translations,
        private OwnershipBoundary $boundary,
        private MediaUrlResolver $urls,
        private OwnershipResourceRegistry $ownershipResources,
        private MediaDeliveryGuard $delivery,
    ) {}

    /** Return at most 20,000 association items and ten locale rows per asset.
     * @param  list<Model>  $owners
     * @param  list<string>  $slots
     */
    public function read(array $owners, array $slots, Authenticatable|SystemActor $actor, ?string $locale = null): array
    {
        MediaActors::assert($actor);
        $input = OwnerBatch::fromModels($owners);
        if ($input->isEmpty()) {
            return $this->result($input, []);
        }
        $slots = $this->slotNames($slots);
        $locale = $this->locales->resolve($locale);
        $definition = (MediaModelRegistry::newInstance())->translationDefinition();
        $chain = $definition->localeChain($locale, $this->catalog->supported());
        if (count($chain) > 10) {
            throw new InvalidArgumentException('Media batch reads allow at most ten requested and fallback locales.');
        }
        $batch = $this->owners->admit($input);
        $this->policy->authorizeOwners($batch, $actor);
        $ceilings = $this->ceilings($batch, $slots);
        $rows = $this->queries->bounded($this->queries->batch($batch, $ceilings, $actor, $this->policy), $batch, $ceilings)->get();
        if ($rows->count() > 20000) {
            throw new MediaBatchReadException('Media slot storage exceeded the 20,000-row batch ceiling.');
        }
        $assets = new Collection;
        $translated = new Collection;
        if ($rows->isNotEmpty()) {
            $ids = $rows->pluck('media_id')->unique()->all();
            $assets = $this->queries->assets($batch, $actor, $this->policy)->whereKey($ids)
                ->select($this->assetColumns())->limit(20000)->get()->keyBy('id');
            $translated = $this->boundary->query(MediaTranslation::query()->withoutEagerLoads(), 'media.translations')
                ->whereIn('media_id', $assets->modelKeys())->whereIn('locale', $chain)
                ->select(['id', 'media_id', 'locale', 'title', 'alt', 'caption'])->limit($assets->count() * count($chain))->get()->groupBy('media_id');
        }
        $publicUrls = $this->delivery->publicUrls(array_values($assets->all()), fn (Media $asset): string => $this->urls->publicUrl($asset, []));
        $values = [];
        foreach ($batch->identities() as $identity) {
            $named = new stdClass;
            foreach ($slots as $slot) {
                $named->{$slot} = [];
            }
            $values[$identity->type][$identity->id] = ['slots' => $named];
        }
        foreach ($rows as $row) {
            $row = $this->association($row);
            $asset = $assets->get($row->media_id);
            if (! $asset instanceof Media) {
                continue;
            }
            $loaded = $this->translationRows($translated->get($asset->id, new Collection));
            $fields = [];
            foreach (['title', 'alt', 'caption'] as $field) {
                $value = $this->translations->resolve($loaded, $definition, $field, $locale, $chain)->value;
                $fields[$field] = is_string($value) ? $value : null;
            }
            $url = $publicUrls[$asset->id] ?? '';
            $items = $values[$row->associable_type][$row->associable_id]['slots']->{$row->collection};
            if (! is_array($items)) {
                throw new MediaBatchReadException('A requested Media slot must contain an item list.');
            }
            $items[] = ['associationId' => $row->id, 'mediaId' => $asset->id, 'title' => $fields['title'], 'altText' => $fields['alt'], 'caption' => $fields['caption'], 'mimeType' => $asset->mime_type, 'size' => $asset->size, 'isPublic' => $asset->is_public, 'url' => $url === '' ? null : $url];
            $values[$row->associable_type][$row->associable_id]['slots']->{$row->collection} = $items;
        }

        return $this->result($batch, $values);
    }

    /** Validate finite names without normalizing canonical collection identity.
     * @param  array<array-key, mixed>  $slots
     * @return list<string>
     */
    private function slotNames(array $slots): array
    {
        if (! array_is_list($slots) || $slots === []) {
            throw new InvalidArgumentException('Media batch reads require a list of registered slots.');
        }
        $names = [];
        foreach ($slots as $slot) {
            if (! is_string($slot) || trim($slot) === '' || mb_strlen($slot) > 50) {
                throw new InvalidArgumentException('Media slot names must be nonempty and at most 50 characters.');
            }
            $names[$slot] = $slot;
        }
        if (count($names) > 20) {
            throw new InvalidArgumentException('Media batch reads allow at most twenty distinct registered slots.');
        }

        return array_values($names);
    }

    /** Select original storage facts after the canonical boundary verifies the active schema.
     * @return list<string>
     */
    private function assetColumns(): array
    {
        $columns = ['id', 'mime_type', 'size', 'is_public', 'visibility', 'status', 'disk', 'folder', 'hash'];
        if (MediaOwnership::active()) {
            $columns[] = 'storage_path';
        }

        if (config('nvl-ownership.enabled') === true && MediaOwnership::active()) {
            $definition = $this->ownershipResources->get('media.assets')->ownership;
            $columns = [...$columns, 'owner_scope_key', 'revision', ...($definition->foreignKey === null ? [] : [$definition->foreignKey])];
        }

        return array_map((MediaModelRegistry::newInstance())->qualifyColumn(...), $columns);
    }

    /** Validate each canonical owner's attribute-dependent declarations without runtime interactions.
     * @param  list<string>  $slots
     * @return array<string, array<array-key, array<array-key, int>>>
     */
    private function ceilings(OwnerBatch $batch, array $slots): array
    {
        $ceilings = [];
        foreach ($batch->owners() as $index => $owner) {
            if (! $owner instanceof HasNvlMedia) {
                throw new InvalidArgumentException('Media batch owners must implement HasNvlMedia.');
            }
            $registration = MediaRegistration::forOwner($owner);
            $identity = $batch->identities()[$index];
            foreach ($slots as $name) {
                $slot = $registration->getSlot($name);
                if ($slot === null) {
                    throw new InvalidArgumentException('Every canonical Media owner must register each requested slot.');
                }
                $ceiling = $slot->isSingleFile ? 1 : ($slot->slotSizeLimit ?? 10);
                if ($ceiling < 1) {
                    throw new InvalidArgumentException('Media slot row ceilings must be positive.');
                }
                $ceilings[$identity->type][$identity->id][$name] = min(10, $ceiling);
            }
        }

        return $ceilings;
    }

    /** Hydrate only the bounded safe association identity columns. */
    private function association(stdClass $row): MediaAssociation
    {
        $attributes = [];
        foreach (get_object_vars($row) as $column => $value) {
            if (! is_string($column)) {
                throw new MediaBatchReadException('A Media association row has an invalid column.');
            }
            $attributes[$column] = $value;
        }

        return (new MediaAssociation)->newFromBuilder($attributes);
    }

    /** Retain pure translation facts without lazy owner access.
     * @param  Collection<int, MediaTranslation>  $rows
     * @return SupportCollection<int, Model>
     */
    private function translationRows(Collection $rows): SupportCollection
    {
        return new SupportCollection(array_map($this->translationModel(...), $rows->all()));
    }

    /** Expose one translation fact through the resolver's Model contract. */
    private function translationModel(MediaTranslation $row): Model
    {
        return $row;
    }

    /** Serialize complete explicit owner arrays.
     * @param  array<string, array<array-key, array<string, mixed>>>  $values
     */
    private function result(OwnerBatch $batch, array $values): array
    {
        $order = [];
        foreach ($batch->identities() as $identity) {
            if (! isset($values[$identity->type][$identity->id])) {
                throw new \LogicException('Every admitted owner requires an explicit Media result.');
            }
            $order[] = ['type' => $identity->type, 'id' => $identity->id];
        }

        return ['owners' => $values, 'order' => $order];
    }
}
