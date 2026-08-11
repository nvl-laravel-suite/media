<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\DefaultMediaAuthorization;
use Nvl\Media\Tests\Stubs\TestMediaUser;
use Nvl\Media\Tests\Stubs\TestPermissionMediaUser;

beforeEach(function () {
    Gate::define('update', fn ($u, $m) => (string) $u->id === (string) $m->id);
    config()->set('media.associable_mutation_abilities', []);
    config()->set('media.allowed_associable_types', [TestMediaUser::class]);
});

function createApiUser(array $overrides = []): TestMediaUser
{
    return TestMediaUser::withoutEvents(
        static fn (): TestMediaUser => TestMediaUser::forceCreate(array_merge([
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'yIXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',

        ], $overrides)),
    );
}

/**
 * @param  list<string>  $roles
 * @param  list<string>  $permissions
 */
function createPermissionApiUser(
    array $roles = [],
    array $permissions = [],
): TestPermissionMediaUser {
    $user = TestPermissionMediaUser::withoutEvents(
        static fn (): TestPermissionMediaUser => TestPermissionMediaUser::forceCreate([
            'name' => 'Privileged Media User',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'yIXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]),
    );

    return $user
        ->withMediaRoles($roles)
        ->withMediaPermissions($permissions);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createApiMedia(array $overrides = []): Media
{
    $attributes = array_merge([
        'filename' => 'test-image.jpg',
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

function createUnsupportedApiAssociable(): Model
{
    $table = 'test_non_media_associables';

    if (! Schema::hasTable($table)) {
        Schema::create($table, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }

    $unsupportedAssociable = new class extends Model
    {
        protected $table = 'test_non_media_associables';

        protected $fillable = ['id', 'name'];

        public $incrementing = false;

        protected $keyType = 'string';
    };

    $unsupportedAssociable->forceFill([
        'id' => (string) Str::uuid(),
        'name' => 'Unsupported Associable',
    ])->save();

    return $unsupportedAssociable;
}

/* =================================================================
 * Authentication
 * ================================================================= */

describe('Authentication', function () {

    it('requires authentication for all endpoints', function () {
        $this->getJson('/api/v1/media')->assertStatus(401);
        $this->postJson('/api/v1/media')->assertStatus(401);
        $this->getJson('/api/v1/media/00000000-0000-0000-0000-000000000000')->assertStatus(401);
        $this->putJson('/api/v1/media/00000000-0000-0000-0000-000000000000')->assertStatus(401);
        $this->deleteJson('/api/v1/media/00000000-0000-0000-0000-000000000000')->assertStatus(401);
        $this->postJson('/api/v1/media/00000000-0000-0000-0000-000000000000/attach')->assertStatus(401);
        $this->postJson('/api/v1/media/00000000-0000-0000-0000-000000000000/detach')->assertStatus(401);
        $this->getJson('/api/v1/media/00000000-0000-0000-0000-000000000000/variations')->assertStatus(401);
        $this->postJson('/api/v1/media/00000000-0000-0000-0000-000000000000/regenerate')->assertStatus(401);
        $this->postJson('/api/v1/media/reorder')->assertStatus(401);
    });

    it('requires an exact morph identity for private ownership', function () {
        $owner = createApiUser();
        $authorization = new DefaultMediaAuthorization;
        $media = createApiMedia([
            'is_public' => false,
            'uploaded_by' => $owner->id,
            'uploaded_by_type' => $owner->getMorphClass(),
        ]);

        expect($authorization->allows(
            new MediaActorData($owner->getMorphClass(), $owner->id),
            MediaAbility::View,
            $media,
        ))->toBeTrue()
            ->and($authorization->allows(
                new MediaActorData(TestPermissionMediaUser::class, $owner->id),
                MediaAbility::View,
                $media,
            ))->toBeFalse();

        $media->uploaded_by_type = null;

        expect($authorization->allows(
            new MediaActorData($owner->getMorphClass(), $owner->id),
            MediaAbility::View,
            $media,
        ))->toBeFalse()
            ->and($authorization->allows(
                new MediaActorData(null, $owner->id),
                MediaAbility::View,
                $media,
            ))->toBeFalse();
    });
});

/* =================================================================
 * GET /api/v1/media (index)
 * ================================================================= */

describe('GET /api/v1/media (index)', function () {

    it('lists media with pagination', function () {
        $user = createApiUser();

        createApiMedia(['filename' => 'file-1.jpg', 'is_public' => true]);
        createApiMedia(['filename' => 'file-2.jpg', 'is_public' => true]);
        createApiMedia(['filename' => 'file-3.jpg', 'is_public' => true]);

        $response = $this->actingAs($user)->getJson('/api/v1/media');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'media' => ['items', 'links', 'meta'],
                    'filterOptions',
                    'dialogConfig',
                ],
            ]);
    });

    it('filters by search term', function () {
        $user = createApiUser();

        createApiMedia(['filename' => 'sunset-photo.jpg', 'is_public' => true]);
        createApiMedia(['filename' => 'mountain-view.jpg', 'is_public' => true]);

        $response = $this->actingAs($user)->getJson('/api/v1/media?search=sunset');

        $response->assertStatus(200);

        $media_data = $response->json('data.media.items');

        // Should only find the sunset media
        collect($media_data)->each(function ($item) {
            expect(str_contains($item['filename'], 'sunset'))->toBeTrue();
        });
    });

    it('filters by type', function () {
        $user = createApiUser();

        createApiMedia(['type' => MediaType::IMAGE, 'is_public' => true]);
        createApiMedia([
            'type' => MediaType::DOCUMENT,
            'filename' => 'report.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'is_public' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/media?type=document');

        $response->assertStatus(200);
    });

    it('rejects invalid filter values through the declared DTO rules', function (string $query) {
        $user = createApiUser();

        $this->actingAs($user)
            ->getJson('/api/v1/media?'.$query)
            ->assertUnprocessable();
    })->with([
        'oversized search' => ['search='.str_repeat('a', 256)],
        'unknown type' => ['type=unknown'],
        'invalid public flag' => ['isPublic=maybe'],
        'zero page size' => ['perPage=0'],
        'unknown sort column' => ['sortBy=uploaded_by'],
        'invalid sort direction' => ['sortDirection=sideways'],
    ]);

    it('scopes results to own media and public media', function () {
        Storage::fake('public');

        $owner = createApiUser();
        $other = createApiUser();

        $media_items = [
            createApiMedia(['filename' => 'owner-public.jpg', 'uploaded_by' => $owner->id, 'is_public' => true]),
            createApiMedia(['filename' => 'owner-private.jpg', 'uploaded_by' => $owner->id, 'is_public' => false]),
            createApiMedia(['filename' => 'other-public.jpg', 'uploaded_by' => $other->id, 'is_public' => true]),
            createApiMedia(['filename' => 'other-private.jpg', 'uploaded_by' => $other->id, 'is_public' => false]),
            createApiMedia([
                'filename' => 'same-id-wrong-type-private.jpg',
                'uploaded_by' => $owner->id,
                'uploaded_by_type' => TestPermissionMediaUser::class,
                'is_public' => false,
            ]),
            createApiMedia([
                'filename' => 'same-id-untyped-private.jpg',
                'uploaded_by' => $owner->id,
                'uploaded_by_type' => null,
                'is_public' => false,
            ]),
        ];

        // Put files so getUrl() won't fail on private media
        foreach ($media_items as $m) {
            Storage::disk('public')->put($m->folder.'/'.$m->hash, 'content');
        }

        $response = $this->actingAs($owner)->getJson('/api/v1/media');
        $response->assertStatus(200);

        $items = $response->json('data.media.items');
        $filenames = collect($items)->pluck('filename')->toArray();

        expect($filenames)->toContain('owner-public.jpg')
            ->toContain('owner-private.jpg')
            ->toContain('other-public.jpg')
            ->not->toContain('other-private.jpg')
            ->not->toContain('same-id-wrong-type-private.jpg')
            ->not->toContain('same-id-untyped-private.jpg');
    });

    it('allows a consumer authorizer to grant cross-uploader listing', function () {
        Storage::fake('public');

        $manager = createApiUser();
        $other = createApiUser();
        app()->instance(MediaAuthorization::class, new class($manager->id) implements MediaAuthorization
        {
            private DefaultMediaAuthorization $fallback;

            public function __construct(
                private readonly int|string $managerId,
            ) {
                $this->fallback = new DefaultMediaAuthorization;
            }

            public function allows(
                MediaActorData $actor,
                MediaAbility $ability,
                ?Media $media = null,
                ?Model $owner = null,
            ): bool {
                if ($ability === MediaAbility::ListAll) {
                    return (string) $actor->id === (string) $this->managerId;
                }

                return $this->fallback->allows($actor, $ability, $media, $owner);
            }
        });

        $visiblePrivate = createApiMedia([
            'filename' => 'other-private-visible.jpg',
            'uploaded_by' => $other->id,
            'is_public' => false,
        ]);
        $deletedPrivate = createApiMedia([
            'filename' => 'other-private-deleted.jpg',
            'uploaded_by' => $other->id,
            'is_public' => false,
        ]);

        foreach ([$visiblePrivate, $deletedPrivate] as $media) {
            Storage::disk('public')->put($media->folder.'/'.$media->hash, 'content');
        }

        $deletedPrivate->delete();

        $response = $this->actingAs($manager)->getJson('/api/v1/media');
        $response->assertStatus(200);

        $items = $response->json('data.media.items');
        $filenames = collect($items)->pluck('filename')->toArray();

        expect($filenames)->toContain('other-private-visible.jpg')
            ->not->toContain('other-private-deleted.jpg');
    });

    it('lets a configured global role list and delete media across ownership boundaries', function () {
        Storage::fake('public');
        config()->set(
            'media.authorization.spatie_permission.global_roles',
            ['media-admin'],
        );

        $administrator = createPermissionApiUser(roles: ['media-admin']);
        $owner = createApiUser();
        $media = createApiMedia([
            'filename' => 'owner-private.jpg',
            'uploaded_by' => $owner->id,
            'uploaded_by_type' => $owner->getMorphClass(),
            'is_public' => false,
        ]);
        Storage::disk('public')->put($media->folder.'/'.$media->hash, 'content');

        $this->actingAs($administrator)
            ->getJson('/api/v1/media')
            ->assertOk()
            ->assertJsonFragment(['filename' => 'owner-private.jpg']);

        $this->actingAs($administrator)
            ->deleteJson("/api/v1/media/{$media->id}")
            ->assertOk();

        expect(Media::withTrashed()->findOrFail($media->id)->trashed())->toBeTrue();
    });

    it('honors the granular delete-any permission without granting unconfigured roles', function () {
        Storage::fake('public');

        $administrator = createPermissionApiUser(
            roles: ['admin'],
            permissions: ['media.delete-any'],
        );
        $owner = createApiUser();
        $media = createApiMedia([
            'uploaded_by' => $owner->id,
            'uploaded_by_type' => $owner->getMorphClass(),
            'is_public' => false,
        ]);

        $this->actingAs($administrator)
            ->deleteJson("/api/v1/media/{$media->id}")
            ->assertOk();

        expect(Media::withTrashed()->findOrFail($media->id)->trashed())->toBeTrue();
    });

    it('supports include_variations flag for index responses', function () {
        $user = createApiUser();
        $media = createApiMedia(['is_public' => true]);

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 150,
            'height' => 150,
            'size' => 256,
            'format' => 'webp',
            'quality' => 80,
        ]);

        $without = $this->actingAs($user)->getJson('/api/v1/media?include_variations=0');
        $with = $this->actingAs($user)->getJson('/api/v1/media?include_variations=1');

        $without->assertStatus(200);
        $with->assertStatus(200);

        $withoutItems = (array) $without->json('data.media.items');
        $withItems = (array) $with->json('data.media.items');

        $withoutFirst = collect($withoutItems)->first();
        $withFirst = collect($withItems)->first();

        expect($withoutFirst)->toBeArray()
            ->and(array_key_exists('imageVariations', $withoutFirst))->toBeFalse()
            ->and($withFirst)->toBeArray()
            ->and(array_key_exists('imageVariations', $withFirst))->toBeTrue();
    });
});

/* =================================================================
 * GET /api/v1/media/{id} (show)
 * ================================================================= */

describe('GET /api/v1/media/{id} (show)', function () {

    it('returns a single media item', function () {
        $user = createApiUser();
        $media = createApiMedia(['is_public' => true]);

        $response = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $media->id)
            ->assertJsonPath('data.filename', $media->filename);
    });

    it('returns every persisted translation field', function () {
        $user = createApiUser();
        $media = createApiMedia(['is_public' => true]);
        $media->translations()->create([
            'locale' => 'en',
            'title' => 'Campaign',
            'alt' => 'Campaign photograph',
            'caption' => 'Campaign caption',
            'description' => 'Campaign description',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/media/{$media->id}")
            ->assertOk()
            ->assertJsonPath('data.translations.en.title', 'Campaign')
            ->assertJsonPath('data.translations.en.alt', 'Campaign photograph')
            ->assertJsonPath('data.translations.en.caption', 'Campaign caption')
            ->assertJsonPath('data.translations.en.description', 'Campaign description');
    });

    it('returns 404 for non-existent media', function () {
        $user = createApiUser();

        $response = $this->actingAs($user)->getJson('/api/v1/media/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    });

    it('allows owner to view private media', function () {
        Storage::fake('public');
        $user = createApiUser();
        $media = createApiMedia(['is_public' => false, 'uploaded_by' => $user->id]);

        // Put a file so getUrl() won't fail
        Storage::disk('public')->put($media->folder.'/'.$media->hash, 'content');

        $response = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}");

        $response->assertStatus(200);
    });

    it('denies non-owner from viewing private media', function () {
        $owner = createApiUser();
        $other = createApiUser();
        $media = createApiMedia(['is_public' => false, 'uploaded_by' => $owner->id]);

        $response = $this->actingAs($other)->getJson("/api/v1/media/{$media->id}");

        $response->assertStatus(403);
    });

    it('supports include_variations flag for detail responses', function () {
        $user = createApiUser();
        $media = createApiMedia(['is_public' => true]);

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 150,
            'height' => 150,
            'size' => 256,
            'format' => 'webp',
            'quality' => 80,
        ]);

        $without = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}?include_variations=0");
        $with = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}?include_variations=1");

        $without->assertStatus(200);
        $with->assertStatus(200);

        expect(array_key_exists('imageVariations', (array) $without->json('data')))->toBeFalse()
            ->and(array_key_exists('imageVariations', (array) $with->json('data')))->toBeTrue();
    });

    it('serializes usages with the camelCase modelId contract', function () {
        $user = createApiUser();
        $media = createApiMedia(['is_public' => true]);

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.usages.0.modelId', (string) $user->id);

        expect($response->json('data.usages.0'))->not->toHaveKey('model_id');
    });
});

/* =================================================================
 * POST /api/v1/media (store)
 * ================================================================= */

describe('POST /api/v1/media (store)', function () {

    beforeEach(function () {
        Storage::fake('public');

        config([
            'filesystems.default' => 'public',
            'media.default_path' => '{model_type}/{model_id}',
            'media.auto_generate_variations' => false,
            'media.group_types' => [
                'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'document' => ['pdf', 'doc', 'docx'],
            ],
        ]);
    });

    it('uploads files successfully', function () {
        $user = createApiUser();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $response = $this->actingAs($user)->postJson('/api/v1/media', [
            'files' => [$file],
            'collection' => 'gallery',
            'is_public' => true,
        ]);

        $response->assertStatus(201);

        expect(Media::count())->toBeGreaterThanOrEqual(1);
    });

    it('uses the configured default path instead of the collection name as storage folder', function () {
        $user = createApiUser();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $this->actingAs($user)->postJson('/api/v1/media', [
            'files' => [$file],
            'collection' => 'gallery',
            'is_public' => true,
        ])->assertStatus(201);

        /** @var Media $media */
        $media = Media::query()->latest('created_at')->firstOrFail();

        expect($media->folder)->toBe("test_media_user/{$user->id}")
            ->and($media->folder)->not->toBe('gallery');
    });

    it('validates required files', function () {
        $user = createApiUser();

        $response = $this->actingAs($user)->postJson('/api/v1/media', []);

        $response->assertStatus(422);
    });
});

/* =================================================================
 * PUT /api/v1/media/{id} (update)
 * ================================================================= */

describe('PUT /api/v1/media/{id} (update)', function () {

    it('updates media metadata as owner', function () {
        $user = createApiUser();
        $media = createApiMedia(['tags' => ['old'], 'uploaded_by' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/v1/media/{$media->id}", [
            'tags' => ['new', 'updated'],
        ]);

        $response->assertStatus(200);

        $fresh = Media::find($media->id);

        expect($fresh->tags)->toBe(['new', 'updated']);
    });

    it('updates is_public field via snake_case input', function () {
        $user = createApiUser();
        $media = createApiMedia(['is_public' => false, 'uploaded_by' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/v1/media/{$media->id}", [
            'is_public' => true,
        ]);

        $response->assertStatus(200);

        $fresh = Media::find($media->id);

        expect($fresh->is_public)->toBeTrue();
    });

    it('denies non-owner from updating', function () {
        $owner = createApiUser();
        $other = createApiUser();
        $media = createApiMedia(['tags' => ['old'], 'uploaded_by' => $owner->id]);

        $response = $this->actingAs($other)->putJson("/api/v1/media/{$media->id}", [
            'tags' => ['hacked'],
        ]);

        $response->assertStatus(403);
    });
});

/* =================================================================
 * DELETE /api/v1/media/{id} (destroy)
 * ================================================================= */

describe('DELETE /api/v1/media/{id} (destroy)', function () {

    it('deletes media as owner', function () {
        Storage::fake('public');

        $user = createApiUser();
        $media = createApiMedia(['uploaded_by' => $user->id]);
        $media_id = $media->id;

        $response = $this->actingAs($user)->deleteJson("/api/v1/media/{$media_id}");

        $response->assertStatus(200);

        expect(Media::query()->find($media_id))->toBeNull()
            ->and(Media::withTrashed()->find($media_id)?->trashed())->toBeTrue();
    });

    it('denies non-owner from deleting', function () {
        $owner = createApiUser();
        $other = createApiUser();
        $media = createApiMedia(['uploaded_by' => $owner->id]);

        $response = $this->actingAs($other)->deleteJson("/api/v1/media/{$media->id}");

        $response->assertStatus(403);
    });
});

/* =================================================================
 * POST /api/v1/media/{id}/attach
 * ================================================================= */

describe('POST /api/v1/media/{id}/attach', function () {

    it('attaches media to a model as owner', function () {
        $user = createApiUser();
        $media = createApiMedia(['uploaded_by' => $user->id]);

        $response = $this->actingAs($user)->postJson("/api/v1/media/{$media->id}/attach", [
            'associableType' => TestMediaUser::class,
            'associableId' => (string) $user->id,
            'collection' => 'avatar',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas(MediaTables::Associations, [
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
        ]);
    });

    it('fails closed when no associable model types are allowlisted', function () {
        $user = createApiUser();
        $media = createApiMedia(['uploaded_by' => $user->id]);
        config()->set('media.allowed_associable_types', []);

        $this->actingAs($user)->postJson("/api/v1/media/{$media->id}/attach", [
            'associableType' => TestMediaUser::class,
            'associableId' => (string) $user->id,
            'collection' => 'avatar',
        ])->assertForbidden();

        $this->assertDatabaseMissing(MediaTables::Associations, [
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
        ]);
    });

    it('denies non-owner from attaching', function () {
        $owner = createApiUser();
        $other = createApiUser();
        $media = createApiMedia(['uploaded_by' => $owner->id]);

        $response = $this->actingAs($other)->postJson("/api/v1/media/{$media->id}/attach", [
            'associableType' => TestMediaUser::class,
            'associableId' => (string) $other->id,
            'collection' => 'avatar',
        ]);

        $response->assertStatus(403);
    });

    it('denies attaching media to another user when the actor cannot update that target user', function () {
        $owner = createApiUser();
        $target = createApiUser();
        $media = createApiMedia(['uploaded_by' => $owner->id]);

        $response = $this->actingAs($owner)->postJson("/api/v1/media/{$media->id}/attach", [
            'associableType' => TestMediaUser::class,
            'associableId' => (string) $target->id,
            'collection' => 'avatar',
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing(MediaTables::Associations, [
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $target->id,
            'collection' => 'avatar',
        ]);
    });

    it('rejects attach requests for allowlisted models that do not implement the media contract', function () {
        $owner = createApiUser();
        $media = createApiMedia(['uploaded_by' => $owner->id]);
        $unsupportedAssociable = createUnsupportedApiAssociable();
        $unsupportedAssociableClass = $unsupportedAssociable::class;

        config()->set('media.allowed_associable_types', [$unsupportedAssociableClass]);

        $response = $this->actingAs($owner)->postJson("/api/v1/media/{$media->id}/attach", [
            'associableType' => $unsupportedAssociableClass,
            'associableId' => (string) $unsupportedAssociable->getKey(),
            'collection' => 'default',
        ]);

        $response->assertStatus(422)
            ->assertInvalid(['associableType'])
            ->assertJsonPath(
                'errors.associableType.0',
                trans('media::media/messages.error.associable_type_must_support_media'),
            );
    });
});

/* =================================================================
 * POST /api/v1/media/{id}/detach
 * ================================================================= */

describe('POST /api/v1/media/{id}/detach', function () {

    it('detaches media from a model as owner', function () {
        $user = createApiUser();
        $media = createApiMedia(['uploaded_by' => $user->id]);

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/media/{$media->id}/detach", [
            'associableType' => TestMediaUser::class,
            'associableId' => (string) $user->id,
            'collection' => 'avatar',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing(MediaTables::Associations, [
            'media_id' => $media->id,
            'associable_id' => $user->id,
        ]);
    });

    it('denies detaching media from another user when the actor cannot update that target user', function () {
        $owner = createApiUser();
        $target = createApiUser();
        $media = createApiMedia(['uploaded_by' => $owner->id]);

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $target->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        $response = $this->actingAs($owner)->postJson("/api/v1/media/{$media->id}/detach", [
            'associableType' => TestMediaUser::class,
            'associableId' => (string) $target->id,
            'collection' => 'avatar',
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas(MediaTables::Associations, [
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $target->id,
            'collection' => 'avatar',
        ]);
    });

    it('rejects detach requests for allowlisted models that do not implement the media contract', function () {
        $owner = createApiUser();
        $media = createApiMedia(['uploaded_by' => $owner->id]);
        $unsupportedAssociable = createUnsupportedApiAssociable();
        $unsupportedAssociableClass = $unsupportedAssociable::class;

        config()->set('media.allowed_associable_types', [$unsupportedAssociableClass]);

        $response = $this->actingAs($owner)->postJson("/api/v1/media/{$media->id}/detach", [
            'associableType' => $unsupportedAssociableClass,
            'associableId' => (string) $unsupportedAssociable->getKey(),
            'collection' => 'default',
        ]);

        $response->assertStatus(422)
            ->assertInvalid(['associableType'])
            ->assertJsonPath(
                'errors.associableType.0',
                trans('media::media/messages.error.associable_type_must_support_media'),
            );
    });
});

/* =================================================================
 * POST /api/v1/media/reorder
 * ================================================================= */

describe('POST /api/v1/media/reorder', function () {

    it('denies reordering media on another user when the actor cannot update that target user', function () {
        $owner = createApiUser();
        $target = createApiUser();
        $firstMedia = createApiMedia(['uploaded_by' => $owner->id, 'hash' => md5('first-hash').'.jpg', 'digest' => md5('first-digest')]);
        $secondMedia = createApiMedia(['uploaded_by' => $owner->id, 'hash' => md5('second-hash').'.jpg', 'digest' => md5('second-digest')]);

        MediaAssociation::create([
            'media_id' => $firstMedia->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $target->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        MediaAssociation::create([
            'media_id' => $secondMedia->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $target->id,
            'collection' => 'avatar',
            'order' => 1,
        ]);

        $response = $this->actingAs($owner)->postJson('/api/v1/media/reorder', [
            'mediaIds' => [$secondMedia->id, $firstMedia->id],
            'associableType' => TestMediaUser::class,
            'associableId' => (string) $target->id,
            'collection' => 'avatar',
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas(MediaTables::Associations, [
            'media_id' => $firstMedia->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $target->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        $this->assertDatabaseHas(MediaTables::Associations, [
            'media_id' => $secondMedia->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $target->id,
            'collection' => 'avatar',
            'order' => 1,
        ]);
    });

    it('rejects reorder requests for allowlisted models that do not implement the media contract', function () {
        $owner = createApiUser();
        $media = createApiMedia([
            'uploaded_by' => $owner->id,
            'hash' => md5('unsupported-reorder-hash').'.jpg',
            'digest' => md5('unsupported-reorder-digest'),
        ]);
        $unsupportedAssociable = createUnsupportedApiAssociable();
        $unsupportedAssociableClass = $unsupportedAssociable::class;

        config()->set('media.allowed_associable_types', [$unsupportedAssociableClass]);

        $response = $this->actingAs($owner)->postJson('/api/v1/media/reorder', [
            'mediaIds' => [$media->id],
            'associableType' => $unsupportedAssociableClass,
            'associableId' => (string) $unsupportedAssociable->getKey(),
            'collection' => 'default',
        ]);

        $response->assertStatus(422)
            ->assertInvalid(['associableType'])
            ->assertJsonPath(
                'errors.associableType.0',
                trans('media::media/messages.error.associable_type_must_support_media'),
            );
    });
});

/* =================================================================
 * GET /api/v1/media/{id}/variations
 * ================================================================= */

describe('GET /api/v1/media/{id}/variations', function () {

    it('lists variations for media', function () {
        $user = createApiUser();
        $media = createApiMedia(['is_public' => true]);

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 150,
            'height' => 150,
            'size' => 512,
            'format' => 'webp',
            'quality' => 80,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/media/{$media->id}/variations");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
            ]);
    });
});

/* =================================================================
 * POST /api/v1/media/{id}/regenerate
 * ================================================================= */

describe('POST /api/v1/media/{id}/regenerate', function () {

    it('returns the stable API error contract for media that does not support variations', function () {
        $user = createApiUser();
        $media = createApiMedia([
            'uploaded_by' => $user->id,
            'type' => MediaType::DOCUMENT,
            'filename' => 'report.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/media/{$media->id}/regenerate")
            ->assertStatus(422)
            ->assertJson([
                'message' => (string) trans('media::media/messages.error.variations_unsupported'),
                'code' => 'variations_unsupported',
            ]);
    });
});
