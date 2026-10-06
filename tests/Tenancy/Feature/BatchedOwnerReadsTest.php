<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Contracts\ListOwnerMediaSlotsContract;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Services\MediaBatchQueryBuilder;
use Nvl\Media\Services\MediaUrlResolver;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Media\Tests\Stubs\BatchMediaAuthorization;
use Nvl\Media\Tests\Stubs\BatchMediaOwner;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

beforeEach(function (): void {
    $driver = getenv('NVL_C1_TEST_DRIVER');
    if (is_string($driver) && $driver !== '') {
        expect(DB::connection()->getDriverName())->toBe($driver);
    }
    app()->instance(MediaBatchAuthorization::class, new BatchMediaAuthorization);
});

it('keeps deleted tenant owners outside direct and scoped reads with caller ORs', function (bool $direct): void {
    $scenario = MediaTenancyScenario::install();
    $scenario->run($scenario::A, function () use ($direct, $scenario): void {
        $deleted = tenantBatchMediaOwner();
        $live = tenantBatchMediaOwner();
        foreach ([$deleted, $live] as $owner) {
            $asset = Media::factory()->create([
                ...app(TenantBoundary::class)->attributes('media.assets'),
                'is_public' => false,
                'storage_path' => 'media/tenants/'.$scenario::A.'/'.bin2hex(random_bytes(8)),
            ]);
            tenantBatchMediaAssociation($owner, $asset);
        }
        DB::table($deleted->getTable())->where('id', $deleted->id)->update(['deleted_at' => now()]);
        $query = BatchMediaOwner::query()->withoutGlobalScopes()->whereKey($deleted->id)
            ->orWhere($live->getQualifiedKeyName(), $live->id);
        $actor = MediaActorData::system();
        $policy = app(MediaBatchAuthorization::class);
        if ($direct) {
            app(MediaBatchQueryBuilder::class)->has($query, 'document', $actor, $policy);
        } else {
            $query->hasNvlMedia('document', $actor, $policy);
        }

        expect($query->pluck('id')->all())->toBe([$live->id]);
    });
})->with([true, false]);

/** Create canonical read fixtures inside the active registered tenant. */
function tenantBatchMediaOwner(): BatchMediaOwner
{
    $owner = new BatchMediaOwner(['name' => 'Owner']);
    $owner->forceFill(app(TenantBoundary::class)->attributes('test.media-owners'))->save();

    return $owner;
}

/** Persist inherited association ownership with its canonical parent. */
function tenantBatchMediaAssociation(BatchMediaOwner $owner, Media $asset): MediaAssociation
{
    $association = new MediaAssociation([
        'media_id' => $asset->id, 'associable_type' => $owner->getMorphClass(), 'associable_id' => $owner->id,
        'collection' => 'document', 'is_active' => true,
    ]);
    $association->forceFill(['tenant_id' => $asset->tenant_id])->save();

    return $association;
}

/** Detect actual logical Media SQL independently of engine quoting.
 * @return list<array{query: string, bindings: array<int, mixed>, time: float}>
 */
function tenantBatchMediaStorageQueries(): array
{
    $tables = [(new Media)->getTable(), (new MediaAssociation)->getTable(), (new MediaTranslation)->getTable()];

    return array_values(array_filter(DB::getQueryLog(), static function (array $entry) use ($tables): bool {
        $sql = str_replace(['"', '`', '[', ']'], '', strtolower($entry['query']));
        foreach ($tables as $table) {
            if (preg_match('/\b(?:from|join)\s+'.preg_quote(strtolower($table), '/').'\b/', $sql) === 1) {
                return true;
            }
        }

        return false;
    }));
}

