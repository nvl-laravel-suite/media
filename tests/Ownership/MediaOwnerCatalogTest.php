<?php

declare(strict_types=1);

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
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => expect(fn () => app(MediaOwnerCatalog::class)->grant($source->id, 1))->toThrow(OwnershipViolation::class));
    app()->bind(MediaCatalogAccess::class, fn () => new class implements MediaCatalogAccess
    {
        public function assertCanGrant(string $sourceId, int $revision): void {}

        public function assertCanRevoke(string $grantId): void {}
    });
    app()->forgetScopedInstances();
    $foreignGrant = null;
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($source, &$foreignGrant): void {
        $catalog = app(MediaOwnerCatalog::class);
        $grant = $catalog->grant($source->id, 1);
        $foreignGrant = $grant->id;
        $snapshot = $catalog->inspect($grant->id);
        $file = $catalog->stage($snapshot);
        $key = (string) Str::uuid();
        $copy = MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($catalog, $snapshot, $file, $key) {
            $copy = $catalog->persist($snapshot, $file, $key);
            $catalog->discard($file);
            Storage::disk('public')->assertExists($copy->storage_path);

            return $copy;
        });
        expect($copy::class)->toBe(CatalogOwnershipMedia::class)->and($copy->id)->not->toBe($source->id)->and($copy->storage_path)->not->toBe($source->storage_path);
        $staged = $catalog->stage($snapshot);
        $catalog->revoke($grant->id, $grant->revision);
        expect(fn () => MediaModelRegistry::newInstance()->getConnection()->transaction(fn () => $catalog->persist($snapshot, $staged, (string) Str::uuid())))->toThrow(OwnershipViolation::class);
        $catalog->discard($staged);
        Storage::disk('public')->assertExists($copy->storage_path);
        expect(MediaModelRegistry::query()->count())->toBe(1);
    });
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, fn () => expect(fn () => app(MediaOwnerCatalog::class)->inspect($foreignGrant))->toThrow(OwnershipViolation::class));
});
