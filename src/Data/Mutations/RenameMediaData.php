<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
/** Validated media filename mutation. */
final class RenameMediaData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly string $filename,
    ) {}

    /**
     * Return the filename mutation validation rules.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/'],
        ];
    }
}
