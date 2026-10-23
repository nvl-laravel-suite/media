<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Contracts\ListOwnerMediaContract;
use Nvl\Media\Contracts\ListOwnerMediaSlotsContract;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\Data\Display\OwnerMediaSlotItemData;
use Nvl\Media\Data\Display\OwnerMediaSlots;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Exceptions\MediaBatchReadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Services\MediaBatchQueryBuilder;
use Nvl\Media\Services\MediaUrlResolver;
use Nvl\Media\Services\UnsupportedMediaBatchAuthorization;
use Nvl\Media\Support\MediaBatchOwnerAdmission;
use Nvl\Media\Tests\Stubs\BatchMediaAuthorization;
use Nvl\Media\Tests\Stubs\BatchMediaOwner;
use Nvl\Media\Tests\Stubs\BatchStringMediaOwner;
use Nvl\Media\Tests\Stubs\DeclaredConnectionBatchMediaOwner;
use Nvl\Media\Tests\Stubs\IntegerBatchMediaOwner;
use Nvl\Media\Tests\Stubs\NumericBatchMediaOwner;
use Nvl\Media\Tests\Stubs\OtherBatchStringMediaOwner;
use Nvl\Media\Tests\Stubs\OwnerSlotWorkflowModel;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

beforeEach(function (): void {
    $this->batchMorphMap = Relation::morphMap();
    $this->batchRequiresMorphMap = Relation::requiresMorphMap();
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
    app()->instance(MediaBatchAuthorization::class, new BatchMediaAuthorization);
    $driver = getenv('NVL_C1_TEST_DRIVER');
    if (is_string($driver) && $driver !== '') {
        expect((new OwnerSlotWorkflowModel)->getConnection()->getDriverName())->toBe($driver);
    }
});

afterEach(function (): void {
    Relation::morphMap($this->batchMorphMap, false);
    Relation::requireMorphMap($this->batchRequiresMorphMap);
});

it('provides the bounded many owner media contract', function (int $count): void {
    $owners = [];
    for ($index = 0; $index < $count; $index++) {
        $owners[] = OwnerSlotWorkflowModel::query()->create(['name' => 'Owner']);
    }
    $result = app(ListOwnerMediaSlotsContract::class)->execute($owners, ['document'], MediaActorData::system());
    expect($result->order)->toHaveCount($count);
})->with([1, 25, 100]);

it('returns an object map without storage for an empty batch', function (): void {
    $result = app(ListOwnerMediaSlotsContract::class)->execute([], ['document'], MediaActorData::system());
    expect(json_encode($result->owners))->toBe('{}');
});

it('contains pre-existing caller ORs for direct and scoped host reads', function (bool $direct): void {
    $deleted = BatchMediaOwner::query()->create(['name' => 'Deleted']);
    $live = BatchMediaOwner::query()->create(['name' => 'Live']);
    batchMediaAssociation($live, Media::factory()->create(['is_public' => false]));
    DB::table($deleted->getTable())->where('id', $deleted->id)->update(['deleted_at' => now()]);
    $query = BatchMediaOwner::query()->withoutGlobalScopes()->whereKey($deleted->id)->orWhere($live->getQualifiedKeyName(), $live->id);
    $actor = MediaActorData::system();
    $policy = app(MediaBatchAuthorization::class);

    if ($direct) {
        app(MediaBatchQueryBuilder::class)->has($query, 'document', $actor, $policy);
    } else {
        $query->hasNvlMedia('document', $actor, $policy);
    }

    expect($query->pluck('id')->all())->toBe([$live->id]);
})->with([true, false]);

/** Attach seeded storage without entering filesystem ingestion in read-focused tests. */
function batchMediaAssociation(Model $owner, Media $asset, string $slot = 'document', int $order = 0, bool $active = true): MediaAssociation
{
    return MediaAssociation::query()->create([
        'media_id' => $asset->id, 'associable_type' => $owner->getMorphClass(), 'associable_id' => (string) $owner->getKey(),
        'collection' => $slot, 'order' => $order, 'is_active' => $active,
    ]);
}

/** Read exact native identity fields from a public object map.
 * @return list<OwnerMediaSlotItemData>
 */
function batchMediaItems(OwnerMediaSlots $result, Model $owner, string $slot = 'document'): array
{
    return $result->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()}->slots->{$slot};
}

/** Count logical package table reads independent of driver quoting.
 * @return list<array{query: string, bindings: array<int, mixed>, time: float}>
 */
function batchMediaStorageQueries(): array
{
    $tables = array_map(static fn (Model $model): string => $model->getTable(), [new Media, new MediaAssociation, new MediaTranslation]);

    return array_values(array_filter(DB::getQueryLog(), static function (array $query) use ($tables): bool {
        $sql = str_replace(['"', '`', '[', ']'], '', strtolower($query['query']));
        foreach ($tables as $table) {
            if (preg_match('/\b(?:from|join)\s+'.preg_quote(strtolower($table), '/').'\b/', $sql) === 1) {
                return true;
            }
        }

        return false;
    }));
}

