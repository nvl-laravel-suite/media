<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Nvl\Media\Definitions\Tables\MediaTables;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** ReorderMediaPayload: input DTO for reordering media associations. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
class ReorderMediaPayload extends Data
{
    use DataTransform;

    public function __construct(
        /** @var array<int, string> */
        #[LiteralTypeScriptType('string[]')]
        public readonly array $mediaIds,

        /** @var string */
        public readonly string $associableType,

        /** @var string */
        public readonly string $associableId,

        /** @var string */
        public readonly string $collection = 'default',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'mediaIds' => ['required', 'array', 'min:1'],
            'mediaIds.*' => ['uuid', 'exists:'.MediaTables::Media.',id'],
            'associableType' => ['required', 'string', 'max:255'],
            'associableId' => ['required', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:50'],
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
