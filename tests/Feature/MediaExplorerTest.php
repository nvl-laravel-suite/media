<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Tests\Stubs\TestMediaUser;

function explorerUser(array $overrides = []): TestMediaUser
{
    return TestMediaUser::withoutEvents(
        static fn (): TestMediaUser => TestMediaUser::forceCreate(array_merge([
            'name' => 'Explorer User',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'yIXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',

        ], $overrides)),
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function explorerMedia(array $overrides = []): Media
{
    $attributes = array_merge([
        'filename' => 'test-file.jpg',
        'hash' => md5(uniqid('', true)).'.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'disk' => 'public',
        'folder' => 'test',
        'is_public' => true,
        'type' => MediaType::IMAGE,
        'digest' => md5('test'),
    ], $overrides);

    if (($attributes['uploaded_by'] ?? null) !== null
        && ! array_key_exists('uploaded_by_type', $overrides)) {
        $attributes['uploaded_by_type'] = TestMediaUser::class;
    }

    return Media::create($attributes);
}

/** Extract paginated items from API response, handling both nested and flat structures. */
function extractMediaItems(TestResponse $response): array
{
    return (array) $response->json('data.media.items');
}

/* =================================================================
 * New Filters
 * ================================================================= */

describe('New index filters', function () {

    it('filters by folder', function () {
        $user = explorerUser();

        explorerMedia(['folder' => 'photos', 'is_public' => true]);
        explorerMedia(['folder' => 'documents', 'is_public' => true]);

        $response = $this->actingAs($user)->getJson('/api/v1/media?folder=photos');

        $response->assertStatus(200);
        $items = extractMediaItems($response);
        collect($items)->each(fn ($item) => expect($item['folder'])->toBe('photos'));
    });

    it('filters by mimeType', function () {
        $user = explorerUser();

        explorerMedia(['mime_type' => 'image/jpeg', 'is_public' => true]);
        explorerMedia([
            'mime_type' => 'application/pdf',
            'filename' => 'doc.pdf',
            'extension' => 'pdf',
            'type' => MediaType::DOCUMENT,
            'is_public' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/media?mimeType=application/pdf');

        $response->assertStatus(200);
        $items = extractMediaItems($response);
        collect($items)->each(fn ($item) => expect($item['mimeType'])->toBe('application/pdf'));
    });

    it('filters by extension', function () {
        $user = explorerUser();

        explorerMedia(['extension' => 'jpg', 'is_public' => true]);
        explorerMedia([
            'extension' => 'pdf',
            'filename' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'type' => MediaType::DOCUMENT,
            'is_public' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/media?extension=pdf');

        $response->assertStatus(200);
        $items = extractMediaItems($response);
        collect($items)->each(fn ($item) => expect($item['extension'])->toBe('pdf'));
    });

    it('filters by locale', function () {
        $user = explorerUser();

        $media_en = explorerMedia(['is_public' => true]);
        $media_no_trans = explorerMedia(['is_public' => true]);

        MediaTranslation::create([
            'media_id' => $media_en->id,
            'locale' => 'en',
            'title' => 'English title',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/media?locale=en');

        $response->assertStatus(200);

        $items = extractMediaItems($response);
        $ids = collect($items)->pluck('id')->toArray();
        expect($ids)->toContain($media_en->id)
            ->not->toContain($media_no_trans->id);
    });

    it('filters by associableType', function () {
        $user = explorerUser();

        $media_with = explorerMedia(['is_public' => true]);
        $media_without = explorerMedia(['is_public' => true]);

        MediaAssociation::create([
            'media_id' => $media_with->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/media?associableType='.urlencode(TestMediaUser::class));

        $response->assertStatus(200);
        $items = extractMediaItems($response);
        $ids = collect($items)->pluck('id')->toArray();
        expect($ids)->toContain($media_with->id)
            ->not->toContain($media_without->id);
    });

    it('combines associableType with collection filter', function () {
        $user = explorerUser();

        $media_avatar = explorerMedia(['is_public' => true]);
        $media_gallery = explorerMedia(['is_public' => true]);

        MediaAssociation::create([
            'media_id' => $media_avatar->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        MediaAssociation::create([
            'media_id' => $media_gallery->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'gallery',
            'order' => 0,
        ]);

        $response = $this->actingAs($user)->getJson(
            '/api/v1/media?associableType='.urlencode(TestMediaUser::class).'&collection=avatar'
        );

        $response->assertStatus(200);
        $items = extractMediaItems($response);
        $ids = collect($items)->pluck('id')->toArray();
        expect($ids)->toContain($media_avatar->id)
            ->not->toContain($media_gallery->id);
    });
});

/* =================================================================
 * Rename
 * ================================================================= */

describe('PATCH /api/v1/media/{id}/rename', function () {

    it('renames media successfully', function () {
        $user = explorerUser();
        $media = explorerMedia(['uploaded_by' => $user->id]);

        $response = $this->actingAs($user)->patchJson("/api/v1/media/{$media->id}/rename", [
            'filename' => 'new-name.jpg',
        ]);

        $response->assertStatus(200);
        expect(Media::find($media->id)->filename)->toBe('new-name.jpg');
    });

    it('validates filename is required', function () {
        $user = explorerUser();
        $media = explorerMedia(['uploaded_by' => $user->id]);

        $response = $this->actingAs($user)->patchJson("/api/v1/media/{$media->id}/rename", []);

        $response->assertStatus(422);
    });

    it('denies non-owner from renaming', function () {
        $owner = explorerUser();
        $other = explorerUser();
        $media = explorerMedia(['uploaded_by' => $owner->id]);

        $response = $this->actingAs($other)->patchJson("/api/v1/media/{$media->id}/rename", [
            'filename' => 'hacked.jpg',
        ]);

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $this->patchJson('/api/v1/media/00000000-0000-0000-0000-000000000000/rename')
            ->assertStatus(401);
    });
});

/* =================================================================
 * Usages
 * ================================================================= */

describe('GET /api/v1/media/{id}/usages', function () {

    it('returns association details', function () {
        $user = explorerUser();
        $media = explorerMedia(['is_public' => true]);

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}/usages");

        $response->assertStatus(200);

        $usages = $response->json('data');
        expect($usages)->toHaveCount(1);
        expect($usages[0]['type'])->toBe(TestMediaUser::class);
        expect($usages[0]['modelId'])->toEqual($user->id);
        expect($usages[0])->not->toHaveKey('model_id');
        expect($usages[0]['collection'])->toBe('avatar');
    });

    it('returns empty array for unused media', function () {
        $user = explorerUser();
        $media = explorerMedia(['is_public' => true]);

        $response = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}/usages");

        $response->assertStatus(200);
        expect($response->json('data'))->toBeEmpty();
    });

    it('requires authentication', function () {
        $this->getJson('/api/v1/media/00000000-0000-0000-0000-000000000000/usages')
            ->assertStatus(401);
    });
});

/* =================================================================
 * Download
 * ================================================================= */

describe('GET /api/v1/media/{id}/download', function () {

    it('streams file with correct headers', function () {
        Storage::fake('public');

        $user = explorerUser();
        $media = explorerMedia(['is_public' => true]);

        Storage::disk('public')->put($media->buildPath(), 'file-content');

        $response = $this->actingAs($user)->get("/api/v1/media/{$media->id}/download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
    });

    it('returns 404 for missing file on disk', function () {
        Storage::fake('public');

        $user = explorerUser();
        $media = explorerMedia(['is_public' => true]);
        // Don't put any file on disk

        $response = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}/download");

        $response->assertStatus(404);
    });

    it('requires authentication', function () {
        $this->getJson('/api/v1/media/00000000-0000-0000-0000-000000000000/download')
            ->assertStatus(401);
    });
});

/* =================================================================
 * Replace
 * ================================================================= */

describe('POST /api/v1/media/{id}/replace', function () {

    beforeEach(function () {
        Storage::fake('public');

        config([
            'filesystems.default' => 'public',
            'media.delete_files_on_media_delete' => true,
            'media.clean_empty_directories' => false,
            'media.cache_file_existence' => false,
            'media.group_types' => [
                'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'document' => ['pdf', 'doc', 'docx'],
            ],
        ]);
    });

    it('replaces file and updates metadata', function () {
        $user = explorerUser();
        $media = explorerMedia(['uploaded_by' => $user->id]);
        $old_hash = $media->hash;

        // Put old file on disk
        Storage::disk('public')->put($media->buildPath(), 'old-content');

        $new_file = UploadedFile::fake()->image('replacement.png', 200, 200);

        $response = $this->actingAs($user)->postJson("/api/v1/media/{$media->id}/replace", [
            'file' => $new_file,
        ]);

        $response->assertStatus(200);

        $fresh = Media::find($media->id);
        expect($fresh->filename)->toBe('replacement.png');
        expect($fresh->extension)->toBe('png');
        expect($fresh->hash)->not->toBe($old_hash);
    });

    it('validates file is required', function () {
        $user = explorerUser();
        $media = explorerMedia(['uploaded_by' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/v1/media/{$media->id}/replace", []);

        $response->assertStatus(422);
    });

    it('denies non-owner from replacing', function () {
        $owner = explorerUser();
        $other = explorerUser();
        $media = explorerMedia(['uploaded_by' => $owner->id]);

        $file = UploadedFile::fake()->image('hack.png', 100, 100);

        $response = $this->actingAs($other)->postJson("/api/v1/media/{$media->id}/replace", [
            'file' => $file,
        ]);

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $this->postJson('/api/v1/media/00000000-0000-0000-0000-000000000000/replace')
            ->assertStatus(401);
    });
});

/* =================================================================
 * Bulk Operations
 * ================================================================= */

describe('POST /api/v1/media/bulk', function () {

    beforeEach(function () {
        Storage::fake('public');
        config([
            'media.delete_files_on_media_delete' => true,
            'media.clean_empty_directories' => false,
            'media.cache_file_existence' => false,
        ]);
    });

    it('bulk deletes multiple items', function () {
        $user = explorerUser();
        $m1 = explorerMedia(['uploaded_by' => $user->id]);
        $m2 = explorerMedia(['uploaded_by' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/v1/media/bulk', [
            'action' => 'delete',
            'ids' => [$m1->id, $m2->id],
        ]);

        $response->assertStatus(200);
        expect($response->json('data.affected'))->toBe(2);
        expect(Media::find($m1->id))->toBeNull();
        expect(Media::find($m2->id))->toBeNull();
    });

    it('bulk tags multiple items', function () {
        $user = explorerUser();
        $m1 = explorerMedia(['uploaded_by' => $user->id, 'tags' => ['existing']]);
        $m2 = explorerMedia(['uploaded_by' => $user->id, 'tags' => []]);

        $response = $this->actingAs($user)->postJson('/api/v1/media/bulk', [
            'action' => 'tag',
            'ids' => [$m1->id, $m2->id],
            'tags' => ['new-tag', 'another'],
        ]);

        $response->assertStatus(200);
        expect($response->json('data.affected'))->toBe(2);

        $fresh1 = Media::find($m1->id);
        expect($fresh1->tags)->toContain('existing')
            ->toContain('new-tag')
            ->toContain('another');

        $fresh2 = Media::find($m2->id);
        expect($fresh2->tags)->toContain('new-tag')
            ->toContain('another');
    });

    it('bulk moves items to new folder', function () {
        $user = explorerUser();
        $m1 = explorerMedia(['uploaded_by' => $user->id, 'folder' => 'old']);
        $m2 = explorerMedia(['uploaded_by' => $user->id, 'folder' => 'old']);

        Storage::disk('public')->put($m1->buildPath(), 'content1');
        Storage::disk('public')->put($m2->buildPath(), 'content2');

        $response = $this->actingAs($user)->postJson('/api/v1/media/bulk', [
            'action' => 'move',
            'ids' => [$m1->id, $m2->id],
            'folder' => 'new-folder',
        ]);

        $response->assertStatus(200);
        expect($response->json('data.affected'))->toBe(2);

        expect(Media::find($m1->id)->folder)->toBe('new-folder');
        expect(Media::find($m2->id)->folder)->toBe('new-folder');
    });

    it('validates action field', function () {
        $user = explorerUser();

        $response = $this->actingAs($user)->postJson('/api/v1/media/bulk', [
            'action' => 'invalid',
            'ids' => ['00000000-0000-0000-0000-000000000000'],
        ]);

        $response->assertStatus(422);
    });

    it('validates ids are required', function () {
        $user = explorerUser();

        $response = $this->actingAs($user)->postJson('/api/v1/media/bulk', [
            'action' => 'delete',
        ]);

        $response->assertStatus(422);
    });

    it('requires authentication', function () {
        $this->postJson('/api/v1/media/bulk')->assertStatus(401);
    });
});

/* =================================================================
 * Show response enhancements
 * ================================================================= */

describe('Show response includes usages and file_exists', function () {

    it('includes usages list in show response', function () {
        Storage::fake('public');

        $user = explorerUser();
        $media = explorerMedia(['is_public' => true]);

        Storage::disk('public')->put($media->buildPath(), 'content');

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'profile',
            'order' => 0,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}");

        $response->assertStatus(200);

        $media_data = $response->json('data');
        expect($media_data)->toHaveKey('usages');
        expect($media_data['usages'])->toHaveCount(1);
        expect($media_data['usages'][0]['collection'])->toBe('profile');
    });

    it('includes file_exists in show response', function () {
        Storage::fake('public');

        $user = explorerUser();
        $media = explorerMedia(['is_public' => true]);

        // Put file so it exists
        Storage::disk('public')->put($media->buildPath(), 'content');

        // Disable caching for test
        config(['media.cache_file_existence' => false]);

        $response = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}");

        $response->assertStatus(200);
        expect($response->json('data.fileExists'))->toBeTrue();
    });

    it('file_exists is false when file missing from disk', function () {
        Storage::fake('public');

        $user = explorerUser();
        $media = explorerMedia(['is_public' => true]);
        // Don't put file on disk

        config(['media.cache_file_existence' => false]);

        $response = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}");

        $response->assertStatus(200);
        expect($response->json('data.fileExists'))->toBeFalse();
    });
});