it('paginates every authorized owner collection and counts distinct original bytes beyond previews', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $other = BatchMediaOwner::query()->create(['name' => 'Other']);
    $associations = [];
    for ($index = 0; $index < 13; $index++) {
        $asset = Media::factory()->create(['is_public' => false, 'size' => 100]);
        $associations[] = batchMediaAssociation($owner, $asset, 'unbounded', $index)->id;
        if ($index === 0) {
            $associations[] = batchMediaAssociation($owner, $asset, 'library')->id;
        }
    }
    batchMediaAssociation($other, Media::factory()->create(['size' => 999]), 'unbounded');
    batchMediaAssociation($owner, Media::factory()->create(['size' => 999]), 'unbounded', active: false);
    $denied = Media::factory()->create(['size' => 999]);
    batchMediaAssociation($owner, $denied, 'unbounded');
    app(MediaBatchAuthorization::class)->deniedAssets = [$denied->id];
    $reader = app(ListOwnerMediaContract::class);
    $received = [];

    foreach ([1, 2, 3] as $page) {
        $result = $reader->execute(MediaActorData::system(), $owner, page: $page, perPage: 5);
        expect($result->page)->toBe($page)
            ->and($result->perPage)->toBe(5)
            ->and($result->total)->toBe(14)
            ->and($result->collectionCounts->unbounded)->toBe(13)
            ->and($result->collectionCounts->library)->toBe(1)
            ->and($result->distinctAssetCount)->toBe(13)
            ->and($result->originalBytes)->toBe(1300)
            ->and($result->items)->toHaveCount($page === 3 ? 4 : 5);
        foreach ($result->items as $item) {
            $received[] = $item->media->associationId;
            expect($item->media)->toBeInstanceOf(OwnerMediaSlotItemData::class)
                ->and($item->media->url)->toBeNull();
        }
    }
    sort($received);
    sort($associations);
    expect($received)->toBe($associations);
    $gallery = $reader->execute(MediaActorData::system(), $owner, collection: 'unbounded', perPage: 10000);
    expect($gallery->total)->toBe(13)->and($gallery->perPage)->toBe(100)
        ->and($gallery->distinctAssetCount)->toBe(13)->and($gallery->originalBytes)->toBe(1300);
});

it('returns requested current public variation URLs without private capabilities or missing fallbacks', function (bool $enabled): void {
    config(['nvl-media.assets.allowed_parameters' => $enabled ? ['v'] : []]);
    Storage::fake('public');
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $assets = [];
    foreach ([true, false] as $public) {
        $asset = Media::factory()->create(['is_public' => $public, 'type' => 'image', 'extension' => 'jpg', 'mime_type' => 'image/jpeg']);
        batchMediaAssociation($owner, $asset, 'unbounded');
        Storage::disk($asset->disk)->put($asset->buildPath(), 'original');
        $variation = MediaImageVariation::factory()->forMedia($asset)->create(['label' => 'thumbnail', 'status' => 'available']);
        Storage::disk($asset->disk)->put($variation->getPath(), 'thumbnail');
        $assets[] = $asset;
    }

    $result = app(ListOwnerMediaContract::class)->execute(MediaActorData::system(), $owner, variations: ['thumbnail', 'missing']);
    $items = collect($result->items)->keyBy(static fn ($item): string => $item->media->mediaId);

    if ($enabled) {
        expect($items[$assets[0]->id]->variationUrls->thumbnail)->toBeString()->toContain('thumbnail');
    } else {
        expect($items[$assets[0]->id]->variationUrls->thumbnail)->toBeNull();
    }
    expect($items[$assets[0]->id]->variationUrls->missing)->toBeNull()
        ->and($items[$assets[1]->id]->media->url)->toBeNull()
        ->and(get_object_vars($items[$assets[1]->id]->variationUrls))->toBe(['thumbnail' => null, 'missing' => null])
        ->and(json_encode($result))->not->toContain('signature=', 'uploadedBy', 'storagePath');
})->with([true, false]);

it('serializes owner variation maps as objects for empty and numeric labels', function (array $labels, string $expected): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    batchMediaAssociation($owner, Media::factory()->create(['is_public' => false]), 'unbounded');

    $result = app(ListOwnerMediaContract::class)->execute(MediaActorData::system(), $owner, variations: $labels);

    expect(json_encode($result->items[0]->variationUrls))->toBe($expected);
})->with([
    'empty' => [[], '{}'],
    'numeric' => [['0', '1'], '{"0":null,"1":null}'],
]);

it('resolves exact numeric variation labels independently of a stale numerically equal label', function (): void {
    Storage::fake('public');
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $asset = Media::factory()->create(['is_public' => true, 'type' => 'image', 'extension' => 'jpg', 'mime_type' => 'image/jpeg']);
    batchMediaAssociation($owner, $asset, 'unbounded');
    foreach (['0', '00'] as $label) {
        $variation = MediaImageVariation::factory()->forMedia($asset)->create(['label' => $label, 'status' => 'available']);
        Storage::disk($asset->disk)->put($variation->getPath(), 'variation-'.$label);
        if ($label === '0') {
            $asset->revision++;
            $asset->save();
        }
    }

    $result = app(ListOwnerMediaContract::class)->execute(MediaActorData::system(), $owner, variations: ['0', '00']);

    expect($result->items[0]->variationUrls->{'0'})->toBeNull()
        ->and($result->items[0]->variationUrls->{'00'})->toBeString()->toContain('v=00')
        ->and($asset->getVariation('00')?->label)->toBe('00')
        ->and($asset->getVariation('0e0'))->toBeNull()
        ->and($asset->hasVariation('0e0'))->toBeFalse();
});

