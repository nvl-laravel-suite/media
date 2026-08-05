<?php

declare(strict_types=1);

namespace Nvl\Media\Http\Controllers\Api;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nvl\Media\Actions\BulkDeleteMediaAction;
use Nvl\Media\Actions\BulkMoveMediaAction;
use Nvl\Media\Actions\BulkTagMediaAction;
use Nvl\Media\Actions\RenameMediaAction;
use Nvl\Media\Actions\UpdateMediaMetadataAction;
use Nvl\Media\Contracts\DeleteMediaContract;
use Nvl\Media\Data\Mutations\BulkMediaPayload;
use Nvl\Media\Data\Mutations\ReorderMediaPayload;
use Nvl\Media\Data\Mutations\UpdateMediaPayload;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAssociableResolver;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Services\MediaQueryService;
use Nvl\Media\Services\MediaResourceDataFactory;

/**
 * Handles metadata, ordering, and lifecycle mutations.
 */
final class MediaMutationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MediaQueryService $queries,
        private readonly UpdateMediaMetadataAction $updateMetadata,
        private readonly DeleteMediaContract $deleteMedia,
        private readonly RenameMediaAction $renameMedia,
        private readonly BulkDeleteMediaAction $bulkDelete,
        private readonly BulkTagMediaAction $bulkTag,
        private readonly BulkMoveMediaAction $bulkMove,
        private readonly MediaAssociableResolver $associables,
        private readonly MediaResourceDataFactory $resources,
    ) {}

    public function update(
        Request $request,
        UpdateMediaPayload $data,
        Media $media,
    ): JsonResponse {
        $this->authorize('update', $media);

        $updated = $this->updateMetadata->execute($media, $data);

        return response()->json([
            'data' => $this->resources->fromModel($request, $updated),
        ]);
    }

    public function destroy(Media $media): JsonResponse
    {
        $this->authorize('delete', $media);

        return response()->json([
            'data' => ['deleted' => $this->deleteMedia->execute($media)],
            'message' => (string) trans('media::media/messages.success.deleted'),
        ]);
    }

    public function rename(Request $request, Media $media): JsonResponse
    {
        $this->authorize('update', $media);

        $request->validate([
            'filename' => ['required', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/'],
        ]);

        $renamed = $this->renameMedia->execute(
            $media,
            $request->string('filename')->toString(),
        );

        return response()->json([
            'data' => $this->resources->fromModel($request, $renamed),
            'message' => (string) trans('media::media/messages.success.renamed'),
        ]);
    }

    public function reorder(ReorderMediaPayload $data): JsonResponse
    {
        $mediaItems = $this->queries->findMany($data->mediaIds);

        foreach ($mediaItems as $media) {
            $this->authorize('update', $media);
        }

        $associable = $this->associables->resolveForMutation(
            $data->associableType,
            $data->associableId,
        );
        $associable->updateMediaOrder($data->mediaIds, $data->collection);

        return response()->json([
            'data' => ['reordered' => true],
            'message' => (string) trans('media::media/messages.success.reordered'),
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
        $mediaItems = $this->queries->findMany($data->ids);

        foreach ($mediaItems as $media) {
            $this->authorize($ability, $media);
        }

        $count = match ($data->action) {
            'delete' => $this->bulkDelete->execute($data->ids),
            'tag' => $this->bulkTag->execute($data->ids, $data->tags ?? []),
            'move' => $this->bulkMove->execute($data->ids, $data->folder ?? ''),
            default => 0,
        };

        return response()->json([
            'data' => ['affected' => $count],
            'message' => (string) trans(
                'media::media/messages.success.bulk_completed',
                ['action' => $data->action],
            ),
        ]);
    }
}
