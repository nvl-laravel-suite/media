<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Mutations;

use Nvl\Media\Tests\Fixtures\DataTransform;
use Nvl\Media\Tests\Fixtures\Http\Rules\AllowedMimeTypes;
use Nvl\Media\Tests\Fixtures\Http\Rules\MaxFileSize;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]

final class ReplaceMediaData extends Data
{
    use DataTransform;

    public function __construct(

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
