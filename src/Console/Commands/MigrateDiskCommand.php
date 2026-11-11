<?php

declare(strict_types=1);

namespace Nvl\Media\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Ownership\MediaOwnership;
use Nvl\Media\Services\MediaDeliveryPolicy;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaDiskGuard;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaFileOperator;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaPathResolver;
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
    private readonly MediaPathResolver $paths;

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

    /** Identifiers per query, well below every supported engine's bound-parameter limit. */
    private const int KEY_CHUNK = 1000;

    public function __construct(
        private readonly MediaDiskGateway $disks,
        private readonly MediaDiskGuard $diskGuard,
        private readonly MediaFileExistence $existence,
        private readonly MediaFileOperator $files,
        private readonly MediaMutationLock $mutationLock,
        private readonly MediaDeliveryPolicy $deliveryPolicy,
        ?MediaPathResolver $paths = null,
    ) {
        $this->paths = $paths ?? new MediaPathResolver;
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

        $records = $query->getModel()->newCollection();
        (clone $query)->with('imageVariations')->chunkById(self::KEY_CHUNK, function (Collection $chunk) use (&$records): void {
            $records = $records->merge($chunk);
        });
        try {
            $this->assertDestinationVisibility($records, $to);
        } catch (Throwable $error) {
            $this->error("Migration failed: {$error->getMessage()}");

            return self::FAILURE;
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
                try {
                    $this->mutationLock->executeMany(
                        array_values($records->map(static fn (Media $media): string => $media->id)->all()),
                        fn () => $records->firstOrFail()->getConnection()->transaction(function () use ($records, $from, $to): void {
                            $this->updateDiskColumn($this->lockVerifiedSnapshot($records, $to), $from, $to);
                        }),
                    );
                } catch (Throwable $error) {
                    $this->error("Migration failed: {$error->getMessage()}");

                    return self::FAILURE;
                }
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

        if (! $dryRun && $this->input->isInteractive() && ! $this->confirm('Proceed?', true)) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            return $this->migrateSnapshot($records, $from, $to, true);
        }

        try {
            return $this->mutationLock->executeMany(
                array_values($records->map(static fn (Media $media): string => $media->id)->all()),
                fn (): int => $this->migrateSnapshot($records, $from, $to, false),
            );
        } catch (Throwable $error) {
            $this->error("Migration failed: {$error->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Copy the selected snapshot and swap only those verified identities while locked.
     *
     * @param  Collection<int, Media>  $records
     */
    private function migrateSnapshot(Collection $records, string $from, string $to, bool $dryRun): int
    {
        $this->assertDestinationVisibility($records, $to);
        $moved = 0;
        $failed = 0;
        /** @var array<int, array{old: string, new: string, destination_existed: bool}> $completedMoves */
        $completedMoves = [];

        foreach ($records as $media) {
            foreach ($this->pathsForMedia($media, $to) as $sourcePath => $targetPath) {
                if ($dryRun) {
                    $this->line("  [dry-run] {$from}:{$sourcePath} → {$to}:{$targetPath}");
                    $moved++;

                    continue;
                }

                try {
                    $destinationExisted = $this->copyDiskObject($from, $sourcePath, $to, $targetPath);
                    $completedMoves[] = [
                        'old' => $sourcePath,
                        'new' => $targetPath,
                        'destination_existed' => $destinationExisted,
                    ];
                    $moved++;
                } catch (Throwable $error) {
                    $this->error("  Failed: {$sourcePath} ({$error->getMessage()})");
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
                $records->firstOrFail()->getConnection()->transaction(function () use ($records, $from, $to, $completedMoves): void {
                    $this->updateDiskColumn($this->lockVerifiedSnapshot($records, $to), $from, $to);

                    foreach ($completedMoves as $move) {
                        if (! $this->files->delete($from, $move['old'])) {
                            throw new RuntimeException('Unable to complete a verified object move.');
                        }

                        $this->line($move['destination_existed']
                            ? "  Verified existing destination and removed source: {$move['new']}"
                            : "  Moved: {$move['old']} → {$move['new']}");
                    }
                });
            } catch (Throwable $error) {
                $this->rollbackDiskMoves($from, $to, $completedMoves);
                $this->error("Database update failed. Filesystem rollback attempted: {$error->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Migration complete.');

        return self::SUCCESS;
    }

    /**
     * Fence copied paths against replacements, moves and variation changes.
     *
     * @param  Collection<int, Media>  $records
     * @return Collection<int, Media>
     */
    private function lockVerifiedSnapshot(Collection $records, string $to): Collection
    {
        $locked = $records->toBase()->chunk(self::KEY_CHUNK)->reduce(
            static fn (Collection $locked, $chunk): Collection => $locked->merge(
                $records->firstOrFail()->newQuery()->withTrashed()
                    ->whereKey($chunk->pluck('id')->all())->with('imageVariations')->orderBy('id')->lockForUpdate()->get(),
            ),
            $records->make(),
        );
        $byId = $locked->keyBy('id');

        foreach ($records as $snapshot) {
            $current = $byId->get($snapshot->id);

            if (! $current instanceof Media
                || $current->disk !== $snapshot->disk
                || $current->visibility !== $snapshot->visibility
                || $current->revision !== $snapshot->revision
                || $current->buildPath() !== $snapshot->buildPath()
                || $this->pathsForMedia($current, $to) !== $this->pathsForMedia($snapshot, $to)
                || $current->imageVariations->mapWithKeys(fn (MediaImageVariation $variation): array => [$variation->id => $variation->source_revision])->all()
                    !== $snapshot->imageVariations->mapWithKeys(fn (MediaImageVariation $variation): array => [$variation->id => $variation->source_revision])->all()) {
                throw new RuntimeException("Media [{$snapshot->id}] changed while migration was in progress.");
            }
        }

        $this->assertDestinationVisibility($locked, $to);

        return $locked;
    }

    /** @param Collection<int, Media> $records */
    private function assertDestinationVisibility(Collection $records, string $disk): void
    {
        foreach ($records as $media) {
            $this->deliveryPolicy->assertVisibility($disk, $media->visibility);
        }
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

        // SQL narrows the candidates; LIKE and equality are case-insensitive on some engines, so the exact
        // prefix is confirmed in PHP before a row is counted, previewed or rewritten.
        $hasPrefix = static fn (string $folder): bool => $folder === $normalizedFrom
            || str_starts_with($folder, $normalizedFrom.'/');
        $pattern = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalizedFrom).'/%';
        $query = MediaModelRegistry::query()->withTrashed()
            ->when(is_string($onDisk) && $onDisk !== '', function (Builder $builder) use ($onDisk): void {
                $builder->where('disk', $onDisk);
            })
            ->where(function (Builder $builder) use ($normalizedFrom, $pattern): void {
                $builder->where('folder', $normalizedFrom)
                    ->orWhereRaw("folder like ? escape '!'", [$pattern]);
            });
        $this->applyAssociationFilters($query, $associableTypes, $collections);

        $total = 0;
        $samples = [];
        (clone $query)->select(['id', 'folder'])->chunkById(1000, function ($records) use ($hasPrefix, &$total, &$samples): void {
            foreach ($records as $media) {
                if (! $hasPrefix((string) $media->folder)) {
                    continue;
                }

                $total++;

                if (count($samples) < 10) {
                    $samples[] = (string) $media->folder;
                }
            }
        });

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

        foreach ($samples as $currentFolder) {
            $this->line(sprintf('  %s → %s', $currentFolder, $this->replaceFolderPrefix($currentFolder, $normalizedFrom, $normalizedTo)));
        }

        if ($total > count($samples)) {
            $this->line(sprintf('  ... and %d more', $total - count($samples)));
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

        $query->chunkById(500, function ($records) use ($hasPrefix, $normalizedFrom, $normalizedTo, &$updated): void {
            foreach ($records as $media) {
                $folder = (string) $media->folder;

                if (! $hasPrefix($folder)) {
                    continue;
                }

                $nextFolder = $this->replaceFolderPrefix($folder, $normalizedFrom, $normalizedTo);

                (MediaModelRegistry::newInstance())->getConnection()->table((MediaModelRegistry::newInstance())->getTable())
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
     * Update the disk column, and the stored paths when the disk roots differ, for the locked media rows.
     *
     * @param  Collection<int, Media>  $locked  Rows locked and verified by lockVerifiedSnapshot()
     * @param  string  $from  Source disk name
     * @param  string  $to  Destination disk name
     */
    private function updateDiskColumn(Collection $locked, string $from, string $to): void
    {
        $this->info('Updating database records...');
        $model = $locked->firstOrFail();

        $updated = $model->getConnection()->transaction(function () use ($locked, $model, $from, $to): int {
            if (MediaPathResolver::rootFolder($from) !== MediaPathResolver::rootFolder($to)) {
                foreach ($locked as $media) {
                    $paths = $this->pathsForMedia($media, $to);
                    if (MediaOwnership::active()) {
                        $model->newQuery()->withTrashed()->whereKey($media->id)->update(['storage_path' => $paths[$media->buildPath()]]);
                    }
                    foreach ($media->imageVariations as $variation) {
                        $media->imageVariations()->whereKey($variation->id)->update([
                            'storage_path' => $paths[$variation->getPath()],
                        ]);
                    }
                }
            }

            $updated = 0;

            foreach (array_chunk($locked->modelKeys(), self::KEY_CHUNK) as $keys) {
                $updated += $model->newQuery()->withTrashed()->whereKey($keys)->update(['disk' => $to]);
            }

            return $updated;
        });

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
            $this->error("Destination disk '{$to}' is not in nvl-media.allowed_disks.");

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
        $query = MediaModelRegistry::query()->withTrashed()->where('disk', $from);

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
     * @param  string  $targetDisk  Destination disk whose configured root must be used
     * @return array<string, string> Source-to-destination object paths for the original and variations
     */
    private function pathsForMedia(Media $media, string $targetDisk): array
    {
        $rewritePaths = MediaPathResolver::rootFolder($media->disk) !== MediaPathResolver::rootFolder($targetDisk);
        $paths = [$media->buildPath() => $rewritePaths
            ? $this->paths->mediaPathForFolder($media, $media->folder ?? '', $targetDisk)
            : $media->buildPath()];

        foreach ($media->imageVariations as $variation) {
            $paths[$variation->getPath()] = $rewritePaths
                ? $this->paths->variationPathForFolder($media, $variation, $media->folder ?? '', $targetDisk)
                : $variation->getPath();
        }

        return $paths;
    }

    /**
     * Copy one object and verify it without removing the source before fencing.
     *
     * @param  string  $fromDisk  Source disk name
     * @param  string  $fromPath  Source object path
     * @param  string  $toDisk  Destination disk name
     * @param  string  $toPath  Destination object path
     * @return bool Whether the destination object existed before the migration
     */
    private function copyDiskObject(
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

        if (! $destinationExisted) {
            try {
                if (! $this->files->copy($fromDisk, $fromPath, $toDisk, $toPath)
                    || ! $this->objectsMatch($fromDisk, $fromPath, $toDisk, $toPath)) {
                    throw new RuntimeException('Unable to complete a verified object move.');
                }
            } catch (Throwable $error) {
                $this->discardCreatedObject($toDisk, $toPath);

                throw $error;
            }
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
        if (! $this->existence->existsFresh($toDisk, $toPath)) {
            throw new RuntimeException('Destination object is missing during rollback.');
        }

        if ($this->existence->existsFresh($fromDisk, $fromPath)) {
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

        if (! $destinationExisted) {
            $this->discardCreatedObject($toDisk, $toPath);
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
