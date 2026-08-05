<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** MediaType: classification of stored files by content category. */
#[TypeScript]
enum MediaType: string
{
    case IMAGE = 'image';
    case DOCUMENT = 'document';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case ARCHIVE = 'archive';
    case CODE = 'code';
    case OTHER = 'other';

    /**
     * Get the localized display label for this media type.
     */
    public function getLabel(): string
    {
        return (string) trans("media::media/general.types.{$this->value}");
    }

    /**
     * Get all cases as label-value option pairs for dropdowns.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['label' => $case->getLabel(), 'value' => $case->value],
            self::cases(),
        );
    }

    public static function fromExtension(string $extension): self
    {
        $extension = strtolower(ltrim($extension, '.'));

        if ($extension === '') {
            return self::OTHER;
        }

        $groupTypes = config('media.group_types', []);

        foreach (is_array($groupTypes) ? $groupTypes : [] as $group => $extensions) {
            if (! is_string($group) || ! is_array($extensions)) {
                continue;
            }

            if (in_array($extension, $extensions, true)) {
                $type = self::tryFrom($group);

                if ($type !== null) {
                    return $type;
                }
            }
        }

        return self::OTHER;
    }

    public static function fromMimeType(string $mime_type): self
    {
        $primary = strtolower(explode('/', $mime_type)[0]);

        return match ($primary) {
            'image' => self::IMAGE,
            'video' => self::VIDEO,
            'audio' => self::AUDIO,
            'application' => self::resolveApplicationMime($mime_type),
            'text' => self::resolveTextMime($mime_type),
            default => self::OTHER,
        };
    }

    public function isVisual(): bool
    {
        return in_array($this, [self::IMAGE, self::VIDEO], true);
    }

    public function supportsConversions(): bool
    {
        return $this === self::IMAGE;
    }

    private static function resolveApplicationMime(string $mime_type): self
    {
        $archive_mimes = [
            'application/zip',
            'application/vnd.rar',
            'application/x-7z-compressed',
            'application/x-tar',
            'application/gzip',
        ];

        if (in_array($mime_type, $archive_mimes, true)) {
            return self::ARCHIVE;
        }

        $code_mimes = ['application/json', 'application/xml'];

        if (in_array($mime_type, $code_mimes, true)) {
            return self::CODE;
        }

        return self::DOCUMENT;
    }

    private static function resolveTextMime(string $mime_type): self
    {
        if ($mime_type === 'text/csv' || $mime_type === 'text/plain') {
            return self::DOCUMENT;
        }

        return self::OTHER;
    }
}
