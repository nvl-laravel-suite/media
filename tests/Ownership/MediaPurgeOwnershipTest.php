<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaVariations;
use Nvl\Media\Tests\Fixtures\OwnershipActor;
use Nvl\Media\Tests\Fixtures\OwnershipCompany;
use Nvl\Media\Tests\Fixtures\OwnershipMediaScenario;
use Nvl\Media\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;

uses(OwnershipTestCase::class);

it('retains native owner admission when purging a tombstone and inherited variation objects', function (): void {
    Storage::fake('public');
    config(['nvl-media.delete_files_on_media_delete' => false]);
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $fixture = $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($actor): array {
        $owner = OwnershipMediaScenario::owner();
        $media = OwnershipMediaScenario::upload($owner, true);
        $variation = app(MediaVariations::class)->generate($actor, $media, ConversionDefinition::fromPreset('retained-preview', ['width' => 16, 'height' => 16, 'format' => 'png']));
        app(MediaLibrary::class)->delete($actor, $media, owner: $owner);

        return ['owner' => $owner, 'media' => $media, 'original' => $media->buildPath(), 'variation' => $variation->getPath()];
    });

    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, function () use ($actor, $fixture): void {
        expect(fn () => app(MediaLibrary::class)->purgeDeletedFiles($actor, $fixture['media']->id))->toThrow(ModelNotFoundException::class);
        Storage::disk('public')->assertExists([$fixture['original'], $fixture['variation']]);
    });
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($actor, $fixture): void {
        expect(app(MediaLibrary::class)->purgeDeletedFiles($actor, $fixture['media']->id, $fixture['owner']))->toBeTrue();
        Storage::disk('public')->assertMissing([$fixture['original'], $fixture['variation']]);
    });
});
