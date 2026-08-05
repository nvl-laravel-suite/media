<?php

declare(strict_types=1);

namespace Nvl\Media\Data;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Media\Enums\MediaType;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** MediaFilter: query filter DTO for listing and searching media. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
class MediaFilter extends Data
{
    use DataTransform;

    /** @var array<int, string> */
    public const ALLOWED_SORT_COLUMNS = [
        'created_at',
        'updated_at',
        'filename',
        'size',
        'type',
        'extension',
        'mime_type',
    ];

    public function __construct(
        /**
         * @var string|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $search = null,

        /**
         * @var MediaType|null
         */
        #[TypeScriptOptional]
        #[TypeScriptType(MediaType::class)]
        public readonly MediaType|Optional|null $type = null,

        /**
         * @var string|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $disk = null,

        /**
         * @var bool|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $isPublic = null,

        /**
         * @var string|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $collection = null,

        /**
         * @var string|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $tag = null,

        /**
         * @var string|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $folder = null,

        /**
         * @var string|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $mimeType = null,

        /**
         * @var string|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $extension = null,

        /**
         * @var string|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $locale = null,

        /**
         * @var string|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $associableType = null,

        /**
         * @var int|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number | null')]
        public readonly int|Optional|null $perPage = 25,

        /**
         * @var int|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number | null')]
        public readonly int|Optional|null $page = 1,

        /**
         * @var string|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $sortBy = 'created_at',

        /**
         * @var string|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $sortDirection = 'desc',
    ) {}

    /**
     * Validation rules for media filter data.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(MediaType::class)],
            'disk' => ['nullable', 'string', 'max:25'],
            'isPublic' => ['nullable', 'boolean'],
            'collection' => ['nullable', 'string', 'max:50'],
            'tag' => ['nullable', 'string', 'max:50'],
            'folder' => ['nullable', 'string', 'max:255'],
            'mimeType' => ['nullable', 'string', 'max:100'],
            'extension' => ['nullable', 'string', 'max:10'],
            'locale' => ['nullable', 'string', 'max:5'],
            'associableType' => ['nullable', 'string', 'max:255'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sortBy' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_COLUMNS)],
            'sortDirection' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * Custom validation messages from module translations.
     *
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return self::translatedMessages('media::media');
    }

    /**
     * Attribute names for clearer error messages.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('media::media');
    }
}
