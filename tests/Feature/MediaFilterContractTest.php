<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaReader;
use Nvl\Media\Tests\Fixtures\MediaFilters;
use Nvl\Support\Enums\SystemActor;

it('applies filename type and tag search without broadening the caller query', function (): void {
    $filenameMatch = Media::factory()->create(['filename' => 'image-launch.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'disk' => 'public', 'type' => MediaType::DOCUMENT, 'tags' => ['launch']]);
    $typeMatch = Media::factory()->create(['filename' => 'portrait.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'disk' => 'public', 'type' => MediaType::IMAGE, 'tags' => ['portrait']]);
    $tagMatch = Media::factory()->create(['filename' => 'campaign.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'disk' => 'public', 'type' => MediaType::DOCUMENT, 'tags' => ['image']]);
    $outsideCallerRestriction = Media::factory()->create(['filename' => 'image-outside.jpg', 'disk' => 's3', 'type' => MediaType::IMAGE, 'tags' => ['image']]);
    $decoy = Media::factory()->create(['filename' => 'report.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'disk' => 'public', 'type' => MediaType::DOCUMENT, 'tags' => ['report']]);
    $ids = app(MediaReader::class)->paginate(SystemActor::Background, ['disk' => 'public', 'search' => 'image'])->pluck('id')->all();
    expect($ids)->toHaveCount(3)->toContain($filenameMatch->id, $typeMatch->id, $tagMatch->id);
    expect($ids)->not->toContain($outsideCallerRestriction->id, $decoy->id);
});

it('rejects malformed native filters before querying Media', function (array $filters): void {
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(MediaReader::class)->paginate(SystemActor::Background, $filters))
        ->toThrow(ValidationException::class);
    expect(DB::getQueryLog())->toBe([]);
})->with([
    'oversized search' => [['search' => str_repeat('x', 256)]],
    'invalid media type' => [['type' => 'unknown']],
    'invalid page' => [['page' => 0]],
    'unapproved sort' => [['sortBy' => 'uploaded_by']],
]);

it('keeps the optional consumer filter fixture compatible with native criterion arrays', function (): void {
    $filters = new class
    {
        use MediaFilters;
    };
    $search = $filters->filterSchema()->filters[0]->handler;
    $match = Media::factory()->create(['filename' => 'launch.txt', 'disk' => 'public']);
    Media::factory()->create(['filename' => 'launch-outside.txt', 'disk' => 'private']);
    $query = Media::query()->where('disk', 'public');

    expect($search($query, ['alias' => 'search', 'operator' => FilterOperator::Equals, 'value' => 'launch'])->pluck('id')->all())
        ->toBe([$match->id]);
});

it('treats LIKE wildcards and escape characters in a search as literal text on every engine', function (string $search, array $expected): void {
    foreach (['100%_done.pdf', '100x-done.pdf', 'plain.pdf', 'back\\slash.pdf', 'bang!mark.pdf'] as $filename) {
        Media::factory()->create(['filename' => $filename, 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'disk' => 'public', 'type' => MediaType::DOCUMENT, 'tags' => []]);
    }

    $found = app(MediaReader::class)->paginate(SystemActor::Background, ['search' => $search])->pluck('filename')->sort()->values()->all();

    expect($found)->toBe($expected);
})->with([
    'percent' => ['100%', ['100%_done.pdf']],
    'underscore' => ['_', ['100%_done.pdf']],
    'backslash' => ['\\', ['back\\slash.pdf']],
    'escape character' => ['!', ['bang!mark.pdf']],
    'plain text' => ['done', ['100%_done.pdf', '100x-done.pdf']],
]);

it('matches a filename search without regard to letter case on every engine', function (string $search): void {
    $media = Media::factory()->create(['filename' => 'Annual-Report.PDF', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'disk' => 'public', 'type' => MediaType::DOCUMENT, 'tags' => []]);

    expect(app(MediaReader::class)->paginate(SystemActor::Background, ['search' => $search])->pluck('id')->all())->toBe([$media->id]);
})->with(['annual-report', 'ANNUAL-REPORT', 'Report.pdf', 'DOCUMENT']);
