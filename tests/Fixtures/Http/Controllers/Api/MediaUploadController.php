<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaDiskGuard;
use Nvl\Media\Services\MediaUploader;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Fixtures\Data\Mutations\ReplaceMediaData;
use Nvl\Media\Tests\Fixtures\Data\Mutations\StoreMediaPayload;
use Nvl\Media\Tests\Fixtures\MediaManagementDataFactory;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Handles ordinary uploads and source-file replacement.
 */
final class MediaUploadController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MediaUploader $uploadMedia,
        private readonly MediaUploader $replaceMediaFile,
        private readonly MediaDiskGuard $diskGuard,
        private readonly MediaManagementDataFactory $resources,
    ) {}

    public function store(Request $request, StoreMediaPayload $data): JsonResponse
    {
        $this->authorize('create', Media::class);

        $slot = new MediaSlot($data->collection ?? 'default');
        $slot->useDisk($this->diskGuard->resolveAllowed(
            is_string($data->disk) ? $data->disk : null,
            $data->isPublic,
        ));
        $slot->isPublic($data->isPublic);

        $owner = $request->user();

        if (! $owner instanceof Model) {
            throw ValidationException::withMessages([
                'user' => [(string) trans('nvl-media::media/messages.error.unauthorized')],
            ]);
        }

        /** @var list<UploadedFile> $files */
        $files = $request->file('files', []);
        $uploaded = [];

        foreach ($files as $file) {
            $media = $this->uploadMedia->upload(
                actor: $request->user(),
                file: $file,
                disk: $slot->disk,
                model: $owner,
                slot: $slot,
                fileName: $file->getClientOriginalName(),
                isPublic: $data->isPublic,
                tags: array_values($data->tags ?? []),
            );

            $uploaded[] = $this->resources->fromModel($media);
        }

        return response()->json([
            'data' => ['items' => $uploaded],
            'message' => (string) trans('nvl-media::media/messages.success.uploaded'),
        ], HttpResponse::HTTP_CREATED);
    }

    public function replace(Request $request, ReplaceMediaData $data, Media $media): JsonResponse
    {
        $this->authorize('update', $media);

        $replacement = $data->file;

        if (! $replacement instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => ['A valid replacement upload is required.'],
            ]);
        }

        $updated = $this->replaceMediaFile->replace($request->user(), $media, $replacement);

        return response()->json([
            'data' => $this->resources->fromModel($updated),
            'message' => (string) trans('nvl-media::media/messages.success.replaced'),
        ]);
    }
}
