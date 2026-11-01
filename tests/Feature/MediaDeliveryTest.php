<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaDeliveryMode;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaAssetService;
use Nvl\Media\Services\MediaDoctor;
use Nvl\Media\Services\MediaUrlResolver;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    Storage::fake('cdn', ['url' => 'https://cdn.example.test']);
    Storage::fake('masked', ['url' => 'https://origin.example.test']);
    Storage::fake('vault');
    Route::get('/authenticated-media/{media}', fn () => 'protected')->name('delivery.authenticated.show');
    config([
        'nvl-media.assets.authenticated_route_name' => 'delivery.authenticated.show',
        'nvl-media.allowed_disks' => ['cdn', 'masked', 'vault'],
        'nvl-media.delivery.disks.cdn' => [
            'public' => ['mode' => 'direct', 'allowed' => ['direct', 'application']], 'private' => false,
        ],
        'nvl-media.delivery.disks.masked' => [
            'public' => ['mode' => 'application', 'allowed' => ['application']],
        ],
        'nvl-media.delivery.disks.vault' => [
            'public' => false,
            'private' => ['mode' => 'application', 'allowed' => ['application', 'signed_application', 'temporary_storage']],
            'maximum_ttl' => 300,
        ],
    ]);
});

/** @param array<string, mixed> $attributes */
function deliveryAsset(array $attributes = []): Media
{
    return Media::factory()->create([
        'disk' => 'cdn', 'folder' => 'images', 'hash' => 'photo.jpg',
        'filename' => 'photo.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
        'is_public' => true, ...$attributes,
    ]);
}

/** Build the deliberate SQLite replica simulation from the canonical portable schema. */
function deliveryReplicaDatabase(string $name): Connection
{
    config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $originalConnection = config('nvl-media.connection');

    try {
        config(['nvl-media.connection' => $name]);
        foreach (['2024_02_01_000001_nvl_media_create_media_table.php', '2024_02_01_000003_nvl_media_create_media_image_variations_table.php'] as $migration) {
            (require __DIR__.'/../../database/migrations/'.$migration)->up();
        }
    } finally {
        config(['nvl-media.connection' => $originalConnection]);
    }

    return DB::connection($name);
}

it('uses independent public delivery policies for two disks with configured URLs', function (): void {
    $cdn = deliveryAsset();
    $masked = deliveryAsset(['disk' => 'masked', 'hash' => 'masked.jpg']);
    expect($cdn->getUrl())->toBe('https://cdn.example.test/media/images/photo.jpg')
        ->and($masked->getUrl())->toContain('/nvl/media/assets/'.$masked->id)
        ->toContain('version=')->not->toContain('origin.example.test');
});

it('rejects private records passed to public URL helpers', function (): void {
    $media = deliveryAsset(['disk' => 'vault', 'is_public' => false]);
    expect(fn () => $media->buildPublicUrl())->toThrow(RuntimeException::class);
    expect(fn () => app(MediaUrlResolver::class)->publicUrl($media))->toThrow(RuntimeException::class);
});

it('permits an explicit public mode override only when the disk permits it', function (): void {
    $cdn = deliveryAsset();
    $masked = deliveryAsset(['disk' => 'masked']);
    expect($cdn->getUrl(mode: MediaDeliveryMode::Application))->toContain('/nvl/media/assets/'.$cdn->id);
    expect(fn () => $masked->getUrl(mode: MediaDeliveryMode::Direct))->toThrow(RuntimeException::class);
});

it('uses the same selected policy for originals and current variations', function (): void {
    $media = deliveryAsset();
    $variation = MediaImageVariation::factory()->create([
        'media_id' => $media->id, 'label' => 'thumb', 'storage_path' => 'media/images/conversions/thumb.webp',
        'source_revision' => $media->revision, 'status' => 'available',
    ]);
    Storage::disk('cdn')->put($variation->getPath(), 'thumbnail');
    expect($media->getUrl('thumb'))->toStartWith('https://cdn.example.test/media/images/conversions/thumb.webp?version=')
        ->and($media->getUrl('thumb', MediaDeliveryMode::Application))->toContain('/nvl/media/assets/'.$media->id)
        ->toContain('v=thumb')->not->toContain('cdn.example.test');
});

it('returns an authenticated private route without minting a bearer capability', function (): void {
    $media = deliveryAsset(['disk' => 'vault', 'is_public' => false]);
    expect($media->getUrl())->toContain('/authenticated-media/'.$media->id)
        ->not->toContain('signature=')->not->toContain('expires=')->not->toContain('/storage/');
});

