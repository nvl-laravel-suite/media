<?php

declare(strict_types=1);

namespace Nvl\Media\Http\Controllers\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LogicException;
use Nvl\Media\Data\Display\MediaImageVariationPayload;
use Nvl\Media\Data\MediaFilter;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaLibraryItemDataFactory;
use Nvl\Media\Services\MediaQueryService;
use Nvl\Media\Services\MediaResourceDataFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Read-only library, usage, and download endpoints.
 */
final class MediaLibraryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MediaQueryService $queryService,
        private readonly MediaLibraryItemDataFactory $libraryItems,
        private readonly MediaResourceDataFactory $resources,
        private readonly MediaDiskGateway $disks,
        private readonly MediaFileExistence $existence,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Media::class);

        $filters = MediaFilter::validateAndCreate($request->query());
        $user = $request->user();
        $includeVariations = $request->boolean('include_variations', true);
        $paginator = $this->queryService->index(
            filters: $filters,
            user: $user instanceof Authenticatable ? $user : null,
            includeVariations: $includeVariations,
        );

        if (! $paginator instanceof LengthAwarePaginator) {
            throw new LogicException('Expected media index to return a length-aware paginator.');
        }

        $items = [];

        foreach ($paginator->items() as $item) {
            if (! $item instanceof Media) {
                throw new LogicException('Media pagination returned an invalid item.');
            }

            $payload = $this->libraryItems->fromModel($item)->toArray();

            if ($includeVariations) {
                $payload['imageVariations'] = $item->imageVariations
                    ->map(
                        static fn (MediaImageVariation $variation): array => MediaImageVariationPayload::fromModel($variation)->toArray(),
                    )
                    ->values()
                    ->all();
            }

            $items[] = $payload;
        }

        return response()->json([
            'data' => [
                'media' => [
                    'items' => $items,
                    'links' => [
                        'first' => $paginator->url(1),
                        'last' => $paginator->url(max($paginator->lastPage(), 1)),
                        'prev' => $paginator->previousPageUrl(),
                        'next' => $paginator->nextPageUrl(),
                    ],
                    'meta' => [
                        'currentPage' => $paginator->currentPage(),
                        'lastPage' => $paginator->lastPage(),
                        'perPage' => $paginator->perPage(),
                        'total' => $paginator->total(),
                    ],
                ],
                'filterOptions' => $this->queryService->filterOptions(
                    $user instanceof Authenticatable ? $user : null,
                ),
                'dialogConfig' => $this->defaultDialogConfig(),
            ],
        ], 200);
    }

    public function show(Request $request, Media $media): JsonResponse
    {
        $this->authorize('view', $media);
        $relations = ['translations', 'associations'];

        if ($request->boolean('include_variations', true)) {
            $relations[] = 'imageVariations';
        }

        $media->loadMissing($relations);

        return response()->json([
            'data' => $this->resources->fromModel($request, $media),
        ]);
    }

    public function usages(Media $media): JsonResponse
    {
        $this->authorize('view', $media);

        return response()->json([
            'data' => $this->queryService->usages($media->id)->toArray(),
        ], 200);
    }

    public function download(Media $media): StreamedResponse|JsonResponse
    {
        try {
            $this->authorize('download', $media);
            $path = $media->buildPath();

            if (! $this->existence->exists($media->disk, $path)) {
                return response()->json([
                    'message' => (string) trans('media::media/messages.error.file_not_found_on_disk'),
                ], 404);
            }

            $stream = $this->disks->readStream($media->disk, $path);

            if (! is_resource($stream)) {
                return response()->json([
                    'message' => (string) trans('media::media/messages.error.file_not_found_on_disk'),
                ], 404);
            }

            return response()->streamDownload(static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            }, $media->filename, [
                'Content-Type' => $media->mime_type,
            ]);
        } catch (AuthorizationException $exception) {
            return response()->json([
                'message' => $exception->getMessage()
                    ?: (string) trans('media::media/messages.error.unauthorized'),
            ], 403);
        } catch (Throwable $exception) {
            Log::error('Media download failed.', [
                'media_id' => $media->id,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => (string) trans('media::media/messages.error.unexpected'),
            ], 500);
        }
    }

    /**
     * @return array{
     *   allowedTypes: list<string>,
     *   allowedCollections: list<string>,
     *   includePrivate: bool,
     *   preload: bool,
     *   upload: array{enabled: bool, collection: string, isPublic: bool}
     * }
     */
    private function defaultDialogConfig(): array
    {
        return [
            'allowedTypes' => array_map(
                static fn (MediaType $type): string => $type->value,
                MediaType::cases(),
            ),
            'allowedCollections' => ['default'],
            'includePrivate' => true,
            'preload' => true,
            'upload' => [
                'enabled' => true,
                'collection' => 'default',
                'isPublic' => false,
            ],
        ];
    }
}
