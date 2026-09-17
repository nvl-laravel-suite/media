<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Actions\GrantMediaToTenantAction;
use Nvl\Media\Actions\ImportPlatformMediaAction;
use Nvl\Media\Actions\RevokeMediaTenantGrantAction;
use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Media\Data\MediaCatalogSnapshot;
use Nvl\Media\Data\Mutations\ImportPlatformMediaData;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Events\MediaCatalogGrantAudited;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Models\Media;
use Nvl\Media\Tests\Fixtures\MediaTenancyDirectory;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\ValueObjects\TenantId;

it('keeps a completed catalog copy after revocation but rejects a new import', function (): void {
    $scenario = MediaTenancyScenario::install(catalogCopies: true);
    Event::fake([MediaCatalogGrantAudited::class]);
    $source = $scenario->platform(fn (): Media => Media::query()->forceCreate([
        ...app(TenantBoundary::class)->attributes('media.assets'),
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'disk' => 'tenant-disk',
        'filename' => 'catalog.txt',
        'hash' => 'catalog.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size' => 7,
        'digest' => hash('sha256', 'catalog'),
        'storage_path' => 'media/platform/catalog.txt',
        'type' => MediaType::DOCUMENT,
        'status' => MediaLifecycleStatus::Available,
    ]));
    $scenario->platform(fn () => Storage::disk($source->disk)->put($source->buildPath(), 'catalog'));
    $grant = $scenario->platform(fn () => app(GrantMediaToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));
    Event::assertDispatched(MediaCatalogGrantAudited::class, static fn (MediaCatalogGrantAudited $event): bool => $event->operation === 'created'
        && $event->grantId === $grant->id && $event->tenantId === $scenario::A && $event->mediaId === $source->id);
    $request = new ImportPlatformMediaData($grant->id, $grant->revision, $source->revision, (string) Str::uuid());
    $copy = $scenario->run($scenario::A, fn () => app(ImportPlatformMediaAction::class)->execute($request));
    $scenario->platform(fn () => app(RevokeMediaTenantGrantAction::class)->execute($grant->id, $grant->revision));
    Event::assertDispatched(MediaCatalogGrantAudited::class, static fn (MediaCatalogGrantAudited $event): bool => $event->operation === 'revoked'
        && $event->grantId === $grant->id && $event->tenantId === $scenario::A && $event->mediaId === $source->id);

    expect($scenario->run($scenario::A, fn () => Media::query()->findOrFail($copy->id)->digest))->toBe($source->digest);
    expect(fn () => $scenario->run($scenario::A, fn () => app(ImportPlatformMediaAction::class)->execute(
        new ImportPlatformMediaData($grant->id, $grant->revision, $source->revision, (string) Str::uuid()),
    )))->toThrow(TenantBoundaryViolation::class);
    $refreshed = $scenario->platform(fn () => app(GrantMediaToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));
    Event::assertDispatched(MediaCatalogGrantAudited::class, static fn (MediaCatalogGrantAudited $event): bool => $event->operation === 'refreshed'
        && $event->grantId === $refreshed->id && $event->grantRevision === $refreshed->revision);
});

it('dispatches imported image variations only after the root transaction commits', function (): void {
    $scenario = MediaTenancyScenario::install(catalogCopies: true);
    Bus::fake([GenerateImageVariationJob::class]);
    config()->set('media.queue.connection', 'database');
    $bytes = 'catalog-image';
    $source = $scenario->platform(fn (): Media => Media::query()->forceCreate([
        ...app(TenantBoundary::class)->attributes('media.assets'),
        'is_public' => false, 'visibility' => MediaVisibility::Private, 'disk' => 'tenant-disk',
        'filename' => 'catalog.png', 'hash' => 'catalog.png', 'extension' => 'png',
        'mime_type' => 'image/png', 'size' => strlen($bytes), 'digest' => hash('sha256', $bytes),
        'storage_path' => 'media/platform/catalog.png', 'type' => MediaType::IMAGE,
        'status' => MediaLifecycleStatus::Available,
    ]));
    $scenario->platform(fn () => Storage::disk($source->disk)->put($source->buildPath(), $bytes));
    $grant = $scenario->platform(fn () => app(GrantMediaToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));

    $scenario->run($scenario::A, function () use ($grant): void {
        $imports = app(MediaCatalogImport::class);
        $snapshot = $imports->inspect($grant->id);
        $file = $imports->stage($snapshot);
        (new Media)->getConnection()->transaction(function () use ($imports, $snapshot, $file): void {
            $imports->persist($snapshot, $file, (string) Str::uuid());
            Bus::assertNotDispatched(GenerateImageVariationJob::class);
        });
    });

    Bus::assertDispatched(GenerateImageVariationJob::class);
});