it('fails complete owner media reads closed without a query authorization adapter', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    app()->instance(MediaBatchAuthorization::class, new UnsupportedMediaBatchAuthorization);

    expect(fn () => app(ListOwnerMediaContract::class)->execute(MediaActorData::system(), $owner))
        ->toThrow(MediaBatchReadException::class);
});

it('bounds complete owner collection aggregates before projecting an oversized result', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $asset = Media::factory()->create(['is_public' => false]);
    $rows = [];
    for ($index = 0; $index < 1001; $index++) {
        $rows[] = [
            'id' => (string) Str::uuid(), 'media_id' => $asset->id, 'associable_type' => $owner->getMorphClass(),
            'associable_id' => (string) $owner->getKey(), 'collection' => 'collection-'.$index, 'is_active' => true, 'order' => 0,
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table((new MediaAssociation)->getTable())->insert($chunk);
    }

    expect(fn () => app(ListOwnerMediaContract::class)->execute(MediaActorData::system(), $owner))
        ->toThrow(MediaBatchReadException::class, '1,000');
});

it('holds total localized queries fixed and projects nine safe fields', function (int $count): void {
    Storage::fake('public');
    $owners = [];
    $assets = [];
    for ($index = 0; $index < $count; $index++) {
        $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
        $asset = Media::factory()->create(['is_public' => $index % 2 === 0]);
        Storage::disk($asset->disk)->put($asset->buildPath(), 'bytes');
        $association = batchMediaAssociation($owner, $asset);
        MediaTranslation::query()->create(['media_id' => $asset->id, 'locale' => 'en', 'title' => 'Fallback', 'alt' => 'Accessible', 'caption' => 'Caption']);
        MediaTranslation::query()->create(['media_id' => $asset->id, 'locale' => 'bg', 'title' => 'Заглавие', 'alt' => null, 'caption' => '']);
        $owners[] = $owner;
        $assets[] = [$asset, $association];
    }
    app(TenantBoundary::class)->query(Media::query(), 'media.assets');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListOwnerMediaSlotsContract::class)->execute($owners, ['document', '0'], MediaActorData::system(), 'bg');
    expect(DB::getQueryLog())->toHaveCount(4);
    foreach ($owners as $index => $owner) {
        $item = batchMediaItems($result, $owner)[0];
        [$asset, $association] = $assets[$index];
        expect(array_keys($item->toArray()))->toBe(['associationId', 'mediaId', 'title', 'altText', 'caption', 'mimeType', 'size', 'isPublic', 'url'])
            ->and($item->associationId)->toBe($association->id)
            ->and($item->title)->toBe('Заглавие')->and($item->altText)->toBe('Accessible')->and($item->caption)->toBe('')
            ->and($item->url === null)->toBe(! $asset->is_public)
            ->and(batchMediaItems($result, $owner, '0'))->toBe([]);
    }
    expect(DB::getQueryLog())->toHaveCount(4);
    foreach (DB::getQueryLog() as $entry) {
        expect($entry['query'])->not->toContain((new MediaImageVariation)->getTable());
    }
})->with([1, 25, 100]);

it('builds standalone original URLs from existing folder and hash columns', function (): void {
    config([
        'nvl-media.root_folder' => 'media',
        'filesystems.disks.public.url' => 'https://assets.example.test/objects',
    ]);
    Storage::fake('public', ['url' => 'https://assets.example.test/objects']);
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $asset = Media::factory()->create(['is_public' => true, 'folder' => 'documents', 'hash' => 'report.pdf']);
    batchMediaAssociation($owner, $asset);
    expect(Schema::connection($asset->getConnectionName())->hasColumn($asset->getTable(), 'storage_path'))->toBeFalse()
        ->and(app(MediaUrlResolver::class)->publicUrl($asset, []))->toBe('https://assets.example.test/objects/media/documents/report.pdf');
    app(TenantBoundary::class)->query(Media::query(), 'media.assets');
    DB::enableQueryLog();
    DB::flushQueryLog();

    $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system());

    expect(batchMediaItems($result, $owner)[0]->url)->toBe('https://assets.example.test/objects/media/documents/report.pdf')
        ->and(DB::getQueryLog())->toHaveCount(4);
    foreach (batchMediaStorageQueries() as $entry) {
        expect($entry['query'])->not->toContain('storage_path');
    }
});

it('accounts for the fixed disabled tenancy cold safety probe separately', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $asset = Media::factory()->create(['is_public' => false]);
    batchMediaAssociation($owner, $asset);
    app()->forgetScopedInstances();
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system());
    expect(DB::getQueryLog())->toHaveCount(5);
});

it('returns an empty batch with unsupported policy and no queries', function (): void {
    app()->instance(MediaBatchAuthorization::class, new UnsupportedMediaBatchAuthorization);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListOwnerMediaSlotsContract::class)->execute([], [], MediaActorData::system(), 'unsupported');
    expect($result->owners)->toBeInstanceOf(stdClass::class)->and(DB::getQueryLog())->toBe([]);
});

