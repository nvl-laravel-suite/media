<?php

declare(strict_types=1);

use Nvl\Media\Slots\MediaSlot;

it('configures a reusable public asset slot', function () {
    $slot = (new MediaSlot('library'))->publicReusable();

    expect($slot->isPublic)->toBeTrue()
        ->and($slot->isShared())->toBeTrue()
        ->and($slot->isReusable())->toBeTrue()
        ->and($slot->isPrivate())->toBeFalse();
});

it('configures a private exclusive one-to-one slot', function () {
    $slot = (new MediaSlot('identity-document'))->oneToOne();

    expect($slot->isPublic)->toBeFalse()
        ->and($slot->isExclusive())->toBeTrue()
        ->and($slot->isSingleFile)->toBeTrue()
        ->and($slot->slotSizeLimit)->toBe(1)
        ->and($slot->isPrivate())->toBeTrue()
        ->and($slot->isReusable())->toBeFalse();
});
