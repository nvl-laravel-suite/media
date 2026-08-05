<?php

declare(strict_types=1);

namespace Nvl\Media\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Nvl\Media\Support\MediaConfiguration;

/** MaxFileSize: validates that an uploaded file does not exceed the configured size limit. */
class MaxFileSize implements ValidationRule
{
    private int $maxBytes;

    /**
     * @return void
     */
    public function __construct(?int $maxBytes = null)
    {
        $this->maxBytes = $maxBytes
            ?? MediaConfiguration::integer('media.max_file_size', 10 * 1024 * 1024, 1);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail((string) trans('media::media/validation.rules.file_required'));

            return;
        }

        if ($value->getSize() > $this->maxBytes) {
            $readable = number_format($this->maxBytes / (1024 * 1024), 1);
            $fail((string) trans('media::media/validation.rules.max_file_size', [
                'attribute' => $attribute,
                'size_mb' => $readable,
            ]));
        }
    }
}
