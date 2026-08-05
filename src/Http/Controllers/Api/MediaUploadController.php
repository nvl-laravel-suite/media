<?php

declare(strict_types=1);

namespace Nvl\Media\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nvl\Media\Actions\ReplaceMediaFileAction;
use Nvl\Media\Contracts\UploadMediaContract;
use Nvl\Media\Data\Mutations\StoreMediaPayload;
use Nvl\Media\Http\Rules\AllowedMimeTypes;
use Nvl\Media\Http\Rules\MaxFileSize;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaDiskGuard;
use Nvl\Media\Services\MediaResourceDataFactory;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaConfiguration;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Handles ordinary uploads and source-file replacement.
 */
final class MediaUploadController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly UploadMediaContract $uploadMedia,
        private readonly ReplaceMediaFileAction $replaceMediaFile,
        private readonly MediaDiskGuard $diskGuard,
        private readonly MediaResourceDataFactory $resources,
    ) {}

    public function store(Request $request, StoreMediaPayload $data): JsonResponse
    {
        $this->authorize('create', Media::class);

        $request->validate([
            'files' => [
                'required',
                'array',
                'min:1',
                'max:'.MediaConfiguration::integer('media.max_files_per_upload', 10, 1),
            ],
            'files.*' => ['required', 'file', new MaxFileSize, new AllowedMimeTypes],
        ]);

        $slot = new MediaSlot($data->collection ?? 'default');
        $slot->useDisk($this->diskGuard->resolveAllowed(
            is_string($data->disk) ? $data->disk : null,
        ));
        $slot->isPublic($data->isPublic);

        $owner = $request->user();

        if (! $owner instanceof Model) {
            throw ValidationException::withMessages([
                'user' => [(string) trans('media::media/messages.error.unauthorized')],
            ]);
        }

        /** @var list<UploadedFile> $files */
        $files = $request->file('files', []);
        $uploaded = [];

        foreach ($files as $file) {
            $media = $this->uploadMedia->execute(
                file: $file,
                disk: $slot->disk,
                model: $owner,
                slot: $slot,
                fileName: $file->getClientOriginalName(),
                isPublic: $data->isPublic,
                tags: array_values($data->tags ?? []),
            );

            $uploaded[] = $this->resources->fromModel($request, $media);
        }

        return response()->json([
            'data' => ['items' => $uploaded],
            'message' => (string) trans('media::media/messages.success.uploaded'),
        ], HttpResponse::HTTP_CREATED);
    }

    public function replace(Request $request, Media $media): JsonResponse
    {
        $this->authorize('update', $media);

        $request->validate([
            'file' => ['required', 'file', new MaxFileSize, new AllowedMimeTypes],
        ]);

        $replacement = $request->file('file');

        if (! $replacement instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => ['A valid replacement upload is required.'],
            ]);
        }

        $updated = $this->replaceMediaFile->execute($media, $replacement);

        return response()->json([
            'data' => $this->resources->fromModel($request, $updated),
            'message' => (string) trans('media::media/messages.success.replaced'),
        ]);
    }
}
