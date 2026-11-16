<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
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
use Nvl\Support\Adoption\AdoptionGate;
use Nvl\Support\Adoption\AdoptionStore;
use Nvl\Support\Enums\SystemActor;

uses(StandaloneMediaTestCase::class);

const LEGACY_MEDIA_OPTIONS = ['root_folder' => 'legacy', 'owner_types' => ['old-owner' => StandaloneMediaOwner::class]];

/**
 * Create committed legacy px_media tables with one asset graph plus extra bare assets, and their backing files.
 *
 * @return array{time: string, assets: list<string>, pivot: string, variation: string, translation: string}
 */
function seedLegacyMediaGraph(int $assets = 1, bool $variationFile = true): array
{
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
    $ids = array_map(static fn (): string => (string) Str::uuid(), range(1, $assets));
    foreach ($ids as $index => $id) {
        DB::table('px_media')->insert([
            'id' => $id, 'filename' => 'original.jpg', 'hash' => ($index + 91).'.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 4,
            'disk' => 'public', 'folder' => 'photos', 'is_public' => '1', 'type' => 'image', 'digest' => hash('sha256', 'file'), 'tags' => '["old"]',
            'metadata' => '{"kind":"cover"}', 'uploaded_by' => null, ...$timestamps,
        ]);
        Storage::disk('public')->put('legacy/photos/'.($index + 91).'.jpg', 'file');
    }
    [$pivot, $variation, $translation] = array_map(static fn (): string => (string) Str::uuid(), range(1, 3));
    DB::table('px_media_associations')->insert(['id' => $pivot, 'media_id' => $ids[0], 'associable_type' => 'old-owner', 'associable_id' => (string) $owner->getKey(), 'collection' => 'gallery', 'locale' => 'bg', 'order' => 4, 'metadata' => '{"slot":"cover"}', ...$timestamps]);
    DB::table('px_media_image_variations')->insert(['id' => $variation, 'media_id' => $ids[0], 'label' => 'small', 'width' => 100, 'height' => 100, 'size' => 5, 'format' => 'webp', 'quality' => 80, ...$timestamps]);
    DB::table('px_media_translations')->insert(['id' => $translation, 'media_id' => $ids[0], 'locale' => 'bg', 'title' => 'Заглавие', 'alt' => 'Снимка', 'caption' => '', ...$timestamps]);
    if ($variationFile) {
        Storage::disk('public')->put('legacy/photos/conversions/91-small.webp', 'thumb');
    }

    return ['time' => $time, 'assets' => $ids, 'pivot' => $pivot, 'variation' => $variation, 'translation' => $translation];
}

/**
 * Install the opt-in core adoption tables and write the legacy options as an nvl:adopt manifest.
 */
function prepareMediaAdoption(): string
{
    $migrations = dirname((string) (new ReflectionClass(AdoptionStore::class))->getFileName(), 3).'/database/migrations';
    test()->artisan('migrate', ['--path' => $migrations, '--realpath' => true])->assertSuccessful();
    $manifest = (string) tempnam(sys_get_temp_dir(), 'nvl-media-adoption-');
    file_put_contents($manifest, json_encode(LEGACY_MEDIA_OPTIONS, JSON_THROW_ON_ERROR));
    test()->beforeApplicationDestroyed(static fn () => @unlink($manifest));

    return $manifest;
}

/**
 * Run one nvl:adopt media phase and decode its JSON report.
 *
 * @param  array<string, string|int>  $options
 * @return array<string, mixed>
 */
