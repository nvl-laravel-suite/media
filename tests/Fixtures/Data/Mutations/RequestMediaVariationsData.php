<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Mutations;

use Nvl\Media\Tests\Fixtures\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]

final class RequestMediaVariationsData extends Data
{
    use DataTransform;

    public function __construct(
        /** @var array<int, string>|null */
        public readonly ?array $variations = null,

    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'variations' => ['sometimes', 'array', 'max:50'],
            'variations.*' => ['string', 'max:255'],

        ];
    }
}
