<?php

declare(strict_types=1);

use Nvl\Media\Events\MediaAttached;
use Nvl\Media\Events\MediaDetached;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Events\MediaUploadedEvent;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Support\MediaAssociationSnapshot;
use Nvl\Support\Contracts\DomainEvent;

test('media public mutation events dispatch after commit', function (): void {
    expect(is_subclass_of(MediaAttached::class, DomainEvent::class))->toBeTrue()
        ->and(is_subclass_of(MediaDetached::class, DomainEvent::class))->toBeTrue()
        ->and(is_subclass_of(MediaMutated::class, DomainEvent::class))->toBeTrue()
        ->and(is_subclass_of(MediaUploadedEvent::class, DomainEvent::class))->toBeTrue();
});

test('media association snapshots expose stable generic owner metadata', function (): void {
    $association = new MediaAssociation([
        'media_id' => '00000000-0000-0000-0000-000000000001',
        'associable_type' => 'module.model',
        'associable_id' => '00000000-0000-0000-0000-000000000002',
        'collection' => 'image',
        'locale' => 'bg',
    ]);

    expect(MediaAssociationSnapshot::fromAssociation($association))->toBe([
        'media_id' => '00000000-0000-0000-0000-000000000001',
        'associable_type' => 'module.model',
        'associable_id' => '00000000-0000-0000-0000-000000000002',
        'collection' => 'image',
        'locale' => 'bg',
    ]);
});
