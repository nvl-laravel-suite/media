<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** BulkMediaPayload: input DTO for bulk media operations (delete, tag, move). */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
class BulkMediaPayload extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string */
        public readonly string $action,

        /** @var list<string> */
        #[LiteralTypeScriptType('string[]')]
        public readonly array $ids,

        /** @var list<string>|null */
        #[LiteralTypeScriptType('string[] | null')]
        public readonly ?array $tags = null,

        /** @var string|null */
        public readonly ?string $folder = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:delete,tag,move'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
            'tags' => ['required_if:action,tag', 'nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'folder' => ['required_if:action,move', 'nullable', 'string', 'max:255'],
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