it('keeps real enabled tenancy localized total SQL fixed at all owner counts', function (int $count): void {
    $scenario = MediaTenancyScenario::install();
    $scenario->run($scenario::A, function () use ($count): void {
        $owners = [];
        for ($index = 0; $index < $count; $index++) {
            $owner = tenantBatchMediaOwner();
            $asset = Media::factory()->create([...app(TenantBoundary::class)->attributes('media.assets'), 'is_public' => false, 'storage_path' => 'media/tenants/'.app(TenantBoundary::class)->attributes('media.assets')['tenant_id'].'/'.bin2hex(random_bytes(8))]);
            tenantBatchMediaAssociation($owner, $asset);
            foreach (['en' => 'Fallback', 'bg' => 'Превод'] as $locale => $title) {
                $translation = new MediaTranslation(['media_id' => $asset->id, 'locale' => $locale, 'title' => $title, 'alt' => '']);
                $translation->forceFill(['tenant_id' => $asset->tenant_id])->save();
            }
            $owners[] = $owner;
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(ListOwnerMediaSlotsContract::class)->execute($owners, ['document', '0'], MediaActorData::system(), 'bg');
        expect(DB::getQueryLog())->toHaveCount(4);
        foreach ($owners as $owner) {
            $item = $result->owners->{$owner->getMorphClass()}->{$owner->id}->slots->document[0];
            expect($item->title)->toBe('Превод')->and($item->altText)->toBe('')->and($item->url)->toBeNull();
        }
        expect(DB::getQueryLog())->toHaveCount(4);
    });
})->with([1, 25, 100]);

it('retains adopted original storage paths for public batch URLs', function (): void {
    config(['filesystems.disks.tenant-disk.url' => 'https://assets.example.test/objects']);
    $scenario = MediaTenancyScenario::install();
    Storage::fake('tenant-disk', ['url' => 'https://assets.example.test/objects']);
    $scenario->run($scenario::A, function () use ($scenario): void {
        $owner = tenantBatchMediaOwner();
        $asset = Media::factory()->create([
            ...app(TenantBoundary::class)->attributes('media.assets'),
            'is_public' => true,
            'folder' => 'misleading-folder',
            'hash' => 'misleading-hash.txt',
            'storage_path' => 'media/tenants/'.$scenario::A.'/published/report.txt',
        ]);
        tenantBatchMediaAssociation($owner, $asset);
        $expected = 'https://assets.example.test/objects/media/tenants/'.$scenario::A.'/published/report.txt';
        expect(app(MediaUrlResolver::class)->publicUrl($asset, []))->toBe($expected);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system());

        expect($result->owners->{$owner->getMorphClass()}->{$owner->id}->slots->document[0]->url)->toBe($expected)
            ->and(DB::getQueryLog())->toHaveCount(4);
    });
});

it('reloads forged foreign owner facts and denies before Media SQL', function (): void {
    $scenario = MediaTenancyScenario::install();
    $foreign = $scenario->run($scenario::B, fn () => tenantBatchMediaOwner());
    $foreign->tenant_id = $scenario::A;
    $scenario->run($scenario::A, function () use ($foreign): void {
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(fn () => app(ListOwnerMediaSlotsContract::class)->execute([$foreign], ['document'], MediaActorData::system()))->toThrow(TenantBoundaryViolation::class);
        expect(tenantBatchMediaStorageQueries())->toBe([]);
    });
});

it('contains broad SQL policy OR and removed host scopes inside canonical tenant parents', function (): void {
    $scenario = MediaTenancyScenario::install();
    $owners = [];
    foreach ([$scenario::A, $scenario::B] as $tenant) {
        $owners[] = $scenario->run($tenant, function (): BatchMediaOwner {
            $owner = tenantBatchMediaOwner();
            tenantBatchMediaAssociation($owner, Media::factory()->create([...app(TenantBoundary::class)->attributes('media.assets'), 'is_public' => false, 'storage_path' => 'media/tenants/'.app(TenantBoundary::class)->attributes('media.assets')['tenant_id'].'/'.bin2hex(random_bytes(8))]));

            return $owner;
        });
    }
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
    $scenario->run($scenario::A, function () use ($owners, $policy): void {
        $result = app(ListOwnerMediaSlotsContract::class)->execute([$owners[0]], ['document'], MediaActorData::system());
        expect($result->owners->{$owners[0]->getMorphClass()}->{$owners[0]->id}->slots->document)->toHaveCount(1)
            ->and(BatchMediaOwner::query()->withoutGlobalScopes()->select('id')->hasNvlMedia('document', MediaActorData::system(), $policy)->pluck('id')->all())->toBe([$owners[0]->id]);
    });
});