it('fails unsupported authorization before package storage', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    app()->instance(MediaBatchAuthorization::class, new UnsupportedMediaBatchAuthorization);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system()))
        ->toThrow(MediaBatchReadException::class, 'explicit MediaBatchAuthorization');
    expect(batchMediaStorageQueries())->toBe([]);
});

it('rejects owner denial before package storage', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    app(MediaBatchAuthorization::class)->denyOwners = true;
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system()))->toThrow(AuthorizationException::class);
    expect(batchMediaStorageQueries())->toBe([]);
});

it('validates every canonical owner declaration and ignores forged caller facts', function (): void {
    $good = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $restricted = BatchMediaOwner::query()->create(['name' => 'Restricted']);
    $restricted->name = 'Owner';
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListOwnerMediaSlotsContract::class)->execute([$good, $restricted], ['document'], MediaActorData::system()))->toThrow(InvalidArgumentException::class, 'Every canonical');
    expect(batchMediaStorageQueries())->toBe([])->and(app(MediaBatchAuthorization::class)->admitted[1]->name)->toBe('Restricted');
});

it('rejects absent and soft deleted owners before package storage', function (bool $deleted): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    if ($deleted) {
        DB::table($owner->getTable())->where('id', $owner->id)->update(['deleted_at' => now()]);
    } else {
        DB::table($owner->getTable())->where('id', $owner->id)->delete();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system()))->toThrow(TenantBoundaryViolation::class);
    expect(batchMediaStorageQueries())->toBe([]);
})->with([false, true]);

it('validates malformed owners and finite slots before storage', function (string $mode): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $owners = [$owner];
    $slots = ['document'];
    if ($mode === 'unpersisted') {
        $owners = [new BatchMediaOwner];
    }
    if ($mode === '101') {
        $owners = array_fill(0, 101, $owner);
    }
    if ($mode === 'conflict') {
        $other = clone $owner;
        $other->name = 'Forged';
        $owners[] = $other;
    }
    if ($mode === 'empty_slots') {
        $slots = [];
    }
    if ($mode === '21_slots') {
        $slots = array_map(static fn (int $i): string => 'slot'.$i, range(0, 20));
    }
    if ($mode === 'bad_slot') {
        $slots = [' '];
    }
    if ($mode === 'foreign_connection') {
        $owner->setConnection('foreign');
    }
    if ($mode === 'foreign_table') {
        $owner->setTable('other_owners');
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListOwnerMediaSlotsContract::class)->execute($owners, $slots, MediaActorData::system()))->toThrow($mode === 'foreign_connection' || $mode === 'foreign_table' ? TenantBoundaryViolation::class : InvalidArgumentException::class);
    expect(batchMediaStorageQueries())->toBe([]);
})->with(['unpersisted', '101', 'conflict', 'empty_slots', '21_slots', 'bad_slot', 'foreign_connection', 'foreign_table']);

it('bounds dense slot rows in SQL using each declaration and stable ordering', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $expected = [];
    foreach (['document' => 1, 'library' => 3, 'unbounded' => 10] as $slot => $ceiling) {
        for ($index = 0; $index < 35; $index++) {
            $asset = Media::factory()->create(['is_public' => false]);
            $association = batchMediaAssociation($owner, $asset, $slot, $index);
            if ($index < $ceiling) {
                $expected[$slot][] = $association->id;
            }
        }
    }
    app(TenantBoundary::class)->query(Media::query(), 'media.assets');
    $hydrated = 0;
    Media::retrieved(static function () use (&$hydrated): void {
        $hydrated++;
    });
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document', 'library', 'unbounded'], MediaActorData::system());
    foreach ($expected as $slot => $ids) {
        expect(array_map(static fn ($item): string => $item->associationId, batchMediaItems($result, $owner, $slot)))->toBe($ids);
    }
    expect($hydrated)->toBe(14)->and(DB::getQueryLog())->toHaveCount(4)
        ->and(DB::getQueryLog()[1]['query'])->toContain('ROW_NUMBER()', 'nvl_media_rank');
});

it('uses canonical collection identity rather than stale slot metadata', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $asset = Media::factory()->create(['is_public' => false]);
    $association = batchMediaAssociation($owner, $asset, 'library');
    $association->update(['metadata' => ['slot' => 'document']]);
    $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document', 'library'], MediaActorData::system());
    expect(batchMediaItems($result, $owner))->toBe([])->and(batchMediaItems($result, $owner, 'library')[0]->mediaId)->toBe($asset->id);
});

it('excludes inactive denied deleted and unavailable assets before rank and host existence', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $policy = app(MediaBatchAuthorization::class);
    foreach (MediaLifecycleStatus::cases() as $status) {
        $asset = Media::factory()->create(['status' => $status, 'is_public' => false]);
        batchMediaAssociation($owner, $asset, 'unbounded');
    }
    foreach (['inactive', 'denied_asset', 'denied_association', 'deleted'] as $reason) {
        $asset = Media::factory()->create(['is_public' => false]);
        $assoc = batchMediaAssociation($owner, $asset, 'unbounded', 0, $reason !== 'inactive');
        if ($reason === 'denied_asset') {
            $policy->deniedAssets[] = $asset->id;
        }
        if ($reason === 'denied_association') {
            $policy->deniedAssociations[] = $assoc->id;
        }
        if ($reason === 'deleted') {
            $asset->delete();
        }
    }
    $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['unbounded'], MediaActorData::system());
    expect(batchMediaItems($result, $owner, 'unbounded'))->toHaveCount(2)
        ->and(BatchMediaOwner::query()->select(['id', 'name'])->hasNvlMedia('unbounded', MediaActorData::system(), $policy)->first()?->getAttributes())->toBe(['id' => $owner->id, 'name' => 'Owner']);
});

