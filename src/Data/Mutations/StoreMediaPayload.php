<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Nvl\Media\Http\Rules\AllowedMimeTypes;
use Nvl\Media\Http\Rules\MaxFileSize;
use Nvl\Media\Support\MediaConfiguration;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** StoreMediaPayload: input DTO for media file upload requests. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
class StoreMediaPayload extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string|null */
        public readonly ?string $collection = 'default',

        /** @var string|null */
        public readonly ?string $disk = null,

        /** @var bool */
        public readonly bool $isPublic = false,

        /** @var array<int, string>|null */
        #[LiteralTypeScriptType('string[] | null')]
        public readonly ?array $tags = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'files' => [
                'required',
                'array',
                'min:1',
                'max:'.MediaConfiguration::integer('media.max_files_per_upload', 10, 1),
            ],
            'files.*' => ['required', 'file', new MaxFileSize, new AllowedMimeTypes],
            'collection' => ['nullable', 'string', 'max:50'],
            'disk' => ['nullable', 'string', 'max:25'],
            'isPublic' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return self::translatedMessages('media::media');
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('media::media');
    }
}