it('issues an explicitly authorized signed link bound to the current revision', function (): void {
    $media = deliveryAsset(['disk' => 'vault', 'is_public' => false]);
    $url = app(MediaUrlResolver::class)->signedUrl(SystemActor::Background, $media, now()->addMinute());
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect(URL::hasValidSignature(Request::create($url)))->toBeTrue()
        ->and($query['revision'])->toBe((string) $media->revision)
        ->and((int) $query['expires'])->toBeGreaterThan(now()->timestamp);
});

it('checks download authority before issuing private bearer links', function (): void {
    $media = deliveryAsset(['disk' => 'vault', 'is_public' => false]);
    app()->bind(MediaAuthorization::class, fn () => new class implements MediaAuthorization
    {
        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return $ability !== MediaAbility::Download;
        }
    });
    app()->forgetScopedInstances();
    expect(fn () => app(MediaUrlResolver::class)->signedUrl(SystemActor::Background, $media, now()->addMinute()))
        ->toThrow(AuthorizationException::class);
});

it('issues provider URLs only through an explicit bounded authorized operation', function (): void {
    $media = deliveryAsset(['disk' => 'vault', 'is_public' => false]);
    Storage::disk('vault')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expiration) => 'https://storage.example.test/'.$path.'?expires='.$expiration->getTimestamp(),
    );
    $resolver = app(MediaUrlResolver::class);
    expect($resolver->temporaryUrl(SystemActor::Background, $media, now()->addMinute()))
        ->toContain('https://storage.example.test/media/images/photo.jpg?expires=');
    expect(fn () => $resolver->temporaryUrl(SystemActor::Background, $media, now()->addMinutes(6)))
        ->toThrow(RuntimeException::class);
    expect(fn () => $resolver->temporaryUrl(SystemActor::Background, $media, now()->subSecond()))
        ->toThrow(RuntimeException::class);
});

it('fails without falling back when a configured application route is missing', function (): void {
    $media = deliveryAsset(['disk' => 'masked']);
    config(['nvl-media.assets.public_route_name' => 'missing.route']);
    expect(fn () => $media->getUrl())->toThrow(RuntimeException::class);
});

it('rejects unsupported disk visibility and malformed delivery settings', function (): void {
    $privateOnCdn = deliveryAsset(['is_public' => false]);
    expect(fn () => $privateOnCdn->getUrl())->toThrow(RuntimeException::class);
    config(['nvl-media.delivery.disks.cdn.public.mode' => 'typo']);
    expect(fn () => deliveryAsset()->getUrl())->toThrow(InvalidArgumentException::class);
});

it('rejects malformed effective policies before generating a URL', function (string $key, mixed $value): void {
    config([$key => $value]);
    expect(fn () => deliveryAsset()->getUrl())->toThrow(InvalidArgumentException::class);
})->with([
    'null visibility policy' => ['nvl-media.delivery.disks.cdn.public', null],
    'null disk policy' => ['nvl-media.delivery.disks.cdn', null],
    'zero maximum lifetime' => ['nvl-media.delivery.disks.cdn.maximum_ttl', 0],
    'unbounded maximum lifetime' => ['nvl-media.delivery.maximum_ttl', 604801],
]);

it('reports invalid declared delivery policies through the read-only doctor', function (): void {
    config(['nvl-media.delivery.disks.cdn.public.mode' => 'typo']);
    $check = collect(app(MediaDoctor::class)->inspect())->firstWhere('key', 'delivery.policy');
    expect($check)->not->toBeNull()->and($check['passed'])->toBeFalse();
});

it('never broadens a filtered named-variation capability to its original', function (): void {
    $media = deliveryAsset(['disk' => 'vault', 'is_public' => false]);
    MediaImageVariation::factory()->create([
        'media_id' => $media->id, 'label' => 'thumb', 'source_revision' => $media->revision, 'status' => 'available',
    ]);
    config(['nvl-media.assets.allowed_parameters' => []]);

    expect(fn () => app(MediaUrlResolver::class)->signedUrl(SystemActor::Background, $media, now()->addMinute(), ['v' => 'thumb']))
        ->toThrow(RuntimeException::class);
});

