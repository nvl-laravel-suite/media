<?php

declare(strict_types=1);

namespace Nvl\Media\Http\Controllers\Api;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Data\Mutations\AttachMediaPayload;
use Nvl\Media\Data\Mutations\DetachMediaPayload;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAssociableResolver;

/**
 * Handles polymorphic Media associations.
 */
final class MediaAssociationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MediaAssociableResolver $associables,
        private readonly AttachMediaContract $attachMedia,
        private readonly DetachMediaContract $detachMedia,
    ) {}

    public function attach(AttachMediaPayload $data, Media $media): JsonResponse
    {
        $this->authorize('attach', $media);

        $associable = $this->associables->resolveForMutation(
            $data->associableType,
            $data->associableId,
        );
        $association = $this->attachMedia->execute(
            media: $media,
            model: $associable,
            collection: $data->collection,
            locale: $data->locale,
            order: $data->order,
        );

        return response()->json([
            'data' => ['association' => $association->toArray()],
            'message' => (string) trans('media::media/messages.success.attached'),
        ]);
    }

    public function detach(DetachMediaPayload $data, Media $media): JsonResponse
    {
        $this->authorize('detach', $media);

        $associable = $this->associables->resolveForMutation(
            $data->associableType,
            $data->associableId,
        );
        $deleted = $this->detachMedia->execute(
            media: $media->id,
            model: $associable,
            collection: $data->collection,
        );

        return response()->json([
            'data' => ['detachedCount' => $deleted],
            'message' => (string) trans('media::media/messages.success.detached'),
        ]);
    }
}
