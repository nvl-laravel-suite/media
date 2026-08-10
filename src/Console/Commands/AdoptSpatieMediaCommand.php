<?php

declare(strict_types=1);

namespace Nvl\Media\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Nvl\Media\Actions\AdoptSpatieMediaAction;
use Nvl\Media\Data\MediaAdoptionResultData;

/**
 * Plans or applies a reconciled Spatie-style media adoption.
 */
final class AdoptSpatieMediaCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:media:adopt-spatie
        {--source=media_spatie_legacy : Staged legacy media table}
        {--translations= : Optional staged translation table}
        {--variations= : Optional staged variation table}
        {--uploader-type= : Fallback morph type for legacy uploaded_by values}
        {--locale=en : Fallback locale for translation rows}
        {--apply : Insert reconciled rows; default is dry-run}
        {--format=text : Output format: text or json}';

    /** @var string */
    protected $description = 'Plan or apply a non-destructive Spatie-style media adoption';

    /**
     * Execute the dry-run-first media adoption command.
     *
     * @throws JsonException
     */
    public function handle(AdoptSpatieMediaAction $adopt): int
    {
        $format = $this->stringOption('format');

        if (! in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('The media adoption format must be text or json.');
        }

        $result = $adopt->execute(
            sourceTable: $this->stringOption('source'),
            translationTable: $this->nullableStringOption('translations'),
            variationTable: $this->nullableStringOption('variations'),
            uploaderType: $this->nullableStringOption('uploader-type'),
            defaultLocale: $this->stringOption('locale'),
            apply: (bool) $this->option('apply'),
        );

        if ($format === 'json') {
            $this->line((string) json_encode(
                $result->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->renderTextResult($result);
        }

        return $result->ready ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Render a concise reconciliation table and any adoption blockers.
     */
    private function renderTextResult(MediaAdoptionResultData $result): void
    {
        $this->components->info("Media adoption {$result->mode} completed.");
        $this->table(
            ['Resource', 'Source', 'Matched'],
            [
                ['media', $result->sourceMedia, $result->matchedMedia],
                ['associations', $result->sourceAssociations, $result->matchedAssociations],
                ['translations', $result->sourceTranslations, $result->matchedTranslations],
                ['variations', $result->sourceVariations, $result->matchedVariations],
            ],
        );

        foreach ($result->errors as $error) {
            $this->components->error($error);
        }

        foreach ($result->missingPaths as $path) {
            $this->components->error("Missing backing object [{$path}].");
        }
    }

    /**
     * Resolve a required string command option.
     */
    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Media adoption option --{$name} requires a value.");
        }

        return trim($value);
    }

    /**
     * Resolve an optional string command option.
     */
    private function nullableStringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
