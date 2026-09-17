<?php

declare(strict_types=1);

use Nvl\Media\Actions\ReusePublicMediaAction;
use Nvl\Media\Data\MediaFilter;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaQueryService;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Media\Tests\Stubs\TestPrivilegedMediaUser;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

it('denies public reuse across tenant owners even for a passed model', function (): void {
    $scenario = MediaTenancyScenario::install();
    $asset = $scenario->upload($scenario::A, 'public bytes');
    $ownerB = $scenario->owner($scenario::B);

    expect(fn () => $scenario->run($scenario::B, fn () => app(ReusePublicMediaAction::class)
        ->execute($asset, $ownerB)))->toThrow(TenantBoundaryViolation::class);
});

it('keeps a globally privileged actor inside the active tenant library', function (): void {
    $scenario = MediaTenancyScenario::install();
    $assetA = $scenario->upload($scenario::A, 'privileged tenant A');
    $scenario->upload($scenario::B, 'privileged tenant B');
    config()->set('media.authorization.spatie_permission.global_roles', ['media-admin']);

    $ids = $scenario->run($scenario::A, fn () => array_map(
        static fn (Media $media): string => $media->id,
        app(MediaQueryService::class)->index(new MediaFilter, new TestPrivilegedMediaUser)->items(),
    ));

    expect($ids)->toBe([$assetA->id]);
});

it('does not allow an unscoped actor to list another tenant library', function (): void {
    $scenario = MediaTenancyScenario::install();
    $assetA = $scenario->upload($scenario::A, 'tenant A text');
    $scenario->upload($scenario::B, 'tenant B text');

    $ids = $scenario->run($scenario::A, fn () => array_map(
        static fn (Media $media): string => $media->id,
        app(MediaQueryService::class)->index(new MediaFilter)->items(),
    ));

    expect($ids)->toBe([$assetA->id]);
});