it('requires both association and asset view in batch and host queries', function (string $denial): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $asset = Media::factory()->create(['is_public' => true]);
    $assoc = batchMediaAssociation($owner, $asset);
    $policy = app(MediaBatchAuthorization::class);
    if ($denial === 'asset') {
        $policy->deniedAssets[] = $asset->id;
    } else {
        $policy->deniedAssociations[] = $assoc->id;
    }
    $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system());
    expect(batchMediaItems($result, $owner))->toBe([])
        ->and(BatchMediaOwner::query()->hasNvlMedia('document', MediaActorData::system(), $policy)->exists())->toBeFalse();
})->with(['asset', 'association']);

it('fails unsupported host asset view without calling scopeMedia with an empty batch', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $policy = app(MediaBatchAuthorization::class);
    $policy->unsupportedHost = true;
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => BatchMediaOwner::query()->hasNvlMedia('document', MediaActorData::system(), $policy))->toThrow(MediaBatchReadException::class, 'host View adapter');
    expect(batchMediaStorageQueries())->toBe([]);
});

it('returns null when a public original URL is unavailable and never calls private delivery', function (): void {
    Storage::fake('public');
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $asset = Media::factory()->create(['is_public' => true]);
    batchMediaAssociation($owner, $asset);
    config()->set('filesystems.disks.public.url', null);
    config()->set('nvl-media.assets.public_route_name', 'missing.public.asset');
    $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system());
    expect(batchMediaItems($result, $owner)[0]->url)->toBeNull();
});

it('preserves object map identities order duplicates and numeric slots', function (): void {
    Relation::morphMap([], false);
    try {
        $owner = NumericBatchMediaOwner::query()->create(['name' => 'Owner']);
        $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner, $owner], ['0', 'document', '0'], MediaActorData::system());
        $json = json_decode($result->toJson());
        expect($result->order)->toBe([['type' => '7', 'id' => $owner->id]])
            ->and($json->owners)->toBeInstanceOf(stdClass::class)->and($json->owners->{'7'})->toBeInstanceOf(stdClass::class)
            ->and($json->owners->{'7'}->{$owner->id}->slots)->toBeInstanceOf(stdClass::class)
            ->and($json->owners->{'7'}->{$owner->id}->slots->{'0'})->toBe([]);
    } finally {
        Relation::morphMap([], false);
    }
});

it('preserves imperative policy bindings before and after package registration', function (): void {
    $policy = Mockery::mock(MediaAuthorization::class);
    app()->instance(MediaAuthorization::class, $policy);
    (new MediaServiceProvider(app()))->register();
    expect(app(MediaAuthorization::class))->toBe($policy);
    $later = Mockery::mock(MediaAuthorization::class);
    app()->instance(MediaAuthorization::class, $later);
    expect(app(MediaAuthorization::class))->toBe($later);
    $batch = new BatchMediaAuthorization;
    app()->instance(MediaBatchAuthorization::class, $batch);
    (new MediaServiceProvider(app()))->register();
    expect(app(MediaBatchAuthorization::class))->toBe($batch);
});

it('holds the absolute twenty thousand slot transfer ceiling before hydration', function (): void {
    $owners = [];
    for ($index = 0; $index < 100; $index++) {
        $owners[] = BatchMediaOwner::query()->create(['name' => 'Owner']);
    }
    $assets = Media::factory()->count(11)->create(['is_public' => false]);
    $slots = array_map(static fn (int $i): string => 'slot'.$i, range(0, 19));
    $rows = [];
    foreach ($owners as $owner) {
        foreach ($slots as $slot) {
            foreach ($assets as $index => $asset) {
                $rows[] = ['id' => (string) Str::uuid(), 'media_id' => $asset->id, 'associable_type' => $owner->getMorphClass(), 'associable_id' => $owner->id, 'collection' => $slot, 'order' => $index, 'is_active' => true];
            }
        }
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table((new MediaAssociation)->getTable())->insert($chunk);
    }
    app(TenantBoundary::class)->query(Media::query(), 'media.assets');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListOwnerMediaSlotsContract::class)->execute($owners, $slots, MediaActorData::system());
    $queries = DB::getQueryLog();
    expect($queries)->toHaveCount(4);
    DB::disableQueryLog();
    $ranked = $queries[1];
    expect(DB::select($ranked['query'], $ranked['bindings']))->toHaveCount(20000);
    foreach ($owners as $owner) {
        foreach ($slots as $slot) {
            expect(batchMediaItems($result, $owner, $slot))->toHaveCount(10);
        }
    }
});

