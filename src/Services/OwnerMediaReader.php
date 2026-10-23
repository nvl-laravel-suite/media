<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\Data\Display\OwnerMediaItem;
use Nvl\Media\Data\Display\OwnerMediaPage;
use Nvl\Media\Data\Display\OwnerMediaSlotItemData;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Exceptions\MediaBatchReadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Ownership\MediaDeliveryGuard;
use Nvl\Media\Support\MediaAssetUrl;
use Nvl\Media\Support\MediaBatchOwnerAdmission;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Translatable\Services\TranslationResolver;
use stdClass;

/** Reads complete owner associations with bounded hydration and SQL-authorized totals. */
final readonly class OwnerMediaReader
{
    /** Retain canonical admission, query authorization, localization and public delivery collaborators. */
    public function __construct(
        private MediaBatchOwnerAdmission $owners,
        private MediaBatchQueryBuilder $queries,
        private MediaBatchAuthorization $policy,
        private OwnershipBoundary $boundary,
        private MediaLocaleResolver $locales,
        private LocaleCatalog $catalog,
        private TranslationResolver $translations,
        private MediaDeliveryGuard $delivery,
        private MediaUrlResolver $urls,
        private MediaFileExistence $files,
    ) {}

    /** Return bounded association items and complete distinct original-byte totals.
     * @param  list<string>  $variations
     */
    public function read(MediaActorData $actor, Model&HasNvlMedia $owner, ?string $collection, int $page, int $perPage, array $variations, ?string $locale): OwnerMediaPage
    {
        if ($page < 1 || $perPage < 1 || ($collection !== null && (trim($collection) === '' || mb_strlen($collection) > 50))) {
            throw new InvalidArgumentException('Owner Media reads require a positive page/page size and a valid collection.');
        }
        $labels = $this->labels($variations);
        $perPage = min(100, $perPage, MediaConfiguration::integer('nvl-media.query.maximum_page_size', 100, 1));
        if ($page > intdiv(PHP_INT_MAX, $perPage)) {
            throw new InvalidArgumentException('The requested Media page exceeds the supported offset.');
        }
        $locale = $this->locales->resolve($locale);
        $definition = MediaModelRegistry::newInstance()->translationDefinition();
        $chain = $definition->localeChain($locale, $this->catalog->supported());
        if (count($chain) > 10) {
            throw new InvalidArgumentException('Owner Media reads allow at most ten requested and fallback locales.');
        }
        $batch = $this->owners->admit(OwnerBatch::fromModels([$owner]));
        $this->policy->authorizeOwners($batch, $actor);
        $query = $this->associations($batch, $actor, $collection);
        $total = (clone $query)->count();
        $counts = new stdClass;
        $connection = $query->getModel()->getConnection();
        $group = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => new Expression('BINARY `collection`'),
            'pgsql' => new Expression('"collection" COLLATE "C"'),
            default => new Expression('"collection" COLLATE BINARY'),
        };
        $collections = (clone $query)->select('collection')->selectRaw('COUNT(*) AS aggregate')->groupBy('collection', $group)->limit(1001)->toBase()->get();
        if ($collections->count() > 1000) {
            throw new MediaBatchReadException('Owner Media reads allow at most 1,000 authorized collections.');
        }
        foreach ($collections as $count) {
            if (! is_string($count->collection)) {
                throw new MediaBatchReadException('A Media collection aggregate has an invalid identity.');
            }
            $counts->{$count->collection} = $this->integer($count->aggregate);
        }
        $assetIds = (clone $query)->select('media_id')->distinct();
        $stats = $this->queries->assets($batch, $actor, $this->policy)->whereIn('id', $assetIds)
            ->selectRaw('COUNT(*) AS asset_count, COALESCE(SUM(size), 0) AS original_bytes')->toBase()->first();
        if (! $stats instanceof stdClass) {
            throw new MediaBatchReadException('The Media original-asset aggregate is unavailable.');
        }
        $rows = (clone $query)->orderBy('order')->orderBy('id')->forPage($page, $perPage)->get();
        $assets = $this->queries->assets($batch, $actor, $this->policy)->whereKey($rows->pluck('media_id')->unique()->all())->limit($perPage)->get()->keyBy('id');
        $localized = $this->boundary->query(MediaTranslation::query(), 'media.translations')->whereIn('media_id', $assets->modelKeys())->whereIn('locale', $chain)->limit($perPage * count($chain))->get()->groupBy('media_id');
        $variants = $labels === [] ? new Collection : $this->boundary->query(MediaImageVariation::query(), 'media.variations')->whereIn('media_id', $assets->modelKeys())->whereIn('label', $labels)->limit($perPage * count($labels))->get();
        $byAsset = $variants->groupBy('media_id');
        foreach ($assets as $asset) {
            $asset->setRelation('imageVariations', $byAsset->get($asset->id, new Collection));
            foreach ($asset->imageVariations as $variation) {
                $variation->setRelation('media', $asset);
            }
        }
        $variationUrls = [];
        $publicUrls = $this->delivery->publicUrls(array_values($assets->all()), function (Media $asset) use ($labels, &$variationUrls): string {
            $variationUrls[$asset->id] = array_fill_keys($labels, null);
            foreach ($labels as $label) {
                $variation = $asset->getVariation($label);
                if (in_array('v', MediaAssetUrl::allowedParameters(), true)
                    && $variation instanceof MediaImageVariation && $variation->status === 'available' && $variation->source_revision === $asset->revision
                    && $this->files->exists($asset->disk, $variation->getPath())) {
                    $variationUrls[$asset->id][$label] = $this->urls->publicUrl($asset, ['v' => $label]);
                }
            }

            return $this->urls->publicUrl($asset);
        });
        $items = [];
        foreach ($rows as $row) {
            $asset = $assets->get($row->media_id);
            if (! $asset instanceof Media) {
                throw new MediaBatchReadException('An authorized Media association changed before projection.');
            }
            $translated = new SupportCollection(array_map($this->translationModel(...), $localized->get($asset->id, new Collection)->all()));
            $text = [];
            foreach (['title', 'alt', 'caption'] as $field) {
                $value = $this->translations->resolve($translated, $definition, $field, $locale, $chain)->value;
                $text[$field] = is_string($value) ? $value : null;
            }
            $items[] = new OwnerMediaItem(
                new OwnerMediaSlotItemData($row->id, $asset->id, $text['title'], $text['alt'], $text['caption'], $asset->mime_type, $asset->size, $asset->is_public, $publicUrls[$asset->id] ?? null),
                $row->collection,
                (object) ($variationUrls[$asset->id] ?? array_fill_keys($labels, null)),
            );
        }

        return new OwnerMediaPage($items, $page, $perPage, $total, $counts, $this->integer($stats->asset_count), $this->integer($stats->original_bytes));
    }

    /** Build one exact authorized owner association query for both page and totals.
     * @return Builder<MediaAssociation>
     */
    private function associations(OwnerBatch $batch, MediaActorData $actor, ?string $collection): Builder
    {
        $identity = $batch->identities()[0];
        $query = $this->queries->associations();
        MediaBatchOwnerAdmission::exact($query, 'associable_type', $identity->type);
        MediaBatchOwnerAdmission::exact($query, 'associable_id', $identity->id);
        if ($collection !== null) {
            MediaBatchOwnerAdmission::exact($query, 'collection', $collection);
        }
        $query->where(fn (Builder $nested) => $this->policy->scopeAssociations($nested, $batch, $actor));
        $query->whereIn('media_id', $this->queries->assets($batch, $actor, $this->policy)->select('id'));

        return $query;
    }

    /** Validate a bounded exact list of requested variation labels.
     * @param  array<array-key, mixed>  $labels
     * @return list<string>
     */
    private function labels(array $labels): array
    {
        if (! array_is_list($labels) || count($labels) > 20) {
            throw new InvalidArgumentException('Owner Media reads allow at most twenty requested variation labels.');
        }
        foreach ($labels as $label) {
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 50) {
                throw new InvalidArgumentException('Media variation labels must be nonempty and at most fifty characters.');
            }
        }

        return array_values(array_unique($labels));
    }

    /** Normalize nonnegative portable aggregate results without silently overflowing. */
    private function integer(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (! is_int($integer)) {
            throw new MediaBatchReadException('A Media aggregate exceeds the supported integer range.');
        }

        return $integer;
    }

    /** Expose one loaded translation through the resolver's model contract. */
    private function translationModel(MediaTranslation $translation): Model
    {
        return $translation;
    }
}