function adoptMedia(string $phase, string $manifest, array $options = []): array
{
    $status = Artisan::call('nvl:adopt', ['package' => 'media', 'phase' => $phase, 'manifest' => $manifest, '--format' => 'json', ...$options]);

    return ['status' => $status, ...json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

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
    ['time' => $time, 'assets' => [$asset], 'pivot' => $pivot, 'variation' => $variation, 'translation' => $translation] = seedLegacyMediaGraph(variationFile: false);

    $adopter = app(MediaSchemaAdopter::class);
    $actor = SystemActor::Background;
    $options = LEGACY_MEDIA_OPTIONS;
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

it('reports an open transaction before unsafe legacy folders, as before the adapter refactor', function (): void {
    $connection = Media::query()->getConnection();
    $connection->beginTransaction();
    try {
        expect(fn () => app(MediaSchemaAdopter::class)->apply(SystemActor::Background, str_repeat('a', 64), ['root_folder' => '../escape']))
            ->toThrow(LogicException::class, 'outside existing transactions');
    } finally {
        $connection->rollBack();
    }
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

it('keeps committed asset batches through an interrupted nvl:adopt apply and resumes without duplicates', function (): void {
    ['assets' => $assets, 'pivot' => $pivot] = seedLegacyMediaGraph(assets: 3);
    $manifest = prepareMediaAdoption();
    $mediaTable = MediaTables::get(MediaTables::Media);

    $plan = adoptMedia('plan', $manifest);
    expect($plan['status'])->toBe(0)
        ->and($plan['collisions'])->toBe([])
        ->and(array_map(static fn (array $count): ?int => $count['expected'], $plan['counts']))->toBe(['assets' => 3, 'associations' => 1, 'variations' => 1, 'translations' => 1])
        ->and(DB::table(AdoptionStore::Runs)->count())->toBe(0)
        ->and(DB::table($mediaTable)->count())->toBe(0);

    $inserts = 0;
    DB::connection()->beforeExecuting(static function (string $sql) use (&$inserts, $mediaTable): void {
        if (str_starts_with($sql, 'insert into "'.$mediaTable.'"') && ++$inserts === 2) {
            throw new RuntimeException('simulated interruption');
        }
    });
    $failed = adoptMedia('apply', $manifest, ['--fingerprint' => $plan['fingerprint'], '--batch-size' => 1]);
    $run = (string) DB::table(AdoptionStore::Runs)->value('id');
    expect($failed['status'])->toBe(1)
        ->and($failed['error'])->toContain('simulated interruption')
        ->and(DB::table($mediaTable)->pluck('id')->all())->toBe([collect($assets)->sort()->first()])
        ->and(DB::table(AdoptionStore::Runs)->value('status'))->toBe('failed')
        ->and((int) DB::table(AdoptionStore::Checkpoints)->where('step', 'assets')->value('processed'))->toBe(1);

    expect(adoptMedia('apply', $manifest, ['--fingerprint' => $plan['fingerprint']])['error'])->toContain('--run='.$run);
    $resumed = adoptMedia('apply', $manifest, ['--fingerprint' => $plan['fingerprint'], '--batch-size' => 1, '--run' => $run]);
    expect($resumed['status'])->toBe(0)
        ->and($resumed['state'])->toBe('applied')
        ->and(array_map(static fn (array $count): int => $count['processed'], $resumed['counts']))->toBe(['assets' => 3, 'associations' => 1, 'variations' => 1, 'translations' => 1])
        ->and(DB::table($mediaTable)->orderBy('id')->pluck('id')->all())->toBe(collect($assets)->sort()->values()->all())
        ->and(DB::table(MediaTables::get(MediaTables::Associations))->pluck('id')->all())->toBe([$pivot]);

    expect(adoptMedia('verify', $manifest)['verification']['passed'])->toBeTrue()
        ->and(adoptMedia('activate', $manifest, ['--fingerprint' => $plan['fingerprint']])['state'])->toBe('active')
        ->and(app(AdoptionGate::class)->isActive('media'))->toBeTrue();

    $legacy = app(MediaSchemaAdopter::class);
    $review = $legacy->plan(SystemActor::Background, LEGACY_MEDIA_OPTIONS);
    expect($legacy->verify(SystemActor::Background, $review['fingerprint'], LEGACY_MEDIA_OPTIONS)['staged'])
        ->toBe(['assets' => 3, 'associations' => 1, 'variations' => 1, 'translations' => 1]);
});

it('blocks Media activation while a staged backing file is missing', function (): void {
    seedLegacyMediaGraph();
    $manifest = prepareMediaAdoption();
    $plan = adoptMedia('plan', $manifest);
    expect(adoptMedia('apply', $manifest, ['--fingerprint' => $plan['fingerprint']])['state'])->toBe('applied')
        ->and(adoptMedia('verify', $manifest)['verification']['passed'])->toBeTrue();

    Storage::disk('public')->delete('legacy/photos/conversions/91-small.webp');
    $activation = adoptMedia('activate', $manifest, ['--fingerprint' => $plan['fingerprint']]);
    expect($activation['status'])->toBe(1)
        ->and($activation['error'])->toContain('changed since verification');

    $verification = adoptMedia('verify', $manifest);
    expect($verification['status'])->toBe(1)
        ->and($verification['verification']['passed'])->toBeFalse()
        ->and($verification['verification']['problems'])->toContain('px_media_image_variations ['.DB::table('px_media_image_variations')->value('id').'] has no backing file [public:legacy/photos/conversions/91-small.webp].')
        ->and(adoptMedia('activate', $manifest, ['--fingerprint' => $plan['fingerprint']])['error'])->toContain('requires a passed verification')
        ->and(adoptMedia('plan', $manifest)['collisions'])->not->toBeEmpty()
        ->and(app(AdoptionGate::class)->isActive('media'))->toBeFalse();
});

it('refuses to resume nvl:adopt apply after staged Media rows behind the checkpoint were deleted', function (): void {
    ['assets' => $assets] = seedLegacyMediaGraph(assets: 3);
    $manifest = prepareMediaAdoption();
    $mediaTable = MediaTables::get(MediaTables::Media);
    $associationTable = MediaTables::get(MediaTables::Associations);
    $plan = adoptMedia('plan', $manifest);

    $inserts = 0;
    DB::connection()->beforeExecuting(static function (string $sql) use (&$inserts, $mediaTable): void {
        if (str_starts_with($sql, 'insert into "'.$mediaTable.'"') && ++$inserts === 2) {
            throw new RuntimeException('simulated interruption');
        }
    });
    $failed = adoptMedia('apply', $manifest, ['--fingerprint' => $plan['fingerprint'], '--batch-size' => 1]);
    $run = (string) DB::table(AdoptionStore::Runs)->value('id');
    $first = collect($assets)->sort()->first();
    expect($failed['status'])->toBe(1)
        ->and(DB::table($mediaTable)->pluck('id')->all())->toBe([$first]);

    $staged = (array) DB::table($mediaTable)->where('id', $first)->first();
    DB::table($mediaTable)->where('id', $first)->delete();
    $behindCursor = adoptMedia('apply', $manifest, ['--fingerprint' => $plan['fingerprint'], '--batch-size' => 1, '--run' => $run]);
    expect($behindCursor['status'])->toBe(1)
        ->and($behindCursor['error'])->toContain('behind deleted staged Media row ['.$first.']')
        ->and(DB::table($mediaTable)->count())->toBe(0)
        ->and((int) DB::table(AdoptionStore::Checkpoints)->where('step', 'assets')->value('processed'))->toBe(1);

    $interruptedAssociations = false;
    DB::connection()->beforeExecuting(static function (string $sql) use (&$interruptedAssociations, $associationTable): void {
        if (! $interruptedAssociations && str_starts_with($sql, 'insert into "'.$associationTable.'"')) {
            $interruptedAssociations = true;
            throw new RuntimeException('simulated association interruption');
        }
    });
    DB::table($mediaTable)->insert($staged);
    $second = adoptMedia('apply', $manifest, ['--fingerprint' => $plan['fingerprint'], '--run' => $run]);
    expect($second['status'])->toBe(1)
        ->and($second['error'])->toContain('simulated association interruption')
        ->and(DB::table($mediaTable)->count())->toBe(3);

    DB::table($mediaTable)->where('id', $assets[0])->delete();
    $completedDependency = adoptMedia('apply', $manifest, ['--fingerprint' => $plan['fingerprint'], '--run' => $run]);
    expect($completedDependency['status'])->toBe(1)
        ->and($completedDependency['error'])->toContain('behind deleted staged Media row ['.$assets[0].']')
        ->and(DB::table($associationTable)->count())->toBe(0)
        ->and(DB::table($mediaTable)->count())->toBe(2);
});

it('identical exclusive uploads have distinct rows and paths', function (): void {
    Storage::fake('public');
    $owner = StandaloneMediaOwner::query()->create(['name' => 'Exclusive owner']);
    $slot = (new MediaSlot('contract'))->isPublic()->exclusive();
    $upload = static fn (?bool $deduplicate = null): Media => app(MediaUploader::class)->upload(
        SystemActor::Background, UploadedFile::fake()->createWithContent('contract.txt', 'Identical contract bytes.'),
        'public', $owner, $slot, 'contract.txt', isPublic: true, deduplicateExisting: $deduplicate, skipAutoVariations: true,
    );

    $first = $upload();
    $second = $upload();
    expect($second->id)->not->toBe($first->id)
        ->and($second->buildPath())->not->toBe($first->buildPath())
        ->and(Storage::disk('public')->get($first->buildPath()))->toBe('Identical contract bytes.')
        ->and(Storage::disk('public')->get($second->buildPath()))->toBe('Identical contract bytes.')
        ->and(fn () => $upload(true))->toThrow(InvalidArgumentException::class, 'cannot deduplicate')
        ->and(Media::query()->count())->toBe(2);
});

it('refuses to resume when the checkpoint count differs from the legacy rows before its cursor', function (): void {
    seedLegacyMediaGraph(assets: 3);
    $manifest = prepareMediaAdoption();
    $mediaTable = MediaTables::get(MediaTables::Media);
    $plan = adoptMedia('plan', $manifest);
    $inserts = 0;
    DB::connection()->beforeExecuting(static function (string $sql) use (&$inserts, $mediaTable): void {
        if (str_starts_with($sql, 'insert into "'.$mediaTable.'"') && ++$inserts === 2) {
            throw new RuntimeException('simulated interruption');
        }
    });
    expect(adoptMedia('apply', $manifest, ['--fingerprint' => $plan['fingerprint'], '--batch-size' => 1])['status'])->toBe(1);
    $run = (string) DB::table(AdoptionStore::Runs)->value('id');
    DB::table(AdoptionStore::Checkpoints)->where('step', 'assets')->update(['processed' => 2]);

    $resumed = adoptMedia('apply', $manifest, ['--fingerprint' => $plan['fingerprint'], '--batch-size' => 1, '--run' => $run]);
    expect($resumed['status'])->toBe(1)
        ->and($resumed['error'])->toContain('counts 2 source rows, but 1 precede its cursor')
        ->and(DB::table($mediaTable)->count())->toBe(1);
});