it('rejects oversized locale fallback before any SQL', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $locales = ['en', 'bg', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'sv', 'da', 'no'];
    config()->set('nvl-translatable.locales', $locales);
    config()->set('nvl-translatable.fallback_locales', array_slice($locales, 1));
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system(), 'en'))->toThrow(InvalidArgumentException::class, 'ten requested');
    expect(DB::getQueryLog())->toBe([]);
});

it('rejects actual host connection FROM alias and union drift before package SQL', function (string $mode): void {
    config(['database.connections.batch_foreign' => config('database.connections.testing')]);
    $query = BatchMediaOwner::query();
    if ($mode === 'connection') {
        $query->setQuery(DB::connection('batch_foreign')->table($query->getModel()->getTable()));
    }
    if ($mode === 'from') {
        $query->from('foreign_owners');
    }
    if ($mode === 'alias') {
        $query->from($query->getModel()->getTable().' as owners');
    }
    if ($mode === 'union') {
        $query->union(BatchMediaOwner::query());
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => $query->hasNvlMedia('document', MediaActorData::system(), app(MediaBatchAuthorization::class)))->toThrow(TenantBoundaryViolation::class);
    expect(DB::getQueryLog())->toBe([]);
})->with(['connection', 'from', 'alias', 'union']);

it('rejects declared connection removal with an unchanged effective connection', function (): void {
    config(['database.connections.batch_declared' => config('database.connections.testing'), 'nvl-media.connection' => 'batch_declared']);
    $prior = DB::getDefaultConnection();
    DB::setDefaultConnection('batch_declared');
    try {
        expect(fn () => app(MediaBatchOwnerAdmission::class)->assertPrototype((new DeclaredConnectionBatchMediaOwner)->setConnection(null)))->toThrow(TenantBoundaryViolation::class);
    } finally {
        DB::setDefaultConnection($prior);
    }
});

it('keeps two owner dependent private asset views paired across batch and host', function (): void {
    $allowed = BatchMediaOwner::query()->create(['name' => 'Allowed']);
    $denied = BatchMediaOwner::query()->create(['name' => 'Denied']);
    $asset = Media::factory()->create(['is_public' => false]);
    batchMediaAssociation($allowed, $asset);
    $deniedAssociation = batchMediaAssociation($denied, $asset);
    $policy = new class($allowed->id) extends BatchMediaAuthorization
    {
        public function __construct(private string $viewOwner) {}

        public function scopeAssociations(Builder $query, OwnerBatch $batch, MediaActorData $actor): void
        {
            $query->whereHas('media', fn (Builder $asset) => $asset->whereRaw($query->getModel()->qualifyColumn('associable_id').' = ?', [$this->viewOwner]));
        }

        public function scopeHostAssociations(Builder $query, Model $ownerPrototype, MediaActorData $actor, string $slot): void
        {
            $query->whereHas('media', fn (Builder $asset) => $asset->whereRaw($query->getModel()->qualifyColumn('associable_id').' = ?', [$this->viewOwner]));
        }
    };
    app()->instance(MediaBatchAuthorization::class, $policy);
    $result = app(ListOwnerMediaSlotsContract::class)->execute([$allowed, $denied], ['document'], MediaActorData::system());
    expect(batchMediaItems($result, $allowed)[0]->url)->toBeNull()->and(batchMediaItems($result, $denied))->toBe([])
        ->and(BatchMediaOwner::query()->hasNvlMedia('document', MediaActorData::system(), $policy)->pluck('id')->all())->toBe([$allowed->id]);
});

it('preserves host global scopes and selections and admits canonical loaded owners', function (): void {
    $visible = BatchMediaOwner::query()->create(['name' => 'Visible']);
    $hidden = BatchMediaOwner::query()->create(['name' => 'Hidden']);
    foreach ([$visible, $hidden] as $owner) {
        batchMediaAssociation($owner, Media::factory()->create(['is_public' => false]));
    }
    BatchMediaOwner::addGlobalScope('batch-visible', static fn (Builder $query) => $query->where('name', 'Visible'));
    $visible->name = 'Forged';
    app(ListOwnerMediaSlotsContract::class)->execute([$visible], ['document'], MediaActorData::system());
    expect(app(MediaBatchAuthorization::class)->admitted[0]->name)->toBe('Visible')
        ->and(BatchMediaOwner::query()->select('id', 'name')->hasNvlMedia('document', MediaActorData::system(), app(MediaBatchAuthorization::class))->get()->modelKeys())->toBe([$visible->id]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListOwnerMediaSlotsContract::class)->execute([$hidden], ['document'], MediaActorData::system()))->toThrow(TenantBoundaryViolation::class);
    expect(batchMediaStorageQueries())->toBe([]);
});

