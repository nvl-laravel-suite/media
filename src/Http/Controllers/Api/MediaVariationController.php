<?php

declare(strict_types=1);

namespace Nvl\Media\Http\Controllers\Api;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nvl\Media\Actions\GenerateImageVariationAction;
use Nvl\Media\Data\Display\MediaImageVariationPayload;
use Nvl\Media\Data\Mutations\RequestMediaVariationsData;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaConfiguredVariationService;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Handles named-variation inspection and regeneration.
 */
final class MediaVariationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MediaConfiguredVariationService $configuredVariations,
        private readonly GenerateImageVariationAction $generateVariation,
    ) {}

    public function variations(Media $media): JsonResponse
    {
        $this->authorize('view', $media);
        $media->loadMissing('imageVariations');

        return response()->json([
            'data' => $media->imageVariations
                ->map(
                    static fn (MediaImageVariation $variation): array => MediaImageVariationPayload::fromModel($variation)->toArray(),
                )
                ->values()
                ->all(),
        ]);
    }

    public function regenerate(RequestMediaVariationsData $data, Media $media): JsonResponse
    {
        $this->authorize('regenerate', $media);
        $media->loadMissing('imageVariations');

        if (! $media->type->supportsConversions()) {
            return response()->json([
                'message' => (string) trans('media::media/messages.error.variations_unsupported'),
                'code' => 'variations_unsupported',
            ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $requested = $data->variations;
        $labels = is_array($requested)
            ? array_values(array_filter($requested, 'is_string'))
            : [];
        $results = [];

        foreach ($this->configuredVariations->presetDefinitions(
            names: $labels !== [] ? $labels : null,
            enabledOnly: $labels === [],
        ) as $definition) {
            $variation = $this->generateVariation->execute(
                media: $media,
                definition: $definition,
            );

            if ($variation !== null) {
                $results[] = [
                    'label' => $definition->name,
                    'width' => $variation->width,
                    'height' => $variation->height,
                ];
            }
        }

        return response()->json([
            'data' => ['regenerated' => $results],
            'message' => (string) trans(
                'media::media/messages.success.variations_regenerated',
            ),
        ]);
    }
}
