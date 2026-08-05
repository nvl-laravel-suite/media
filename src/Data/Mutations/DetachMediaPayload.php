<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** DetachMediaPayload: input DTO for media detachment requests. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
class DetachMediaPayload extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string */
        public readonly string $associableType,

        /** @var string */
        public readonly string $associableId,

        /** @var string|null */
        public readonly ?string $collection = null,
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
