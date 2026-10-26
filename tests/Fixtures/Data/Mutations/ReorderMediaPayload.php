<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Mutations;

use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Tests\Fixtures\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/** ReorderMediaPayload: input DTO for reordering media associations. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]

class ReorderMediaPayload extends Data
{
    use DataTransform;

    public function __construct(
        /** @var array<int, string> */
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
            'mediaIds.*' => ['uuid', 'exists:'.MediaTables::get(MediaTables::Media).',id'],
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
        return self::translatedMessages('nvl-media::media');
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('nvl-media::media');
    }
}
