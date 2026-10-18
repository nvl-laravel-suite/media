<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\ConfiguredMediaTestCase;
use Nvl\Media\Tests\Fixtures\ConfiguredConsumerMedia;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Translatable\Services\TranslationResourceRegistry;

uses(ConfiguredMediaTestCase::class);

it('uses the exact configured subclass in uploads relations and translation declarations', function (): void {
    Storage::fake('public');
    $owner = TestMediaModel::query()->create(['name' => 'Consumer']);
    $media = app(UploadMediaAction::class)->execute(UploadedFile::fake()->createWithContent('private.txt', 'private content'), 'public', $owner, new MediaSlot('documents'), 'private.txt', skipAutoVariations: true, uploadedBy: '1');
    expect($media::class)->toBe(ConfiguredConsumerMedia::class)
        ->and(app(MediaModelRegistry::class)->mediaClass())->toBe(ConfiguredConsumerMedia::class)
        ->and(Media::factory()->make()::class)->toBe(ConfiguredConsumerMedia::class)
        ->and((new Media)->resolveRouteBinding($media->id)::class)->toBe(ConfiguredConsumerMedia::class)
        ->and((new MediaAssociation)->media()->getRelated()::class)->toBe(ConfiguredConsumerMedia::class)
        ->and((new MediaImageVariation)->media()->getRelated()::class)->toBe(ConfiguredConsumerMedia::class)
        ->and((new MediaTranslation)->media()->getRelated()::class)->toBe(ConfiguredConsumerMedia::class)
        ->and(app(TranslationResourceRegistry::class)->get('media.assets')->modelClass)->toBe(ConfiguredConsumerMedia::class);
});
