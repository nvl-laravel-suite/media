<?php

declare(strict_types=1);

use Nvl\Media\Actions\ReusePublicMediaAction;
use Nvl\Media\Data\MediaFilter;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaQueryService;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

it('denies public reuse across tenant owners even for a passed model', function (): void {
    $scenario = MediaTenancyScenario::install();
    $asset = $scenario->upload($scenario::A, 'public bytes');
    $ownerB = $scenario->owner($scenario::B);

    expect(fn () => $scenario->run($scenario::B, fn () => app(ReusePublicMediaAction::class)
        ->execute($asset, $ownerB)))->toThrow(TenantBoundaryViolation::class);
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