it('uses primary availability when a replica still reports an available private object', function (): void {
    $media = deliveryAsset(['disk' => 'vault', 'is_public' => false]);
    $default = config('database.default');
    $table = $media->getTable();
    $primary = deliveryReplicaDatabase('media-grants');
    $primary->table($table)->insert($media->getAttributes());
    $replica = deliveryReplicaDatabase('media-grants-replica');
    $replica->table($table)->insert($media->getAttributes());
    $primary->table($table)->where('id', $media->id)->update(['status' => 'quarantined']);
    $primary->setReadPdo($replica->getPdo());

    try {
        config(['database.default' => 'media-grants']);
        expect(fn () => app(MediaUrlResolver::class)->temporaryUrl(SystemActor::Background, $media, now()->addMinute()))
            ->toThrow(RuntimeException::class);
        expect(fn () => app(MediaAssetService::class)->resolve($media))
            ->toThrow(RuntimeException::class, 'not available for delivery');
    } finally {
        config(['database.default' => $default]);
        DB::purge('media-grants');
        DB::purge('media-grants-replica');
    }
});

it('rejects stale carried asset facts before resolving retained bytes', function (array $changes): void {
    $media = deliveryAsset(['disk' => 'masked']);
    Storage::disk('masked')->put($media->buildPath(), 'retained bytes');
    expect(app(MediaAssetService::class)->resolve($media)['path'])->toBe($media->buildPath());
    $media->fresh()->forceFill($changes)->save();

    expect(fn () => app(MediaAssetService::class)->resolve($media))->toThrow(RuntimeException::class);
})->with([
    'quarantined canonical asset' => [['status' => 'quarantined']],
    'private canonical asset' => [['visibility' => 'private']],
    'replaced canonical revision' => [['revision' => 2]],
    'changed canonical hash' => [['hash' => 'changed.jpg']],
    'changed canonical disk' => [['disk' => 'vault']],
]);

it('rechecks current primary variation facts instead of a loaded relation', function (array $changes): void {
    $media = deliveryAsset(['disk' => 'masked']);
    $variation = MediaImageVariation::factory()->create([
        'media_id' => $media->id, 'label' => 'thumb', 'source_revision' => $media->revision, 'status' => 'available',
    ]);
    Storage::disk('masked')->put($variation->getPath(), 'retained variation bytes');
    $media->load('imageVariations');
    expect(app(MediaAssetService::class)->resolve($media, 'thumb')['path'])->toBe($variation->getPath());
    $variation->fresh()->forceFill($changes)->save();

    expect(fn () => app(MediaAssetService::class)->resolve($media, 'thumb'))->toThrow(RuntimeException::class);
})->with([
    'quarantined variation' => [['status' => 'quarantined']],
    'stale source revision' => [['source_revision' => 0]],
]);

it('keeps primary parent paths for legacy variations despite a lagging replica', function (string $operation): void {
    $media = deliveryAsset(['disk' => 'vault', 'is_public' => false]);
    $variation = MediaImageVariation::factory()->create([
        'media_id' => $media->id, 'label' => 'thumb', 'storage_path' => null,
        'source_revision' => $media->revision, 'status' => 'available',
    ]);
    $variation->setRelation('media', $media);
    $expectedPath = $variation->getPath();
    $staleParent = clone $media;
    $staleParent->forceFill(['folder' => 'old-folder', 'hash' => 'old-photo.jpg']);
    $staleVariation = clone $variation;
    $staleVariation->setRelation('media', $staleParent);
    $stalePath = $staleVariation->getPath();
    Storage::disk('vault')->put($expectedPath, 'current bytes');
    Storage::disk('vault')->put($stalePath, 'retained old bytes');
    Storage::disk('vault')->buildTemporaryUrlsUsing(fn (string $path, DateTimeInterface $expiration): string => 'https://provider.example.test/'.$path);

    $default = config('database.default');
    $primary = deliveryReplicaDatabase('media-path-primary');
    $replica = deliveryReplicaDatabase('media-path-replica');
    foreach ([$media, $variation] as $record) {
        $table = $record->getTable();
        $primary->table($table)->insert($record->getAttributes());
        $replica->table($table)->insert($record instanceof Media ? $staleParent->getAttributes() : $record->getAttributes());
    }
    $primary->setReadPdo($replica->getPdo());

    try {
        config(['database.default' => 'media-path-primary']);
        expect(Media::query()->findOrFail($media->id)->hash)->toBe('old-photo.jpg');
        if ($operation === 'serve') {
            expect(app(MediaAssetService::class)->resolve($media, 'thumb')['path'])->toBe($expectedPath);
        } else {
            expect(app(MediaUrlResolver::class)->temporaryUrl(SystemActor::Background, $media, now()->addMinute(), 'thumb'))
                ->toBe('https://provider.example.test/'.$expectedPath);
        }
    } finally {
        config(['database.default' => $default]);
        DB::purge('media-path-primary');
        DB::purge('media-path-replica');
    }
})->with(['serve', 'provider grant']);
