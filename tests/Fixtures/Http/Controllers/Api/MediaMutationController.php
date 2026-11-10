<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Http\Controllers\Api;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAssociableResolver;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Services\MediaReader;
use Nvl\Media\Tests\Fixtures\MediaManagementDataFactory;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;

/**
 * Handles metadata, ordering, and lifecycle mutations.
 */
final class MediaMutationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MediaReader $queries,
        private readonly MediaLibrary $updateMetadata,
        private readonly MediaLibrary $deleteMedia,
        private readonly MediaLibrary $renameMedia,
        private readonly MediaLibrary $bulkDelete,
        private readonly MediaLibrary $bulkTag,
        private readonly MediaLibrary $bulkMove,
        private readonly MediaAssociableResolver $associables,
        private readonly MediaManagementDataFactory $resources,
    ) {}

    public function update(
        Request $request,
        Media $media,
    ): JsonResponse {
        $this->authorize('update', $media);

        if ($request->exists('is_public')) {
            $request->merge(['isPublic' => $request->input('is_public')]);
        }
        $attributes = $request->validate([
            'isPublic' => ['sometimes', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'metadata' => ['nullable', 'array'],
            'translations' => ['nullable', 'array', new SupportedLocaleMapRule],
            'translations.*' => ['array:title,alt,caption,description'],
            'translations.*.title' => ['nullable', 'string', 'max:255'],
            'translations.*.alt' => ['nullable', 'string', 'max:255'],
            'translations.*.caption' => ['nullable', 'string'],
            'translations.*.description' => ['nullable', 'string'],
            'translation_mode' => ['sometimes', Rule::enum(TranslationSyncMode::class)],
        ]);
        if (array_key_exists('isPublic', $attributes)) {
            $attributes['isPublic'] = (bool) $attributes['isPublic'];
        }
        $attributes['translation_mode'] = TranslationSyncMode::from(
            $attributes['translation_mode'] ?? TranslationSyncMode::Patch->value,
        );
        $updated = $this->updateMetadata->updateMetadata($request->user(), $media, $attributes);

        return response()->json([
            'data' => $this->resources->fromModel($updated),
        ]);
    }

    public function destroy(Media $media): JsonResponse
    {
        $this->authorize('delete', $media);

        return response()->json([
            'data' => ['deleted' => $this->deleteMedia->delete(auth()->user(), $media)],
            'message' => (string) trans('nvl-media::media/messages.success.deleted'),
        ]);
    }

    public function rename(Request $request, Media $media): JsonResponse
    {
        $this->authorize('update', $media);
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/'],
        ]);

        $renamed = $this->renameMedia->rename(auth()->user(),
            $media,
            $data['filename'],
        );

        return response()->json([
            'data' => $this->resources->fromModel($renamed),
            'message' => (string) trans('nvl-media::media/messages.success.renamed'),
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mediaIds' => ['required', 'array', 'min:1'],
            'mediaIds.*' => ['uuid', 'exists:'.MediaTables::get(MediaTables::Media).',id'],
            'associableType' => ['required', 'string', 'max:255'],
            'associableId' => ['required', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:50'],
        ]);
        $mediaItems = $this->queries->findMany(auth()->user(), $data['mediaIds']);

        foreach ($mediaItems as $media) {
            $this->authorize('update', $media);
        }

        $associable = $this->associables->resolveForMutation(
            $data['associableType'],
            $data['associableId'],
        );
        $associable->nvlMedia()->order($data['mediaIds'], $data['collection'] ?? 'default');

        return response()->json([
            'data' => ['reordered' => true],
            'message' => (string) trans('nvl-media::media/messages.success.reordered'),
        ]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:delete,tag,move'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
            'tags' => ['required_if:action,tag', 'nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'folder' => ['required_if:action,move', 'nullable', 'string', 'max:255'],
        ]);
        if ($data['action'] === 'move' && isset($data['folder'])) {
            try {
                MediaPathResolver::assertSafe($data['folder']);
            } catch (MediaUploadException) {
                throw ValidationException::withMessages([
                    'folder' => ['Folder path contains invalid characters.'],
                ]);
            }
        }

        $ability = $data['action'] === 'delete' ? 'delete' : 'update';
        $mediaItems = $this->queries->findMany(auth()->user(), $data['ids']);

        foreach ($mediaItems as $media) {
            $this->authorize($ability, $media);
        }

        $count = match ($data['action']) {
            'delete' => $this->bulkDelete->bulkDelete(auth()->user(), $data['ids']),
            'tag' => $this->bulkTag->bulkTag(auth()->user(), $data['ids'], $data['tags'] ?? []),
            'move' => $this->bulkMove->move(auth()->user(), $data['ids'], $data['folder'] ?? ''),
            default => 0,
        };

        return response()->json([
            'data' => ['affected' => $count],
            'message' => (string) trans(
                'nvl-media::media/messages.success.bulk_completed',
                ['action' => $data['action']],
            ),
        ]);
    }
}
