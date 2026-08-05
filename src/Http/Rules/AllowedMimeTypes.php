<?php

declare(strict_types=1);

namespace Nvl\Media\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Nvl\Media\Support\MediaConfiguration;

/** AllowedMimeTypes: validates that an uploaded file has an accepted MIME type from config. */
class AllowedMimeTypes implements ValidationRule
{
    /** @var string[] */
    private array $allowedTypes;

    /**
     * @param  array<int, string>  $allowedTypes
     * @return void
     */
    public function __construct(array $allowedTypes = [])
    {
        $this->allowedTypes = ! empty($allowedTypes)
            ? $allowedTypes
            : MediaConfiguration::nestedStringList('media.file_types');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail((string) trans('media::media/validation.rules.file_required'));

            return;
        }

        $mime = $value->getMimeType();

        if (! in_array($mime, $this->allowedTypes, true)) {
            $fail((string) trans('media::media/validation.rules.unsupported_mime_type', [
                'attribute' => $attribute,
                'mime' => $mime,
            ]));
        }
    }
}