it('requires the caller transaction and removes a staged object when the graph rolls back', function (): void {
    $scenario = MediaTenancyScenario::install(catalogCopies: true);
    $source = $scenario->platform(fn (): Media => Media::query()->forceCreate([
        ...app(TenantBoundary::class)->attributes('media.assets'),
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'disk' => 'tenant-disk',
        'filename' => 'rollback.txt',
        'hash' => 'rollback.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size' => 8,
        'digest' => hash('sha256', 'rollback'),
        'storage_path' => 'media/platform/rollback.txt',
        'type' => MediaType::DOCUMENT,
        'status' => MediaLifecycleStatus::Available,
    ]));
    $scenario->platform(fn () => Storage::disk($source->disk)->put($source->buildPath(), 'rollback'));
    $grant = $scenario->platform(fn () => app(GrantMediaToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));

    $scenario->run($scenario::A, function () use ($grant, $source): void {
        $port = app(MediaCatalogImport::class);
        $snapshot = $port->inspect($grant->id);
        $file = $port->stage($snapshot);
        expect(fn () => $port->persist($snapshot, $file, (string) Str::uuid()))
            ->toThrow(TenantBoundaryViolation::class);

        expect(fn () => (new Media)->getConnection()->transaction(function () use ($port, $snapshot, $file): void {
            $port->persist($snapshot, $file, (string) Str::uuid());
            throw new RuntimeException('graph rollback');
        }))->toThrow(RuntimeException::class, 'graph rollback');

        expect(Storage::disk($file->disk)->exists($file->storagePath))->toBeFalse()
            ->and(Media::query()->where('catalog_source_id', $source->id)->exists())->toBeFalse();
    });
});

it('binds staged bytes to the exact safe projected catalog snapshot', function (): void {
    $scenario = MediaTenancyScenario::install(catalogCopies: true);
    $source = $scenario->platform(fn (): Media => Media::query()->forceCreate([
        ...app(TenantBoundary::class)->attributes('media.assets'),
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'disk' => 'tenant-disk',
        'filename' => 'projected.txt',
        'hash' => 'projected.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size' => 9,
        'digest' => hash('sha256', 'projected'),
        'storage_path' => 'media/platform/projected.txt',
        'type' => MediaType::DOCUMENT,
        'status' => MediaLifecycleStatus::Available,
        'metadata' => ['title' => 'Visible', 'internal_token' => 'secret', 'nested' => ['unsafe']],
        'tags' => ['safe', str_repeat('x', 101), ['unsafe']],
    ]));
    $scenario->platform(fn () => Storage::disk($source->disk)->put($source->buildPath(), 'projected'));
    $grant = $scenario->platform(fn () => app(GrantMediaToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));

    $scenario->run($scenario::A, function () use ($grant): void {
        $port = app(MediaCatalogImport::class);
        $snapshot = $port->inspect($grant->id);
        expect($snapshot->metadata)->toBe(['title' => 'Visible'])
            ->and($snapshot->tags)->toBe(['safe']);
        $file = $port->stage($snapshot);
        $other = new MediaCatalogSnapshot(
            grantId: $snapshot->grantId,
            grantRevision: $snapshot->grantRevision,
            sourceId: (string) Str::uuid(),
            sourceRevision: $snapshot->sourceRevision,
            sourceDigest: $snapshot->sourceDigest,
            disk: $snapshot->disk,
            storagePath: $snapshot->storagePath,
            filename: $snapshot->filename,
            extension: $snapshot->extension,
            mimeType: $snapshot->mimeType,
            size: $snapshot->size,
            metadata: $snapshot->metadata,
            tags: $snapshot->tags,
            translations: $snapshot->translations,
        );

        expect(fn () => (new Media)->getConnection()->transaction(
            fn (): Media => $port->persist($other, $file, (string) Str::uuid()),
        ))->toThrow(TenantBoundaryViolation::class, 'staged Media tuple');
        $port->discard($file);
    });
});

it('rechecks recipient activity inside final catalog persistence', function (): void {
    $scenario = MediaTenancyScenario::install(catalogCopies: true);
    $source = $scenario->platform(fn (): Media => Media::query()->forceCreate([
        ...app(TenantBoundary::class)->attributes('media.assets'),
        'is_public' => false, 'visibility' => MediaVisibility::Private, 'disk' => 'tenant-disk',
        'filename' => 'suspend.txt', 'hash' => 'suspend.txt', 'extension' => 'txt',
        'mime_type' => 'text/plain', 'size' => 7, 'digest' => hash('sha256', 'suspend'),
        'storage_path' => 'media/platform/suspend.txt', 'type' => MediaType::DOCUMENT,
        'status' => MediaLifecycleStatus::Available,
    ]));
    $scenario->platform(fn () => Storage::disk($source->disk)->put($source->buildPath(), 'suspend'));
    $grant = $scenario->platform(fn () => app(GrantMediaToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));

    $scenario->run($scenario::A, function () use ($grant): void {
        $port = app(MediaCatalogImport::class);
        $snapshot = $port->inspect($grant->id);
        $file = $port->stage($snapshot);
        $directory = app(TenantDirectory::class);
        expect($directory)->toBeInstanceOf(MediaTenancyDirectory::class);
        $directory->status = TenantStatus::Suspended;

        expect(fn () => (new Media)->getConnection()->transaction(
            fn (): Media => $port->persist($snapshot, $file, (string) Str::uuid()),
        ))->toThrow(TenantBoundaryViolation::class, 'no longer active');
        $port->discard($file);
    });
});
