<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaReader;
use Nvl\Media\Services\MediaSchemaAdopter;
use Nvl\Media\Services\MediaUploader;
use Nvl\Media\Slots\MediaPlacement;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Fixtures\StandaloneMediaOwner;
use Nvl\Media\Tests\StandaloneMediaTestCase;
use Nvl\Support\Enums\SystemActor;

uses(StandaloneMediaTestCase::class);

it('uploads reads and deletes private Media with only required package providers', function (): void {
    Storage::fake('local');
    $owner = StandaloneMediaOwner::query()->create(['name' => 'Lean upload owner']);
    $media = app(MediaUploader::class)->upload(
        SystemActor::Background,
        UploadedFile::fake()->createWithContent('proof.txt', 'Required dependencies only.'),
        'local', $owner, new MediaSlot('proof'), 'proof.txt',
        deduplicateExisting: false, skipAutoVariations: true,
    );
    $path = $media->buildPath();

    expect(app()->getLoadedProviders())->not->toHaveKeys(['Nvl\\Filterable\\Providers\\FilterableServiceProvider', 'Nvl\\Ownership\\Providers\\OwnershipServiceProvider']);
    expect(app(MediaReader::class)->get(SystemActor::Background, $media->id)->id)->toBe($media->id)
        ->and(Storage::disk('local')->get($path))->toBe('Required dependencies only.')
        ->and($media->is_public)->toBeFalse();
    expect(app(MediaLibrary::class)->delete(SystemActor::Background, $media))->toBeTrue();
    Storage::disk('local')->assertMissing($path);
});

