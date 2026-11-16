<?php

declare(strict_types=1);

use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\PortableMediaSearchDriver;
use Nvl\Media\Tests\Fixtures\CloningTextMatcher;
use Nvl\Support\Contracts\TextMatcher;

it('combines cloned matcher predicates with tag search inside the caller scope', function (): void {
    app()->bind(TextMatcher::class, CloningTextMatcher::class);
    $filenameMatch = Media::factory()->create(['filename' => 'image-launch.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'disk' => 'public', 'type' => MediaType::DOCUMENT, 'tags' => ['launch']]);
    $typeMatch = Media::factory()->create(['filename' => 'portrait.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'disk' => 'public', 'type' => MediaType::IMAGE, 'tags' => ['portrait']]);
    $tagMatch = Media::factory()->create(['filename' => 'campaign.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'disk' => 'public', 'type' => MediaType::DOCUMENT, 'tags' => ['image']]);
    Media::factory()->create(['filename' => 'report.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'disk' => 'public', 'type' => MediaType::DOCUMENT, 'tags' => ['report']]);
    Media::factory()->create(['filename' => 'image-outside.jpg', 'disk' => 's3', 'type' => MediaType::IMAGE, 'tags' => ['image']]);

    $query = Media::query()->where('disk', 'public');
    app(PortableMediaSearchDriver::class)->apply($query, 'image');

    expect($query->pluck('id')->sort()->values()->all())
        ->toBe(collect([$filenameMatch->id, $typeMatch->id, $tagMatch->id])->sort()->values()->all());
});
