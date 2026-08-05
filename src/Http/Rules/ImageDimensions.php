<?php

declare(strict_types=1);

namespace Nvl\Media\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/** ImageDimensions: validates that an uploaded image does not exceed maximum width and height. */
class ImageDimensions implements ValidationRule
{
    private int $maxWidth;

    private int $maxHeight;

    /**
     * @return void
     */
    public function __construct(?int $maxWidth = null, ?int $maxHeight = null)
    {
        $constraints = config('media.image_constraints', []);
        $configuredWidth = is_array($constraints) ? ($constraints['max_width'] ?? null) : null;
        $configuredHeight = is_array($constraints) ? ($constraints['max_height'] ?? null) : null;
        $this->maxWidth = $maxWidth
            ?? (is_int($configuredWidth) && $configuredWidth > 0 ? $configuredWidth : 4096);
        $this->maxHeight = $maxHeight
            ?? (is_int($configuredHeight) && $configuredHeight > 0 ? $configuredHeight : 4096);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        if (! str_starts_with($value->getClientMimeType(), 'image/')) {
            return;
        }

        $dimensions = @getimagesize($value->getRealPath());

        if ($dimensions === false) {
            return;
        }

        [$width, $height] = $dimensions;

        if ($width > $this->maxWidth) {
            $fail((string) trans('media::media/validation.rules.image_width_exceeds', [
                'attribute' => $attribute,
                'width' => $width,
                'max_width' => $this->maxWidth,
            ]));
        }

        if ($height > $this->maxHeight) {
            $fail((string) trans('media::media/validation.rules.image_height_exceeds', [
                'attribute' => $attribute,
                'height' => $height,
                'max_height' => $this->maxHeight,
            ]));
        }
    }
}
