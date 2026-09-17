<?php

declare(strict_types=1);

use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;

it('deduplicates within A but never reuses A asset or object in B', function (): void {
    $scenario = MediaTenancyScenario::install();
    $assetA1 = $scenario->upload($scenario::A, 'identical tenant bytes');
    $assetA2 = $scenario->upload($scenario::A, 'identical tenant bytes');
    $assetB = $scenario->upload($scenario::B, 'identical tenant bytes');

    $pathA = $scenario->run($scenario::A, fn (): string => $assetA1->buildPath());
    $pathB = $scenario->run($scenario::B, fn (): string => $assetB->buildPath());

    expect($assetA2->id)->toBe($assetA1->id)
        ->and($assetB->id)->not->toBe($assetA1->id)
        ->and($pathB)->not->toBe($pathA)
        ->and($pathA)->toContain('/tenants/'.$scenario::A.'/')
        ->and($pathB)->toContain('/tenants/'.$scenario::B.'/');
});
