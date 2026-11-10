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
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Tests\Fixtures\Http\Rules\AllowedMimeTypes;
use Nvl\Media\Tests\Fixtures\Http\Rules\MaxFileSize;
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

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Media::class);
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.MediaConfiguration::integer('nvl-media.max_files_per_upload', 10, 1)],
            'files.*' => ['required', 'file', new MaxFileSize, new AllowedMimeTypes],
            'collection' => ['nullable', 'string', 'max:50'],
            'disk' => ['nullable', 'string', 'max:25'],
            'isPublic' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);
        $isPublic = (bool) ($data['isPublic'] ?? false);

        $slot = new MediaSlot($data['collection'] ?? 'default');
        $slot->useDisk($this->diskGuard->resolveAllowed(
            $data['disk'] ?? null,
            $isPublic,
        ));
        $slot->isPublic($isPublic);

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
                isPublic: $isPublic,
                tags: array_values($data['tags'] ?? []),
            );

            $uploaded[] = $this->resources->fromModel($media);
        }

        return response()->json([
            'data' => ['items' => $uploaded],
            'message' => (string) trans('nvl-media::media/messages.success.uploaded'),
        ], HttpResponse::HTTP_CREATED);
    }

    public function replace(Request $request, Media $media): JsonResponse
    {
        $this->authorize('update', $media);
        $data = $request->validate([
            'file' => ['required', 'file', new MaxFileSize, new AllowedMimeTypes],
        ]);

        $replacement = $data['file'];

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
