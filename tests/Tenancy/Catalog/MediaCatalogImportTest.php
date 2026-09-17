<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Actions\GrantMediaToTenantAction;
use Nvl\Media\Actions\ImportPlatformMediaAction;
use Nvl\Media\Actions\RevokeMediaTenantGrantAction;
use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Media\Data\Mutations\ImportPlatformMediaData;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\ValueObjects\TenantId;

it('keeps a completed catalog copy after revocation but rejects a new import', function (): void {
    $scenario = MediaTenancyScenario::install(catalogCopies: true);
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
    $request = new ImportPlatformMediaData($grant->id, $grant->revision, $source->revision, (string) Str::uuid());
    $copy = $scenario->run($scenario::A, fn () => app(ImportPlatformMediaAction::class)->execute($request));
    $scenario->platform(fn () => app(RevokeMediaTenantGrantAction::class)->execute($grant->id, $grant->revision));

    expect($scenario->run($scenario::A, fn () => Media::query()->findOrFail($copy->id)->digest))->toBe($source->digest);
    expect(fn () => $scenario->run($scenario::A, fn () => app(ImportPlatformMediaAction::class)->execute(
        new ImportPlatformMediaData($grant->id, $grant->revision, $source->revision, (string) Str::uuid()),
    )))->toThrow(TenantBoundaryViolation::class);
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
