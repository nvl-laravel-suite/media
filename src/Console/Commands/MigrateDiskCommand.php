<?php

declare(strict_types=1);

namespace Nvl\Media\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaDiskGuard;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaFileOperator;
use RuntimeException;
use Throwable;

/**
 * Migrate media storage metadata and optionally move backing files between disks.
 *
 * Default mode migrates the `disk` column and can move whole folder trees.
 * Database-only mode can also rewrite the `folder` column using prefix
 * replacement without touching the filesystem.
 */
class MigrateDiskCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:media:migrate-disk
        {--from=media : Source value to migrate from}
        {--to=local : Destination value to migrate to}
        {--column=disk : Database column to migrate [disk|folder]}
        {--from-path= : Absolute path to source disk root (when the source disk is no longer configured)}
        {--on-disk= : Restrict folder migrations to a specific disk}
        {--associable-type=* : Restrict to media associated with one or more model morph classes/types}
        {--collection=* : Restrict to media associated through one or more collections}
        {--records-only : Update database records only; do not move filesystem content}
        {--dry-run : Preview without moving or updating}';

    /** @var string */
    protected $description = 'Migrate media disk or folder records, optionally moving backing files between disks';

    public function __construct(
        private readonly MediaDiskGateway $disks,
        private readonly MediaDiskGuard $diskGuard,
        private readonly MediaFileExistence $existence,
        private readonly MediaFileOperator $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the media disk or folder migration command.
     *
     * @return int Console command exit code
     */
    public function handle(): int
    {
        $from = $this->stringOption('from', 'media');
        $to = $this->stringOption('to', 'local');
        $column = $this->stringOption('column', 'disk');
        $fromPath = $this->option('from-path');
        $onDisk = $this->option('on-disk');
        $associableTypes = $this->optionList('associable-type');
        $collections = $this->optionList('collection');
        $recordsOnly = (bool) $this->option('records-only');
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->validateInputs($from, $to, $column, $fromPath, $onDisk, $recordsOnly)) {
            return self::FAILURE;
        }

        return $column === 'folder'
            ? $this->handleFolderMigration($from, $to, $onDisk, $associableTypes, $collections, $dryRun)
            : $this->handleDiskMigration($from, $to, $fromPath, $associableTypes, $collections, $recordsOnly, $dryRun);
    }

    /**
     * Move media records and backing files between disks.
     *
     * @param  string  $from  Source disk name
     * @param  string  $to  Destination disk name
     * @param  mixed  $fromPath  Optional offline source-path value
     * @param  list<string>  $associableTypes  Optional association type filters
     * @param  list<string>  $collections  Optional association collection filters
     * @param  bool  $recordsOnly  Whether to skip filesystem moves
     * @param  bool  $dryRun  Whether to preview without writes
     * @return int Console command exit code
     */
    private function handleDiskMigration(
        string $from,
        string $to,
        mixed $fromPath,
        array $associableTypes,
        array $collections,
        bool $recordsOnly,
        bool $dryRun,
    ): int {
        $query = $this->mediaQuery($from, $associableTypes, $collections);
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("No media records on disk '{$from}'. Nothing to migrate.");

            return self::SUCCESS;
        }

        if ($recordsOnly) {
            $this->info(sprintf(
                '%sUpdating %d media records: disk [%s] → [%s] (database only)',
                $dryRun ? '[DRY RUN] ' : '',
                $total,
                $from,
                $to,
            ));
            $this->displayAssociationFilters($associableTypes, $collections);

            if (! $dryRun) {
                $this->updateDiskColumn($query, $from, $to);
            }

            $this->newLine();
            $this->info('Migration complete.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%sMoving %d media records from disk [%s] to [%s]',
            $dryRun ? '[DRY RUN] ' : '',
            $total,
            $from,
            $to,
        ));
        $this->displayAssociationFilters($associableTypes, $collections);
        $this->warnSharedMediaScope($associableTypes, $collections);

        $records = (clone $query)
            ->with('imageVariations')
            ->orderBy('id')
            ->get();

        if (! $dryRun && $this->input->isInteractive() && ! $this->confirm('Proceed?', true)) {
            return self::SUCCESS;
        }

        $moved = 0;
        $failed = 0;
        /** @var array<int, array{old: string, new: string, destination_existed: bool}> $completedMoves */
        $completedMoves = [];

        foreach ($records as $media) {
            foreach ($this->pathsForMedia($media) as $path) {
                if ($dryRun) {
                    $this->line("  [dry-run] {$from}:{$path} → {$to}:{$path}");
                    $moved++;

                    continue;
                }

                try {
                    $destinationExisted = $this->moveDiskObject($from, $path, $to, $path);
                    $message = $destinationExisted
                        ? "  Verified existing destination and removed source: {$path}"
                        : "  Moved: {$path}";
                    $this->line($message);
                    $completedMoves[] = [
                        'old' => $path,
                        'new' => $path,
                        'destination_existed' => $destinationExisted,
                    ];
                    $moved++;
                } catch (Throwable $error) {
                    $this->error("  Failed: {$path} ({$error->getMessage()})");
                    $failed++;
                }
            }
        }

        $this->newLine();
        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}{$moved} object(s) moved, {$failed} failed.");

        if ($failed > 0) {
            $this->rollbackDiskMoves($from, $to, $completedMoves);
            $this->error('Some objects failed to move. Database NOT updated. Fix and re-run.');

            return self::FAILURE;
        }

        if (! $dryRun) {
            try {
                $this->updateDiskColumn($query, $from, $to);
            } catch (Throwable $error) {
                $this->rollbackDiskMoves($from, $to, $completedMoves);
                $this->error("Database update failed. Filesystem moves rolled back: {$error->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Migration complete.');

        return self::SUCCESS;
    }

    /**
     * Rewrite media folder prefixes for selected records.
     *
     * @param  string  $from  Source folder prefix
     * @param  string  $to  Destination folder prefix
     * @param  mixed  $onDisk  Optional disk restriction
     * @param  list<string>  $associableTypes  Optional association type filters
     * @param  list<string>  $collections  Optional association collection filters
     * @param  bool  $dryRun  Whether to preview without writes
     * @return int Console command exit code
     */
    private function handleFolderMigration(
        string $from,
        string $to,
        mixed $onDisk,
        array $associableTypes,
        array $collections,
        bool $dryRun,
    ): int {
        $normalizedFrom = trim($from, '/');
        $normalizedTo = trim($to, '/');

        $query = Media::withTrashed()
            ->when(is_string($onDisk) && $onDisk !== '', function (Builder $builder) use ($onDisk): void {
                $builder->where('disk', $onDisk);
            })
            ->where(function (Builder $builder) use ($normalizedFrom): void {
                $builder->where('folder', $normalizedFrom)
                    ->orWhere('folder', 'like', $normalizedFrom.'/%');
            });
        $this->applyAssociationFilters($query, $associableTypes, $collections);

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("No media records with folder prefix '{$normalizedFrom}'. Nothing to migrate.");

            return self::SUCCESS;
        }

        $scopeSuffix = is_string($onDisk) && $onDisk !== '' ? " on disk '{$onDisk}'" : '';
        $this->info(sprintf(
            '%sUpdating %d media records%s: folder [%s] → [%s] (database only)',
            $dryRun ? '[DRY RUN] ' : '',
            $total,
            $scopeSuffix,
            $normalizedFrom,
            $normalizedTo,
        ));
        $this->displayAssociationFilters($associableTypes, $collections);
        $this->warnSharedMediaScope($associableTypes, $collections);

        $samples = (clone $query)->orderBy('id')->limit(10)->get(['id', 'folder']);

        foreach ($samples as $media) {
            $currentFolder = (string) $media->folder;
            $nextFolder = $this->replaceFolderPrefix($currentFolder, $normalizedFrom, $normalizedTo);
            $this->line(sprintf('  %s → %s', $currentFolder, $nextFolder));
        }

        if ($total > $samples->count()) {
            $this->line(sprintf('  ... and %d more', $total - $samples->count()));
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run complete.');

            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! $this->confirm('Proceed?', true)) {
            return self::SUCCESS;
        }

        $updated = 0;

        $query->orderBy('id')->chunk(500, function ($records) use ($normalizedFrom, $normalizedTo, &$updated): void {
            foreach ($records as $media) {
                $folder = (string) $media->folder;
                $nextFolder = $this->replaceFolderPrefix($folder, $normalizedFrom, $normalizedTo);

                DB::table(Media::TABLE)
                    ->where('id', $media->id)
                    ->update(['folder' => $nextFolder]);

                $updated++;
            }
        });

        $this->newLine();
        $this->info("Updated {$updated} records: folder '{$normalizedFrom}' → '{$normalizedTo}'");
        $this->newLine();
        $this->info('Migration complete.');

        return self::SUCCESS;
    }

    /**
     * Update the disk column for the selected media rows.
     *
     * @param  Builder<Media>  $query  Selected media query
     * @param  string  $from  Source disk name
     * @param  string  $to  Destination disk name
     */
    private function updateDiskColumn(Builder $query, string $from, string $to): void
    {
        $this->info('Updating database records...');

        $updated = (clone $query)->update(['disk' => $to]);

        $this->info("Updated {$updated} records: disk '{$from}' → '{$to}'");
    }

    private function validateInputs(
        string $from,
        string $to,
        string $column,
        mixed $fromPath,
        mixed $onDisk,
        bool $recordsOnly,
    ): bool {
        if (! in_array($column, ['disk', 'folder'], true)) {
            $this->error("Column '{$column}' is invalid. Use 'disk' or 'folder'.");

            return false;
        }

        if ($from === $to) {
            $this->error('Source and destination values must be different.');

            return false;
        }

        if ($column === 'folder') {
            if (trim($from) === '') {
                $this->error('Folder migrations require a non-empty --from value.');

                return false;
            }

            if (is_string($fromPath) && $fromPath !== '') {
                $this->error('--from-path can only be used with disk migrations.');

                return false;
            }

            return true;
        }

        if (is_string($onDisk) && $onDisk !== '') {
            $this->error('--on-disk can only be used with folder migrations.');

            return false;
        }

        $hasFromPath = is_string($fromPath) && $fromPath !== '';

        if ($hasFromPath) {
            $this->error('--from-path is no longer supported for physical disk moves. Configure the source disk or use --records-only after external copy.');

            return false;
        }

        if (! $recordsOnly && config("filesystems.disks.{$from}") === null) {
            $this->error("Source disk '{$from}' is not configured. Use --records-only after copying files externally.");

            return false;
        }

        if (config("filesystems.disks.{$to}") === null) {
            $this->error("Destination disk '{$to}' is not configured.");

            return false;
        }

        if (! $this->diskGuard->isAllowed($to)) {
            $this->error("Destination disk '{$to}' is not in media.allowed_disks.");

            return false;
        }

        return true;
    }

    /**
     * Build the base media selection query for disk migrations.
     *
     * @param  string  $from  Source disk name
     * @param  list<string>  $associableTypes  Optional association type filters
     * @param  list<string>  $collections  Optional association collection filters
     * @return Builder<Media>
     */
    private function mediaQuery(string $from, array $associableTypes, array $collections): Builder
    {
        $query = Media::withTrashed()->where('disk', $from);

        $this->applyAssociationFilters($query, $associableTypes, $collections);

        return $query;
    }

    /**
     * Apply optional association filters to a media query.
     *
     * @param  Builder<Media>  $query  Media query to filter
     * @param  list<string>  $associableTypes  Optional association type filters
     * @param  list<string>  $collections  Optional association collection filters
     */
    private function applyAssociationFilters(Builder $query, array $associableTypes, array $collections): void
    {
        if ($associableTypes === [] && $collections === []) {
            return;
        }

        $query->whereHas('associations', function (Builder $associationQuery) use ($associableTypes, $collections): void {
            if ($associableTypes !== []) {
                $associationQuery->whereIn('associable_type', $associableTypes);
            }

            if ($collections !== []) {
                $associationQuery->whereIn('collection', $collections);
            }
        });
    }

    /**
     * Parse an array Artisan option into a normalized list.
     *
     * @param  string  $name  Option name
     * @return list<string>
     */
    private function optionList(string $name): array
    {
        $raw = $this->option($name);
        $values = is_array($raw) ? $raw : [$raw];
        $normalized = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            foreach (explode(',', (string) $value) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $normalized[] = $part;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Resolve a scalar command option consistently across Laravel versions.
     */
    private function stringOption(string $name, string $default): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : $default;
    }

    /**
     * Display association filter details.
     *
     * @param  list<string>  $associableTypes  Optional association type filters
     * @param  list<string>  $collections  Optional association collection filters
     */
    private function displayAssociationFilters(array $associableTypes, array $collections): void
    {
        if ($associableTypes === [] && $collections === []) {
            return;
        }

        $this->line('  Association filters:');
        $this->line('    • Types: '.($associableTypes === [] ? 'Any' : implode(', ', $associableTypes)));
        $this->line('    • Collections: '.($collections === [] ? 'Any' : implode(', ', $collections)));
    }

    /**
     * Warn that selected media rows may be shared by other consumers.
     *
     * @param  list<string>  $associableTypes  Optional association type filters
     * @param  list<string>  $collections  Optional association collection filters
     */
    private function warnSharedMediaScope(array $associableTypes, array $collections): void
    {
        if ($associableTypes === [] && $collections === []) {
            return;
        }

        $this->warn('  Note: selected media rows are migrated globally even when they have additional associations.');
    }

    /**
     * Replace a folder prefix with a new folder prefix.
     *
     * @param  string  $folder  Current folder value
     * @param  string  $from  Source prefix
     * @param  string  $to  Destination prefix
     * @return string Rewritten folder value
     */
    private function replaceFolderPrefix(string $folder, string $from, string $to): string
    {
        if ($folder === $from) {
            return $to;
        }

        $suffix = ltrim(substr($folder, strlen($from)), '/');

        if ($suffix === '') {
            return $to;
        }

        return $to !== '' ? $to.'/'.$suffix : $suffix;
    }

    /**
     * Build the relative object paths that must move with a media record.
     *
     * @param  Media  $media  Media record being migrated
     * @return list<string> Relative object paths for the original and variations
     */
    private function pathsForMedia(Media $media): array
    {
        $paths = [$media->buildPath()];

        foreach ($media->imageVariations as $variation) {
            $paths[] = $variation->getPath();
        }

        return array_values(array_unique($paths));
    }

    /**
     * Move one object after proving the destination matches the source.
     *
     * @param  string  $fromDisk  Source disk name
     * @param  string  $fromPath  Source object path
     * @param  string  $toDisk  Destination disk name
     * @param  string  $toPath  Destination object path
     * @return bool Whether the destination object existed before the migration
     */
    private function moveDiskObject(
        string $fromDisk,
        string $fromPath,
        string $toDisk,
        string $toPath,
    ): bool {
        if (! $this->existence->exists($fromDisk, $fromPath)) {
            throw new RuntimeException('Source object is missing.');
        }

        $destinationExisted = $this->existence->exists($toDisk, $toPath);

        if ($destinationExisted) {
            if (! $this->objectsMatch($fromDisk, $fromPath, $toDisk, $toPath)) {
                throw new RuntimeException('Existing destination failed size/checksum verification.');
            }
        }

        if (! $this->files->move($fromDisk, $fromPath, $toDisk, $toPath)) {
            throw new RuntimeException('Unable to complete a verified object move.');
        }

        return $destinationExisted;
    }

    /**
     * Determine whether two storage objects have identical size and contents.
     *
     * @param  string  $firstDisk  First disk name
     * @param  string  $firstPath  First object path
     * @param  string  $secondDisk  Second disk name
     * @param  string  $secondPath  Second object path
     * @return bool True when byte size and SHA-256 checksum match
     */
    private function objectsMatch(
        string $firstDisk,
        string $firstPath,
        string $secondDisk,
        string $secondPath,
    ): bool {
        if ($this->disks->size($firstDisk, $firstPath) !== $this->disks->size($secondDisk, $secondPath)) {
            return false;
        }

        return hash_equals(
            $this->disks->checksum($firstDisk, $firstPath),
            $this->disks->checksum($secondDisk, $secondPath),
        );
    }

    /**
     * Remove an object created by a failed migration without masking the failure.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Object path
     */
    private function discardCreatedObject(string $disk, string $path): void
    {
        try {
            if ($this->existence->exists($disk, $path) && ! $this->files->delete($disk, $path)) {
                $this->warn("  Cleanup failed for {$disk}:{$path}");
            }
        } catch (Throwable $cleanupError) {
            $this->warn("  Cleanup failed for {$disk}:{$path}: {$cleanupError->getMessage()}");
        }
    }

    /**
     * Restore one completed object move while preserving pre-existing destinations.
     *
     * @param  string  $fromDisk  Original source disk
     * @param  string  $fromPath  Original source path
     * @param  string  $toDisk  Migration destination disk
     * @param  string  $toPath  Migration destination path
     * @param  bool  $destinationExisted  Whether the destination predated migration
     */
    private function restoreDiskObject(
        string $fromDisk,
        string $fromPath,
        string $toDisk,
        string $toPath,
        bool $destinationExisted,
    ): void {
        if (! $this->existence->exists($toDisk, $toPath)) {
            throw new RuntimeException('Destination object is missing during rollback.');
        }

        if (! $destinationExisted) {
            if (! $this->files->move($toDisk, $toPath, $fromDisk, $fromPath)) {
                throw new RuntimeException('Unable to restore the verified source object.');
            }

            return;
        }

        if ($this->existence->exists($fromDisk, $fromPath)) {
            if (! $this->objectsMatch($toDisk, $toPath, $fromDisk, $fromPath)) {
                throw new RuntimeException('Existing rollback source failed size/checksum verification.');
            }
        } else {
            if (! $this->files->copy($toDisk, $toPath, $fromDisk, $fromPath)) {
                $this->discardCreatedObject($fromDisk, $fromPath);

                throw new RuntimeException('Unable to restore the source object.');
            }

            if (! $this->objectsMatch($toDisk, $toPath, $fromDisk, $fromPath)) {
                $this->discardCreatedObject($fromDisk, $fromPath);

                throw new RuntimeException('Restored source failed size/checksum verification.');
            }
        }
    }

    /**
     * Move already-copied objects back to the source disk after a failed migration.
     *
     * @param  string  $from  Source disk name
     * @param  string  $to  Destination disk name
     * @param  array<int, array{old: string, new: string, destination_existed: bool}>  $completedMoves  Completed move list
     */
    private function rollbackDiskMoves(string $from, string $to, array $completedMoves): void
    {
        foreach (array_reverse($completedMoves) as $move) {
            try {
                $this->restoreDiskObject(
                    $from,
                    $move['old'],
                    $to,
                    $move['new'],
                    $move['destination_existed'],
                );
            } catch (Throwable $rollbackError) {
                $this->warn("  Rollback failed for {$move['new']}: {$rollbackError->getMessage()}");
            }
        }
    }
}
