<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Http\Request;
use Nvl\Media\Http\Resources\MediaResource;
use Nvl\Media\Models\Media;

/**
 * Resolves the canonical privileged Media API resource payload.
 */
final class MediaResourceDataFactory
{
    /**
     * @return array<string, mixed>
     */
    public function fromModel(Request $request, Media $media): array
    {
        $resolved = (new MediaResource($media))->resolve($request);
        $normalized = [];

        foreach ($resolved as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