it('contains broad policy OR clauses within mandatory ownership active lifecycle and slot predicates', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $foreign = BatchMediaOwner::query()->create(['name' => 'Foreign']);
    $allowed = Media::factory()->create(['is_public' => false]);
    batchMediaAssociation($owner, $allowed);
    batchMediaAssociation($foreign, Media::factory()->create(['is_public' => false]));
    batchMediaAssociation($owner, Media::factory()->create(['is_public' => false]), 'library');
    batchMediaAssociation($owner, Media::factory()->create(['status' => MediaLifecycleStatus::PendingScan]), 'document');
    batchMediaAssociation($owner, Media::factory()->create(), 'document', 0, false);
    $policy = new class extends BatchMediaAuthorization
    {
        public function scopeAssociations(Builder $query, OwnerBatch $batch, MediaActorData $actor): void
        {
            $query->whereRaw('1 = 0')->orWhereRaw('1 = 1');
        }

        public function scopeMedia(Builder $query, OwnerBatch $batch, MediaActorData $actor): void
        {
            $query->whereRaw('1 = 0')->orWhereRaw('1 = 1');
        }

        public function scopeHostAssociations(Builder $query, Model $ownerPrototype, MediaActorData $actor, string $slot): void
        {
            $query->whereRaw('1 = 0')->orWhereRaw('1 = 1');
        }
    };
    app()->instance(MediaBatchAuthorization::class, $policy);
    $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system());
    expect(batchMediaItems($result, $owner))->toHaveCount(1)->and(batchMediaItems($result, $owner)[0]->mediaId)->toBe($allowed->id)
        ->and(BatchMediaOwner::query()->whereKey($owner->id)->hasNvlMedia('document', MediaActorData::system(), $policy)->pluck('id')->all())->toBe([$owner->id]);
});

it('uses byte exact morph and string keys without Cartesian or collation leakage', function (): void {
    Schema::create('batch_string_media_owners', function (Blueprint $table): void {
        $id = $table->string('id')->primary();
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $id->collation('utf8mb4_bin');
        }
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    });
    Relation::morphMap(['host:strings' => BatchStringMediaOwner::class, 'host:other' => OtherBatchStringMediaOwner::class], false);
    try {
        $owners = [];
        foreach (['A', 'a', '0', '007'] as $id) {
            $owner = new BatchStringMediaOwner(['name' => 'Owner']);
            $owner->forceFill(['id' => $id])->save();
            $owners[] = $owner;
            batchMediaAssociation($owner, Media::factory()->create(['is_public' => false]));
        }
        $other = OtherBatchStringMediaOwner::query()->findOrFail('a');
        batchMediaAssociation($other, Media::factory()->create(['is_public' => false]));
        $trailing = batchMediaAssociation($owners[0], Media::factory()->create(['is_public' => false]));
        $trailing->update(['associable_id' => 'A ']);
        $stale = batchMediaAssociation($owners[0], Media::factory()->create(['is_public' => false]), 'unbounded');
        $stale->update(['associable_type' => 'Host:Strings', 'collection' => 'document']);
        $partial = app(ListOwnerMediaSlotsContract::class)->execute([$owners[0], $other], ['document'], MediaActorData::system());
        expect(batchMediaItems($partial, $owners[0]))->toHaveCount(1)->and(batchMediaItems($partial, $other))->toHaveCount(1);
        $all = app(ListOwnerMediaSlotsContract::class)->execute($owners, ['document'], MediaActorData::system());
        foreach ($owners as $owner) {
            expect(batchMediaItems($all, $owner))->toHaveCount(1)
                ->and(BatchStringMediaOwner::query()->whereKey($owner->id)->hasNvlMedia('document', MediaActorData::system(), app(MediaBatchAuthorization::class))->count())->toBe(1);
        }
    } finally {
        Relation::morphMap([], false);
    }
});

it('casts native integer keys for host correlation and excludes noncanonical textual keys', function (): void {
    Schema::create('batch_integer_media_owners', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    });
    $owner = IntegerBatchMediaOwner::query()->create(['name' => 'Owner']);
    $good = batchMediaAssociation($owner, Media::factory()->create(['is_public' => false]));
    $legacy = batchMediaAssociation($owner, Media::factory()->create(['is_public' => false]));
    $legacy->update(['associable_id' => '00'.$owner->getKey()]);
    $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system());
    expect(batchMediaItems($result, $owner))->toHaveCount(1)->and(batchMediaItems($result, $owner)[0]->associationId)->toBe($good->id)
        ->and(IntegerBatchMediaOwner::query()->hasNvlMedia('document', MediaActorData::system(), app(MediaBatchAuthorization::class))->count())->toBe(1);
    $json = json_decode($result->toJson());
    expect($json->owners->{$owner->getMorphClass()})->toBeInstanceOf(stdClass::class);
});

