<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Nvl\Media\Http\Rules\AllowedMimeTypes;
use Nvl\Media\Http\Rules\MaxFileSize;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class ReplaceMediaData extends Data
{
    use DataTransform;

    public function __construct(
        #[LiteralTypeScriptType('File')]
        public readonly mixed $file,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'file' => ['required', 'file', new MaxFileSize, new AllowedMimeTypes],
        ];
    }
}
