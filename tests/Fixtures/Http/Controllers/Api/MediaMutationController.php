<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Http\Controllers\Api;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAssociableResolver;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Services\MediaReader;
use Nvl\Media\Tests\Fixtures\Data\Mutations\BulkMediaPayload;
use Nvl\Media\Tests\Fixtures\Data\Mutations\RenameMediaData;
use Nvl\Media\Tests\Fixtures\Data\Mutations\ReorderMediaPayload;
use Nvl\Media\Tests\Fixtures\Data\Mutations\UpdateMediaPayload;
use Nvl\Media\Tests\Fixtures\MediaManagementDataFactory;
use Spatie\LaravelData\Optional;

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
        UpdateMediaPayload $data,
        Media $media,
    ): JsonResponse {
        $this->authorize('update', $media);

        $attributes = array_filter(get_object_vars($data), static fn ($value): bool => ! $value instanceof Optional);
        unset($attributes['translationMode']);
        $attributes['translation_mode'] = $data->translationMode;
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

    public function rename(Request $request, RenameMediaData $data, Media $media): JsonResponse
    {
        $this->authorize('update', $media);

        $renamed = $this->renameMedia->rename(auth()->user(),
            $media,
            $data->filename,
        );

        return response()->json([
            'data' => $this->resources->fromModel($renamed),
            'message' => (string) trans('nvl-media::media/messages.success.renamed'),
        ]);
    }

    public function reorder(ReorderMediaPayload $data): JsonResponse
    {
        $mediaItems = $this->queries->findMany(auth()->user(), $data->mediaIds);

        foreach ($mediaItems as $media) {
            $this->authorize('update', $media);
        }

        $associable = $this->associables->resolveForMutation(
            $data->associableType,
            $data->associableId,
        );
        $associable->nvlMedia()->order($data->mediaIds, $data->collection);

        return response()->json([
            'data' => ['reordered' => true],
            'message' => (string) trans('nvl-media::media/messages.success.reordered'),
        ]);
    }

    public function bulk(BulkMediaPayload $data): JsonResponse
    {
        if ($data->action === 'move' && $data->folder !== null) {
            try {
                MediaPathResolver::assertSafe($data->folder);
            } catch (MediaUploadException) {
                throw ValidationException::withMessages([
                    'folder' => ['Folder path contains invalid characters.'],
                ]);
            }
        }

        $ability = $data->action === 'delete' ? 'delete' : 'update';
        $mediaItems = $this->queries->findMany(auth()->user(), $data->ids);

        foreach ($mediaItems as $media) {
            $this->authorize($ability, $media);
        }

        $count = match ($data->action) {
            'delete' => $this->bulkDelete->bulkDelete(auth()->user(), $data->ids),
            'tag' => $this->bulkTag->bulkTag(auth()->user(), $data->ids, $data->tags ?? []),
            'move' => $this->bulkMove->move(auth()->user(), $data->ids, $data->folder ?? ''),
            default => 0,
        };

        return response()->json([
            'data' => ['affected' => $count],
            'message' => (string) trans(
                'nvl-media::media/messages.success.bulk_completed',
                ['action' => $data->action],
            ),
        ]);
    }
}