it('denies policy admission before enabled tenancy Media queries', function (): void {
    $scenario = MediaTenancyScenario::install();
    $scenario->run($scenario::A, function (): void {
        $owner = tenantBatchMediaOwner();
        app(MediaBatchAuthorization::class)->denyOwners = true;
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(fn () => app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system()))->toThrow(AuthorizationException::class);
        expect(tenantBatchMediaStorageQueries())->toBe([]);
    });
});

it('excludes forged inherited child tenant rows and foreign asset parents', function (): void {
    $scenario = MediaTenancyScenario::install();
    $foreignAsset = $scenario->run($scenario::B, fn (): Media => Media::factory()->create([
        ...app(TenantBoundary::class)->attributes('media.assets'), 'is_public' => false,
        'storage_path' => 'media/tenants/'.$scenario::B.'/foreign',
    ]));
    $scenario->run($scenario::A, function () use ($scenario, $foreignAsset): void {
        $owner = tenantBatchMediaOwner();
        $asset = Media::factory()->create([
            ...app(TenantBoundary::class)->attributes('media.assets'), 'is_public' => false,
            'storage_path' => 'media/tenants/'.$scenario::A.'/canonical',
        ]);
        $second = Media::factory()->create([
            ...app(TenantBoundary::class)->attributes('media.assets'), 'is_public' => false,
            'storage_path' => 'media/tenants/'.$scenario::A.'/second',
        ]);
        tenantBatchMediaAssociation($owner, $asset);
        // Exercise read defense against corruption independently of composite tenant FKs.
        foreach ([(new MediaAssociation)->getTable(), (new MediaTranslation)->getTable()] as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (in_array('tenant_id', $foreignKey['columns'], true)) {
                    $key = DB::connection()->getDriverName() === 'sqlite' ? $foreignKey['columns'] : $foreignKey['name'];
                    Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->dropForeign($key));
                }
            }
        }
        foreach ([[$second, $scenario::B], [$foreignAsset, $scenario::A]] as [$parent, $childTenant]) {
            DB::table((new MediaAssociation)->getTable())->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $childTenant, 'media_id' => $parent->id,
                'associable_type' => $owner->getMorphClass(), 'associable_id' => $owner->id,
                'collection' => 'document', 'is_active' => true, 'order' => 0,
            ]);
        }
        foreach ([['en', $scenario::A, 'Canonical fallback'], ['bg', $scenario::B, 'Foreign title']] as [$locale, $tenant, $title]) {
            DB::table((new MediaTranslation)->getTable())->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'media_id' => $asset->id,
                'locale' => $locale, 'title' => $title,
            ]);
        }
        $result = app(ListOwnerMediaSlotsContract::class)->execute([$owner], ['document'], MediaActorData::system(), 'bg');
        $items = $result->owners->{$owner->getMorphClass()}->{$owner->id}->slots->document;
        expect($items)->toHaveCount(1)->and($items[0]->mediaId)->toBe($asset->id)
            ->and($items[0]->title)->toBe('Canonical fallback')->and($items[0]->url)->toBeNull()
            ->and(BatchMediaOwner::query()->withoutGlobalScopes()->hasNvlMedia('document', MediaActorData::system(), app(MediaBatchAuthorization::class))->pluck('id')->all())->toBe([$owner->id]);
    });
});
