<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Http\Controllers\Api;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAssociableResolver;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Tests\Fixtures\Data\Mutations\AttachMediaPayload;
use Nvl\Media\Tests\Fixtures\Data\Mutations\DetachMediaPayload;

/**
 * Handles polymorphic Media associations.
 */
final class MediaAssociationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MediaAssociableResolver $associables,
        private readonly MediaAttachments $attachMedia,
        private readonly MediaAttachments $detachMedia,
    ) {}

    public function attach(AttachMediaPayload $data, Media $media): JsonResponse
    {
        $this->authorize('attach', $media);

        $associable = $this->associables->resolveForMutation(
            $data->associableType,
            $data->associableId,
        );
        $association = $this->attachMedia->attach(
            actor: auth()->user(),
            media: $media,
            model: $associable,
            collection: $data->collection,
            locale: $data->locale,
            order: $data->order,
        );

        return response()->json([
            'data' => ['association' => $association->toArray()],
            'message' => (string) trans('nvl-media::media/messages.success.attached'),
        ]);
    }

    public function detach(DetachMediaPayload $data, Media $media): JsonResponse
    {
        $this->authorize('detach', $media);

        $associable = $this->associables->resolveForMutation(
            $data->associableType,
            $data->associableId,
        );
        $deleted = $this->detachMedia->detach(
            actor: auth()->user(),
            media: $media->id,
            model: $associable,
            collection: $data->collection,
        );

        return response()->json([
            'data' => ['detachedCount' => $deleted],
            'message' => (string) trans('nvl-media::media/messages.success.detached'),
        ]);
    }
}
