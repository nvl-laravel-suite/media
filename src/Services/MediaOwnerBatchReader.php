<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\Data\Display\OwnerMediaSlotData;
use Nvl\Media\Data\Display\OwnerMediaSlotItemData;
use Nvl\Media\Data\Display\OwnerMediaSlots;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Exceptions\MediaBatchReadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Support\MediaBatchOwnerAdmission;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Owners\OwnerResultMap;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
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
        private TenantBoundary $boundary,
        private MediaUrlResolver $urls,
        private Repository $configuration,
    ) {}

    /** Return at most 20,000 association items and ten locale rows per asset.
     * @param  list<Model>  $owners
     * @param  list<string>  $slots
     */
    public function read(array $owners, array $slots, MediaActorData $actor, ?string $locale = null): OwnerMediaSlots
    {
        $input = OwnerBatch::fromModels($owners);
        if ($input->isEmpty()) {
            return $this->result($input, []);
        }
        $slots = $this->slotNames($slots);
        $locale = $this->locales->resolve($locale);
        $definition = (new Media)->translationDefinition();
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
        $values = [];
        foreach ($batch->identities() as $identity) {
            $named = new stdClass;
            foreach ($slots as $slot) {
                $named->{$slot} = [];
            }
            $values[$identity->type][$identity->id] = new OwnerMediaSlotData($named);
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
            $url = $asset->is_public ? $this->urls->publicUrl($asset, []) : '';
            $items = $values[$row->associable_type][$row->associable_id]->slots->{$row->collection};
            if (! is_array($items)) {
                throw new MediaBatchReadException('A requested Media slot must contain an item list.');
            }
            $items[] = new OwnerMediaSlotItemData(
                $row->id, $asset->id, $fields['title'], $fields['alt'], $fields['caption'], $asset->mime_type,
                $asset->size, $asset->is_public, $url === '' ? null : $url,
            );
            $values[$row->associable_type][$row->associable_id]->slots->{$row->collection} = $items;
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
        if ($this->configuration->get('nvl-tenancy.enabled') === true) {
            $columns[] = 'storage_path';
        }

        return array_map((new Media)->qualifyColumn(...), $columns);
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
            $registration = $owner->nvlMedia()->registration();
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

    /** Serialize complete explicit owner DTOs.
     * @param  array<string, array<array-key, OwnerMediaSlotData>>  $values
     */
    private function result(OwnerBatch $batch, array $values): OwnerMediaSlots
    {
        return OwnerMediaSlots::fromMap(new OwnerResultMap($batch, $values));
    }
}