it('compiles exact canonical keys window partitions and host correlation for supported drivers', function (string $driver): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    config(['database.connections.batch_compile' => ['driver' => $driver, 'host' => '127.0.0.1', 'database' => 'never_connect', 'username' => 'unused', 'password' => '', 'prefix' => '']]);
    $prior = DB::getDefaultConnection();
    Media::addGlobalScope('tenant', static function (Builder $query): void {});
    MediaAssociation::addGlobalScope('tenant', static function (Builder $query): void {});
    DB::setDefaultConnection('batch_compile');
    app()->instance(TenantBoundary::class, new class implements TenantBoundary
    {
        public function query(Builder $query, string $resource): Builder
        {
            return $query;
        }

        public function assertRecord(Model $record, string $resource): void {}

        public function attributes(string $resource): array
        {
            return [];
        }

        public function key(string $resource, string $identity): string
        {
            return $identity;
        }
    });
    try {
        $builder = app(MediaBatchQueryBuilder::class);
        $batch = OwnerBatch::fromModels([$owner]);
        $ceilings = [$owner->getMorphClass() => [$owner->id => ['document' => 1]]];
        $policy = app(MediaBatchAuthorization::class);
        $ranked = $builder->bounded($builder->batch($batch, $ceilings, MediaActorData::system(), $policy), $batch, $ceilings);
        $query = BatchMediaOwner::query();
        MediaBatchOwnerAdmission::exact($query, $query->getModel()->getQualifiedKeyName(), $owner->id);
        expect($query->toSql())->toContain('CAST(')
            ->and($ranked->toSql())->toContain('ROW_NUMBER() OVER', 'PARTITION BY', 'nvl_media_rank', 'is_active', 'deleted_at')
            ->and($ranked->getBindings())->toContain(1);
        $host = BatchMediaOwner::query()->select('id')->hasNvlMedia('document', MediaActorData::system(), $policy)->toSql();
        match ($driver) {
            'pgsql' => expect($host)->toContain('AS TEXT)', 'COLLATE "C"', 'OCTET_LENGTH'),
            'mysql', 'mariadb' => expect($host)->toContain('AS CHAR)', 'BINARY', 'OCTET_LENGTH'),
            'sqlite' => expect($host)->toContain('AS TEXT)', 'COLLATE BINARY', 'AS BLOB'),
        };
    } finally {
        DB::setDefaultConnection($prior);
    }
})->with(['sqlite', 'pgsql', 'mysql', 'mariadb']);

it('breaks equal order ties deterministically by association id', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $expected = [];
    foreach ([5, 2, 4, 1, 3] as $number) {
        $assoc = batchMediaAssociation($owner, Media::factory()->create(['is_public' => false]), 'library', 7);
        $id = sprintf('00000000-0000-4000-8000-%012d', $number);
        $assoc->forceFill(['id' => $id])->save();
        $expected[] = $id;
    }
    sort($expected);
    $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['library'], MediaActorData::system());
    expect(array_map(static fn ($item): string => $item->associationId, batchMediaItems($result, $owner, 'library')))->toBe(array_slice($expected, 0, 3));
});

it('keeps canonical soft deleted owners outside host existence after scope removal', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    batchMediaAssociation($owner, Media::factory()->create(['is_public' => false]));
    DB::table($owner->getTable())->where('id', $owner->id)->update(['deleted_at' => now()]);
    expect(BatchMediaOwner::query()->withoutGlobalScopes()->hasNvlMedia('document', MediaActorData::system(), app(MediaBatchAuthorization::class))->exists())->toBeFalse();
});

it('keeps total queries dependent on concrete class groups rather than owner count', function (int $count): void {
    $owners = [];
    for ($index = 0; $index < $count; $index++) {
        $class = $index % 2 === 0 ? BatchMediaOwner::class : OwnerSlotWorkflowModel::class;
        $owner = $class::query()->create(['name' => 'Owner']);
        batchMediaAssociation($owner, Media::factory()->create(['is_public' => false]));
        $owners[] = $owner;
    }
    app(TenantBoundary::class)->query(Media::query(), 'media.assets');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListOwnerMediaSlotsContract::class)->execute($owners, ['document'], MediaActorData::system());
    expect(DB::getQueryLog())->toHaveCount($count === 1 ? 4 : 5)->and($result->order)->toHaveCount($count);
    foreach ($owners as $owner) {
        expect(batchMediaItems($result, $owner))->toHaveCount(1);
    }
})->with([1, 25, 100]);

it('bounds translation transfer to the requested locale chain before projection', function (): void {
    $owner = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $asset = Media::factory()->create(['is_public' => false]);
    batchMediaAssociation($owner, $asset);
    foreach (['en', 'bg', 'fr', 'de', 'es', 'it', 'pt', 'nl', 'sv', 'da', 'no'] as $locale) {
        MediaTranslation::query()->create(['media_id' => $asset->id, 'locale' => $locale, 'title' => $locale]);
    }
    app(TenantBoundary::class)->query(Media::query(), 'media.assets');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system(), 'bg');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(4)->and(batchMediaItems($result, $owner)[0]->title)->toBe('bg');
    $translations = $queries[3];
    expect(DB::select($translations['query'], $translations['bindings']))->toHaveCount(2);
});

it('lets a complete host SQL adapter express canonical attribute dependent slot eligibility', function (): void {
    $good = BatchMediaOwner::query()->create(['name' => 'Owner']);
    $restricted = BatchMediaOwner::query()->create(['name' => 'Restricted']);
    foreach ([$good, $restricted] as $owner) {
        batchMediaAssociation($owner, Media::factory()->create(['is_public' => false]));
    }
    $policy = new class extends BatchMediaAuthorization
    {
        public function scopeHostAssociations(Builder $query, Model $ownerPrototype, MediaActorData $actor, string $slot): void
        {
            parent::scopeHostAssociations($query, $ownerPrototype, $actor, $slot);
            $query->where($ownerPrototype->qualifyColumn('name'), '!=', 'Restricted');
        }
    };
    expect(BatchMediaOwner::query()->hasNvlMedia('document', MediaActorData::system(), $policy)->pluck('id')->all())->toBe([$good->id]);
});
