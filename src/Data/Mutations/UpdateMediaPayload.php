<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** UpdateMediaPayload: input DTO for updating media metadata and translations. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class UpdateMediaPayload extends Data
{
    use DataTransform;

    public function __construct(
        /**
         * @var bool|Optional
         */
        #[MapInputName('is_public')]
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $isPublic = new Optional,

        /**
         * @var array<int, string>|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[] | null')]
        public readonly array|Optional|null $tags = new Optional,

        /**
         * @var array<string, mixed>|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly array|Optional|null $metadata = new Optional,

        /**
         * @var array<string, array{title?: string|null, alt?: string|null, caption?: string|null, description?: string|null}>|Optional|null
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, { title?: string | null; alt?: string | null; caption?: string | null; description?: string | null }> | null')]
        public readonly array|Optional|null $translations = new Optional,

        #[TypeScriptOptional]
        #[LiteralTypeScriptType("'patch' | 'replace'")]
        public readonly TranslationSyncMode $translationMode = TranslationSyncMode::Patch,
    ) {}

    /**
     * Validation rules for media update data.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'isPublic' => ['sometimes', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'metadata' => ['nullable', 'array'],
            'translations' => ['nullable', 'array', new SupportedLocaleMapRule],
            'translations.*' => ['array:title,alt,caption,description'],
            'translations.*.title' => ['nullable', 'string', 'max:255'],
            'translations.*.alt' => ['nullable', 'string', 'max:255'],
            'translations.*.caption' => ['nullable', 'string'],
            'translations.*.description' => ['nullable', 'string'],
            'translationMode' => ['sometimes', Rule::enum(TranslationSyncMode::class)],
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
