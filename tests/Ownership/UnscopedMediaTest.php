<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Media\Tests\UnscopedOwnershipMediaTestCase;

uses(UnscopedOwnershipMediaTestCase::class);

it('keeps a consumer that declines ownership usable without owner context or protocol tables', function (): void {
    Storage::fake('public');
    $media = app(UploadMediaAction::class)->execute(UploadedFile::fake()->createWithContent('plain.txt', 'plain'), 'public', TestMediaModel::query()->create(['name' => 'Plain consumer']), new MediaSlot('documents'), 'plain.txt', skipAutoVariations: true);
    expect($media->filename)->toBe('plain.txt')->and($media->getAttributes())->not->toHaveKey('owner_scope_key');
});
