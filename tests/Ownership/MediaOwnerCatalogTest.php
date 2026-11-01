<?php

declare(strict_types=1);
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Contracts\MediaCatalogAccess;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Ownership\MediaOwnerCatalog;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Tests\CatalogMediaTestCase;
use Nvl\Media\Tests\Fixtures\CatalogOwnershipMedia;
use Nvl\Media\Tests\Fixtures\OwnershipActor;
use Nvl\Media\Tests\Fixtures\OwnershipCompany;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(CatalogMediaTestCase::class);
it('defaults grant issuance to deny and keeps copied bytes independent after revocation', function (): void {
    Storage::fake('public');
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $source = $runner->central('media-maintain', $actor, function () {
        $media = MediaModelRegistry::create(['filename' => 'catalog.txt', 'hash' => 'catalog.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'size' => 7, 'disk' => 'public', 'folder' => 'catalog', 'storage_path' => 'media/central/catalog/catalog.txt', 'type' => MediaType::DOCUMENT, 'digest' => hash('sha256', 'catalog')]);
        Storage::disk('public')->put($media->storage_path, 'catalog');

        return $media;
    });
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => expect(fn () => app(MediaOwnerCatalog::class)->grant(SystemActor::Background, $source->id, 1))->toThrow(OwnershipViolation::class));
    app()->bind(MediaCatalogAccess::class, fn () => new class implements MediaCatalogAccess
    {
        public function assertCanGrant(Authenticatable|SystemActor $actor, string $sourceId, int $revision): void {}

        public function assertCanRevoke(Authenticatable|SystemActor $actor, string $grantId): void {}
    });
    app()->forgetScopedInstances();
    $foreignGrant = null;
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($source, &$foreignGrant): void {
        $catalog = app(MediaOwnerCatalog::class);
        $grant = $catalog->grant(SystemActor::Background, $source->id, 1);
        $foreignGrant = $grant->id;
        $snapshot = $catalog->inspect(SystemActor::Background, $grant->id);
        $file = $catalog->stage(SystemActor::Background, $snapshot);
        $key = (string) Str::uuid();
        $copy = MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($catalog, $snapshot, $file, $key) {
            $copy = $catalog->persist(SystemActor::Background, $snapshot, $file, $key);
            $catalog->discard(SystemActor::Background, $file);
            Storage::disk('public')->assertExists($copy->storage_path);

            return $copy;
        });
        expect($copy::class)->toBe(CatalogOwnershipMedia::class)->and($copy->id)->not->toBe($source->id)->and($copy->storage_path)->not->toBe($source->storage_path);
        $before = Storage::disk('public')->allFiles();
        $replayed = $catalog->import(SystemActor::Background, $grant->id, $grant->revision, $source->revision, $key);
        expect($replayed->id)->toBe($copy->id)->and(Storage::disk('public')->allFiles())->toBe($before);
        expect(fn () => MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($catalog, $grant, $source): void {
            $catalog->import(SystemActor::Background, $grant->id, $grant->revision, $source->revision, (string) Str::uuid());
            throw new RuntimeException('rollback catalog import');
        }))->toThrow(RuntimeException::class, 'rollback catalog import');
        expect(MediaModelRegistry::query()->count())->toBe(1)->and(Storage::disk('public')->allFiles())->toBe($before);
        $staged = $catalog->stage(SystemActor::Background, $snapshot);
        $catalog->revoke(SystemActor::Background, $grant->id, $grant->revision);
        expect(fn () => MediaModelRegistry::newInstance()->getConnection()->transaction(fn () => $catalog->persist(SystemActor::Background, $snapshot, $staged, (string) Str::uuid())))->toThrow(OwnershipViolation::class);
        $catalog->discard(SystemActor::Background, $staged);
        Storage::disk('public')->assertExists($copy->storage_path);
        expect(MediaModelRegistry::query()->count())->toBe(1);
    });
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, fn () => expect(fn () => app(MediaOwnerCatalog::class)->inspect(SystemActor::Background, $foreignGrant))->toThrow(OwnershipViolation::class));
});

it('requires private destination admission when staging and persisting catalog copies', function (bool $revokeAfterStage): void {
    Storage::fake('public');
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $source = $runner->central('media-maintain', $actor, function () {
        $media = MediaModelRegistry::create(['filename' => 'catalog.txt', 'hash' => 'catalog.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'size' => 7, 'disk' => 'public', 'folder' => 'catalog', 'storage_path' => 'media/central/catalog/catalog.txt', 'is_public' => true, 'type' => MediaType::DOCUMENT, 'digest' => hash('sha256', 'catalog')]);
        Storage::disk('public')->put($media->storage_path, 'catalog');

        return $media;
    });
    app()->bind(MediaCatalogAccess::class, fn () => new class implements MediaCatalogAccess
    {
        public function assertCanGrant(Authenticatable|SystemActor $actor, string $sourceId, int $revision): void {}

        public function assertCanRevoke(Authenticatable|SystemActor $actor, string $grantId): void {}
    });
    app()->forgetScopedInstances();
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($source, $revokeAfterStage): void {
        $catalog = app(MediaOwnerCatalog::class);
        $grant = $catalog->grant(SystemActor::Background, $source->id, 1);
        $snapshot = $catalog->inspect(SystemActor::Background, $grant->id);
        $file = $revokeAfterStage ? $catalog->stage(SystemActor::Background, $snapshot) : null;
        config(['nvl-media.delivery.disks.public.private' => false]);

        if ($file !== null) {
            expect(fn () => MediaModelRegistry::newInstance()->getConnection()->transaction(fn () => $catalog->persist(SystemActor::Background, $snapshot, $file, (string) Str::uuid())))
                ->toThrow(RuntimeException::class, 'does not admit [private] media');
            $catalog->discard(SystemActor::Background, $file);
            Storage::disk('public')->assertMissing($file['storagePath']);
        } else {
            expect(fn () => $catalog->stage(SystemActor::Background, $snapshot))->toThrow(RuntimeException::class, 'does not admit [private] media');
        }

        expect(MediaModelRegistry::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([$source->storage_path]);
    });
})->with(['before copy' => false, 'before persist' => true]);
