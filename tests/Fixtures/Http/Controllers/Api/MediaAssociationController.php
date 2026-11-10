<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Http\Controllers\Api;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAssociableResolver;
use Nvl\Media\Services\MediaAttachments;

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

    public function attach(Request $request, Media $media): JsonResponse
    {
        $this->authorize('attach', $media);
        $data = $request->validate([
            'associableType' => ['required', 'string', 'max:255'],
            'associableId' => ['required', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:50'],
            'locale' => ['nullable', 'string', 'max:5'],
            'order' => ['nullable', 'integer', 'min:0'],
        ]);

        $associable = $this->associables->resolveForMutation(
            $data['associableType'],
            $data['associableId'],
        );
        $association = $this->attachMedia->attach(
            actor: auth()->user(),
            media: $media,
            model: $associable,
            collection: $data['collection'] ?? 'default',
            locale: $data['locale'] ?? null,
            order: isset($data['order']) ? (int) $data['order'] : null,
        );

        return response()->json([
            'data' => ['association' => $association->toArray()],
            'message' => (string) trans('nvl-media::media/messages.success.attached'),
        ]);
    }

    public function detach(Request $request, Media $media): JsonResponse
    {
        $this->authorize('detach', $media);
        $data = $request->validate([
            'associableType' => ['required', 'string', 'max:255'],
            'associableId' => ['required', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:50'],
        ]);

        $associable = $this->associables->resolveForMutation(
            $data['associableType'],
            $data['associableId'],
        );
        $deleted = $this->detachMedia->detach(
            actor: auth()->user(),
            media: $media->id,
            model: $associable,
            collection: $data['collection'] ?? null,
        );

        return response()->json([
            'data' => ['detachedCount' => $deleted],
            'message' => (string) trans('nvl-media::media/messages.success.detached'),
        ]);
    }
}
