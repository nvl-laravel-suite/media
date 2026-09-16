<?php

declare(strict_types=1);

use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;

it('applies filename type and tag search without broadening the caller query', function (): void {
    $filenameMatch = Media::factory()->create([
        'filename' => 'image-launch.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'type' => MediaType::DOCUMENT,
        'tags' => ['launch'],
    ]);
    $typeMatch = Media::factory()->create([
        'filename' => 'portrait.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'type' => MediaType::IMAGE,
        'tags' => ['portrait'],
    ]);
    $tagMatch = Media::factory()->create([
        'filename' => 'campaign.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'type' => MediaType::DOCUMENT,
        'tags' => ['image'],
    ]);
    $outsideCallerRestriction = Media::factory()->create([
        'filename' => 'image-outside.jpg',
        'disk' => 's3',
        'type' => MediaType::IMAGE,
        'tags' => ['image'],
    ]);
    $decoy = Media::factory()->create([
        'filename' => 'report.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'type' => MediaType::DOCUMENT,
        'tags' => ['report'],
    ]);

    $ids = Media::query()
        ->where('disk', 'public')
        ->applyFilterSet(new FilterSet([
            new FilterCriterion('search', FilterOperator::Equals, 'image'),
        ]))
        ->pluck('id')
        ->all();

    expect($ids)->toHaveCount(3)
        ->toContain($filenameMatch->id, $typeMatch->id, $tagMatch->id);
    expect($ids)->not->toContain($outsideCallerRestriction->id, $decoy->id);
});