it('stages a legacy Media graph in place on the disk and resumes without changing its IDs', function (): void {
    Storage::fake('public');
    config(['nvl-media.root_folder' => 'legacy', 'nvl-media.delivery.disks.public.public.mode' => 'direct']);

    $columns = [
        'px_media' => ['filename', 'hash', 'extension', 'mime_type', 'size', 'disk', 'folder', 'is_public', 'type', 'digest', 'tags', 'metadata', 'uploaded_by', 'deleted_at'],
        'px_media_associations' => ['media_id', 'associable_type', 'associable_id', 'collection', 'locale', 'order', 'metadata'],
        'px_media_image_variations' => ['media_id', 'label', 'width', 'height', 'size', 'format', 'quality'],
        'px_media_translations' => ['media_id', 'locale', 'title', 'alt', 'caption'],
    ];
    foreach ($columns as $name => $fields) {
        Schema::create($name, static function (Blueprint $table) use ($fields): void {
            $table->uuid('id')->primary();
            foreach ($fields as $field) {
                $table->string($field)->nullable();
            }
            $table->timestamps();
        });
    }

    $time = '2026-05-12 11:12:13';
    $timestamps = ['created_at' => $time, 'updated_at' => $time];
    $owner = StandaloneMediaOwner::query()->create(['name' => 'Legacy owner']);
    [$asset, $pivot, $variation, $translation] = array_map(static fn (): string => (string) Str::uuid(), range(1, 4));
    $media = [
        'id' => $asset, 'filename' => 'original.jpg', 'hash' => '91.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 4,
        'disk' => 'public', 'folder' => 'photos', 'is_public' => '1', 'type' => 'image', 'digest' => hash('sha256', 'file'), 'tags' => '["old"]',
        'metadata' => '{"kind":"cover"}', 'uploaded_by' => null, ...$timestamps,
    ];
    DB::table('px_media')->insert($media);
    DB::table('px_media_associations')->insert(['id' => $pivot, 'media_id' => $asset, 'associable_type' => 'old-owner', 'associable_id' => (string) $owner->getKey(), 'collection' => 'gallery', 'locale' => 'bg', 'order' => 4, 'metadata' => '{"slot":"cover"}', ...$timestamps]);
    DB::table('px_media_image_variations')->insert(['id' => $variation, 'media_id' => $asset, 'label' => 'small', 'width' => 100, 'height' => 100, 'size' => 5, 'format' => 'webp', 'quality' => 80, ...$timestamps]);
    DB::table('px_media_translations')->insert(['id' => $translation, 'media_id' => $asset, 'locale' => 'bg', 'title' => 'Заглавие', 'alt' => 'Снимка', 'caption' => '', ...$timestamps]);
    Storage::disk('public')->put('legacy/photos/91.jpg', 'file');

    $adopter = app(MediaSchemaAdopter::class);
    $actor = SystemActor::Background;
    $options = ['root_folder' => 'legacy', 'owner_types' => ['old-owner' => StandaloneMediaOwner::class]];
    $blocked = $adopter->plan($actor, $options);
    expect($blocked['conflicts'])->toBe(['px_media_image_variations ['.$variation.'] has no backing file [public:legacy/photos/conversions/91-small.webp].']);
    Storage::disk('public')->put('legacy/photos/conversions/91-small.webp', 'thumb');

    $plan = $adopter->plan($actor, $options);
    expect($plan['conflicts'])->toBeEmpty()
        ->and($plan['staged'])->toBe(['assets' => 0, 'associations' => 0, 'variations' => 0, 'translations' => 0])
        ->and(fn () => $adopter->verify($actor, $plan['fingerprint'], $options))->toThrow(LogicException::class, 'incomplete');

    $interrupted = false;
    DB::connection()->beforeExecuting(static function (string $sql) use (&$interrupted): void {
        if (! $interrupted && str_starts_with($sql, 'insert into "'.MediaTables::get(MediaTables::Associations).'"')) {
            $interrupted = true;
            throw new RuntimeException('simulated interruption');
        }
    });
    expect(fn () => $adopter->apply($actor, $plan['fingerprint'], $options))->toThrow(RuntimeException::class, 'simulated interruption');
    expect($adopter->plan($actor, $options)['staged']['assets'])->toBe(1)
        ->and($adopter->apply($actor, $plan['fingerprint'], $options)['staged'])->toBe(['assets' => 1, 'associations' => 1, 'variations' => 1, 'translations' => 1])
        ->and($adopter->verify($actor, $plan['fingerprint'], $options)['conflicts'])->toBeEmpty();

    $saved = Media::query()->findOrFail($asset);
    $savedVariation = $saved->imageVariations()->findOrFail($variation);
    expect($saved->created_at?->format('Y-m-d H:i:s'))->toBe($time)
        ->and($saved->getUrl())->toBe(Storage::disk('public')->url('legacy/photos/91.jpg'))
        ->and(explode('?', $savedVariation->getUrl(), 2)[0])->toBe(Storage::disk('public')->url('legacy/photos/conversions/91-small.webp'))
        ->and($saved->associations()->findOrFail($pivot)->metadata)->toBe(['slot' => 'cover'])
        ->and($saved->translations()->findOrFail($translation)->title)->toBe('Заглавие')
        ->and(Storage::disk('public')->get('legacy/photos/91.jpg'))->toBe('file');

    DB::table('px_media_translations')->where('id', $translation)->update(['title' => 'Changed']);
    expect(fn () => $adopter->verify($actor, $plan['fingerprint'], $options))->toThrow(LogicException::class, 'fingerprint');
    Schema::drop('px_media_translations');
    expect(fn () => $adopter->plan($actor, $options))->toThrow(LogicException::class, 'incompatible schema');
});

it('places media by role inside a separate collection and limits each role independently', function (): void {
    Storage::fake('local');
    $actor = SystemActor::Background;
    $owner = StandaloneMediaOwner::query()->create(['name' => 'Placement owner']);
    $upload = function (string $name) use ($actor, $owner) {
        return app(MediaUploader::class)->upload(
            $actor, UploadedFile::fake()->createWithContent($name, $name),
            'local', $owner, new MediaSlot('upload'), $name,
            deduplicateExisting: false, skipAutoVariations: true,
        );
    };

    $attachments = app(MediaAttachments::class);
    $cover = new MediaSlot('cover');
    $cover->slotSizeLimit = 1;
    $first = $attachments->attach($actor, $upload('cover.txt'), $owner, new MediaPlacement('gallery', $cover));
    $sidebar = $attachments->attach($actor, $upload('sidebar.txt'), $owner, new MediaPlacement('gallery', new MediaSlot('sidebar')));
    $replacement = $attachments->attach($actor, $upload('replacement.txt'), $owner, new MediaPlacement(
        'gallery', $cover, 'bg', 7, ['caption' => 'Cover'],
    ));

    expect($first->fresh())->toBeNull()
        ->and($sidebar->fresh()?->metadata)->toBe(['slot' => 'sidebar'])
        ->and($replacement->collection)->toBe('gallery')
        ->and($replacement->locale)->toBe('bg')
        ->and($replacement->order)->toBe(7)
        ->and($replacement->metadata)->toBe(['caption' => 'Cover', 'slot' => 'cover']);
});
