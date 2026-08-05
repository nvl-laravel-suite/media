<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** AttachMediaPayload: input DTO for media attachment requests. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
class AttachMediaPayload extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string */
        public readonly string $associableType,

        /** @var string */
        public readonly string $associableId,

        /** @var string */
        public readonly string $collection = 'default',

        /** @var string|null */
        public readonly ?string $locale = null,

        /** @var int|null */
        public readonly ?int $order = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'associableType' => ['required', 'string', 'max:255'],
            'associableId' => ['required', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:50'],
            'locale' => ['nullable', 'string', 'max:5'],
            'order' => ['nullable', 'integer', 'min:0'],
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
