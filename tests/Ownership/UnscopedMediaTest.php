<?php

declare(strict_types=1);
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Ownership\MediaOwnershipParentResolver;
use Nvl\Media\Services\MediaUploader;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Media\Tests\UnscopedOwnershipMediaTestCase;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(UnscopedOwnershipMediaTestCase::class);
it('keeps a consumer that declines ownership usable without owner context or protocol tables', function (): void {
    Storage::fake('public');
    $media = app(MediaUploader::class)->upload(SystemActor::Background, UploadedFile::fake()->createWithContent('plain.txt', 'plain'), 'public', TestMediaModel::query()->create(['name' => 'Plain consumer']), new MediaSlot('documents'), 'plain.txt', skipAutoVariations: true);
    expect($media->filename)->toBe('plain.txt')->and($media->getAttributes())->not->toHaveKey('owner_scope_key');
});

it('reloads unowned hosts instead of trusting carried attributes', function (): void {
    $owner = TestMediaModel::query()->create(['name' => 'Persisted name']);
    $owner->name = 'Unpersisted name';

    expect(app(MediaOwnershipParentResolver::class)->resolve($owner)->name)->toBe('Persisted name');

    $owner->delete();
    expect(fn () => app(MediaOwnershipParentResolver::class)->resolve($owner))->toThrow(OwnershipViolation::class);
});

it('rejects altered unowned host identities before querying them', function (string $mutation): void {
    $owner = TestMediaModel::query()->create(['name' => 'Canonical owner']);
    match ($mutation) {
        'table' => $owner->setTable('untrusted_table'),
        'connection' => $owner->setConnection('untrusted_connection'),
        'key' => $owner->setAttribute($owner->getKeyName(), 'different-key'),
    };

    expect(fn () => app(MediaOwnershipParentResolver::class)->resolve($owner))->toThrow(OwnershipViolation::class);
})->with(['table', 'connection', 'key']);
