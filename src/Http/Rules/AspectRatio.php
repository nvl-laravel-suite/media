<?php

declare(strict_types=1);

namespace Nvl\Media\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/** AspectRatio: validates that an uploaded image matches a required aspect ratio within tolerance. */
class AspectRatio implements ValidationRule
{
    /**
     * @return void
     */
    public function __construct(
        private readonly float $ratio,
        private readonly float $tolerance = 0.05,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        if (! str_starts_with($value->getClientMimeType(), 'image/')) {
            return;
        }

        $dimensions = @getimagesize($value->getRealPath());

        if ($dimensions === false || $dimensions[1] === 0) {
            return;
        }

        [$width, $height] = $dimensions;
        $actual_ratio = $width / $height;
        $difference = abs($actual_ratio - $this->ratio);

        if ($difference > $this->tolerance) {
            $fail((string) trans('media::media/validation.rules.aspect_ratio_mismatch', [
                'attribute' => $attribute,
                'actual_ratio' => number_format($actual_ratio, 4, '.', ''),
                'required_ratio' => number_format($this->ratio, 4, '.', ''),
                'tolerance' => number_format($this->tolerance, 4, '.', ''),
            ]));
        }
    }
}
