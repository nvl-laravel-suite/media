<?php

declare(strict_types=1);

namespace Nvl\Media\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Nvl\Media\Exceptions\DiskNotDefinedException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaDiskGuard;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaFileOperator;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Services\MediaUrlResolver;
use Nvl\Media\Support\MediaConfiguration;
use Throwable;

/**
 * StorageHealthCommand verifies existing Media records against configured storage and route contracts.
 *
 * The command is intentionally read-only by default. It is designed for
 * already-migrated environments where the database and object storage should
 * be audited, not mutated.
 */
final class StorageHealthCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:media:reconcile
        {--disk= : Storage disk to inspect; defaults to media.disk}
        {--sample=50 : Maximum number of media records to inspect}
        {--public-private-routes : Verify public/private route-backed URL contracts}
        {--routes : Alias for --public-private-routes}
        {--include-trashed : Include soft-deleted media records}
        {--orphans : Inventory unreferenced originals and variations under media.root_folder}
        {--cleanup-orphans : Delete eligible orphan objects after inventory}
        {--older-than=1440 : Minimum orphan age in minutes}
        {--force : Required with --cleanup-orphans in production}
        {--require-records : Fail when the selected disk has no media records}
        {--production : Verifies route contracts, requires records, prohibits live-write, and requires force for orphan cleanup}
        {--live-write : Opt in to a temporary write/read/delete healthcheck object}
        {--cleanup : Explicitly request cleanup of the temporary live-write object}
        {--no-write : Assert read-only mode; incompatible with --live-write}';

    /** @var string */
    protected $description = 'Verify Media storage objects, variations, and route-backed URL contracts without migrating files.';

    public function __construct(
        private readonly MediaDiskGateway $disks,
        private readonly MediaDiskGuard $diskGuard,
        private readonly MediaFileExistence $existence,
        private readonly MediaFileOperator $files,
        private readonly MediaPathResolver $pathResolver,
        private readonly MediaUrlResolver $urlResolver,
    ) {
        parent::__construct();
    }

    /**
     * Execute the storage health check.
     *
     * @return int Console command exit code
     */
    public function handle(): int
    {
        $disk = $this->resolveDisk();
        $sample = $this->resolveSample();
        $production = (bool) $this->option('production');
        $verifyRoutes = (bool) ($production || $this->option('public-private-routes') || $this->option('routes'));
        $includeTrashed = (bool) $this->option('include-trashed');
        $requireRecords = (bool) ($production || $this->option('require-records'));
        $liveWrite = (bool) $this->option('live-write');
        $noWrite = (bool) ($production || $this->option('no-write'));
        $inventoryOrphans = (bool) ($this->option('orphans') || $this->option('cleanup-orphans'));
        $cleanupOrphans = (bool) $this->option('cleanup-orphans');
        $olderThan = max(0, (int) $this->option('older-than'));

        if ($sample < 1) {
            $this->error('--sample must be at least 1.');

            return self::FAILURE;
        }

        if ($production && $liveWrite) {
            $this->error('--production cannot be combined with --live-write.');

            return self::FAILURE;
        }

        if ($production && $cleanupOrphans && ! (bool) $this->option('force')) {
            $this->error('--force is required for orphan cleanup in production.');

            return self::FAILURE;
        }

        if ($liveWrite && $noWrite) {
            $this->error('--live-write cannot be combined with --no-write.');

            return self::FAILURE;
        }

        $failures = [];
        $warnings = [];

        if (! $this->validateDisk($disk, $failures, $warnings)) {
            $this->printIssues($warnings, $failures);

            return self::FAILURE;
        }

        $this->info("Media storage health check for disk [{$disk}]");
        $this->line('Mode: '.$this->modeLabel($production, $liveWrite, $cleanupOrphans));

        $this->printInventory($includeTrashed);

        if ($liveWrite) {
            $liveWriteReport = $this->runLiveWriteProbe($disk);
            $failures = array_merge($failures, $liveWriteReport['failures']);
        }

        $recordReport = $this->verifyMediaRecords($disk, $sample, $includeTrashed, $verifyRoutes);
        $failures = array_merge($failures, $recordReport['failures']);
        $warnings = array_merge($warnings, $recordReport['warnings']);
        $orphanReport = [
            'candidates' => 0,
            'protected' => 0,
            'deleted' => 0,
            'failures' => [],
            'warnings' => [],
        ];

        if ($inventoryOrphans) {
            $orphanReport = $this->inventoryOrphans(
                $disk,
                $olderThan,
                $cleanupOrphans,
            );
            $failures = array_merge($failures, $orphanReport['failures']);
            $warnings = array_merge($warnings, $orphanReport['warnings']);
        }

        if ($requireRecords && $recordReport['records_checked'] === 0) {
            $failures[] = "No media records were found on required disk [{$disk}].";
        }

        $this->newLine();
        $this->info('Summary');
        $this->line("  Records checked: {$recordReport['records_checked']}");
        $this->line("  Originals checked: {$recordReport['originals_checked']}");
        $this->line("  Variations checked: {$recordReport['variations_checked']}");
        $this->line("  Route contracts checked: {$recordReport['route_checks']}");

        if ($inventoryOrphans) {
            $this->line("  Orphan candidates: {$orphanReport['candidates']}");
            $this->line("  Recent/unreliable protected objects: {$orphanReport['protected']}");
            $this->line("  Orphan objects deleted: {$orphanReport['deleted']}");
        }

        $this->printIssues($warnings, $failures);

        if ($failures !== []) {
            $this->error('Media storage health check failed.');

            return self::FAILURE;
        }

        $this->info('Media storage health check passed.');

        return self::SUCCESS;
    }

    /**
     * Resolve the target disk from command input and configuration.
     */
    private function resolveDisk(): string
    {
        $disk = $this->option('disk');

        if (is_string($disk) && trim($disk) !== '') {
            return trim($disk);
        }

        $mediaDisk = config('media.disk');

        if (is_string($mediaDisk) && trim($mediaDisk) !== '') {
            return trim($mediaDisk);
        }

        $defaultDisk = config('filesystems.default');

        return is_string($defaultDisk) && trim($defaultDisk) !== ''
            ? trim($defaultDisk)
            : 'local';
    }

    /**
     * Resolve and sanitize the sample size.
     */
    private function resolveSample(): int
    {
        $sample = $this->option('sample');

        if (is_numeric($sample)) {
            return (int) $sample;
        }

        return 50;
    }

    /**
     * Build the console mode label.
     */
    private function modeLabel(
        bool $production,
        bool $liveWrite,
        bool $cleanupOrphans,
    ): string {
        if ($production) {
            return $cleanupOrphans
                ? 'production orphan cleanup'
                : 'production read-only';
        }

        return $liveWrite ? 'live write probe enabled' : 'read-only';
    }

    /**
     * Validate disk configuration and collect non-fatal warnings.
     *
     * @param  list<string>  $failures
     * @param  list<string>  $warnings
     */
    private function validateDisk(string $disk, array &$failures, array &$warnings): bool
    {
        try {
            $this->disks->ensureDefined($disk);
        } catch (DiskNotDefinedException $exception) {
            $failures[] = $exception->getMessage();

            return false;
        }

        if (! $this->diskGuard->isAllowed($disk)) {
            $warnings[] = "Disk [{$disk}] is configured but is not present in media.allowed_disks.";
        }

        return true;
    }

    /**
     * Print media row inventory grouped by disk and visibility.
     */
    private function printInventory(bool $includeTrashed): void
    {
        $query = $this->baseMediaQuery($includeTrashed)
            ->selectRaw('disk, is_public, count(*) as total')
            ->groupBy('disk', 'is_public')
            ->orderBy('disk')
            ->orderBy('is_public');

        $this->newLine();
        $this->info('Media rows by disk and visibility');

        foreach ($query->get() as $row) {
            /** @var Media $row */
            $visibility = $row->is_public ? 'public' : 'private';
            $rawTotal = $row->getAttribute('total');
            $total = is_int($rawTotal) ? $rawTotal : 0;

            $this->line("  {$row->disk} / {$visibility}: {$total}");
        }
    }

    /**
     * Run an opt-in write/read/delete probe against the disk.
     *
     * @return array{failures: list<string>}
     */
    private function runLiveWriteProbe(string $disk): array
    {
        $path = 'healthchecks/media-storage-health-'.now()->format('YmdHis').'-'.Str::random(8).'.txt';
        $failures = [];
        $written = false;

        $this->newLine();
        $this->info("Running live write probe at [{$disk}:{$path}]");

        try {
            $written = $this->files->put($disk, $path, 'ok');

            if (! $written) {
                $failures[] = "Live write probe failed to write [{$disk}:{$path}].";
            } elseif (! $this->existence->exists($disk, $path)) {
                $failures[] = "Live write probe wrote [{$disk}:{$path}] but existence check failed.";
            } elseif ($this->disks->get($disk, $path) !== 'ok') {
                $failures[] = "Live write probe readback mismatch for [{$disk}:{$path}].";
            }
        } catch (Throwable $exception) {
            $failures[] = "Live write probe failed for [{$disk}:{$path}]: {$exception->getMessage()}";
        } finally {
            if ($written) {
                try {
                    $this->files->delete($disk, $path);
                } catch (Throwable $exception) {
                    $failures[] = "Live write probe cleanup failed for [{$disk}:{$path}]: {$exception->getMessage()}";
                }
            }
        }

        return ['failures' => $failures];
    }

    /**
     * Verify sampled Media originals, variations, and optional route contracts.
     *
     * @return array{
     *     records_checked: int,
     *     originals_checked: int,
     *     variations_checked: int,
     *     route_checks: int,
     *     failures: list<string>,
     *     warnings: list<string>
     * }
     */
    private function verifyMediaRecords(string $disk, int $sample, bool $includeTrashed, bool $verifyRoutes): array
    {
        $records = $this->baseMediaQuery($includeTrashed)
            ->with('imageVariations')
            ->where('disk', $disk)
            ->orderBy('id')
            ->limit($sample)
            ->get();

        $report = [
            'records_checked' => 0,
            'originals_checked' => 0,
            'variations_checked' => 0,
            'route_checks' => 0,
            'failures' => [],
            'warnings' => [],
        ];

        if ($records->isEmpty()) {
            $report['warnings'][] = "No media records found on disk [{$disk}].";

            return $report;
        }

        foreach ($records as $media) {
            /** @var Media $media */
            $report['records_checked']++;
            $report['originals_checked']++;

            $originalPath = $this->pathResolver->mediaPath($media);
            $originalCheck = $this->checkObjectExists($media->disk, $originalPath);
            if (! $originalCheck['exists']) {
                $report['failures'][] = $this->storageFailureMessage(
                    "Missing original for media [{$media->id}]",
                    $media->disk,
                    $originalPath,
                    $originalCheck['error'],
                );
            }

            if ($verifyRoutes) {
                $routeReport = $this->verifyRouteContract($media, null);
                $report['route_checks'] += $routeReport['checks'];
                $report['failures'] = array_merge($report['failures'], $routeReport['failures']);
            }

            foreach ($media->imageVariations as $variation) {
                /** @var MediaImageVariation $variation */
                $report['variations_checked']++;

                $variationPath = $variation->getPath();
                $variationCheck = $this->checkObjectExists($media->disk, $variationPath);
                if (! $variationCheck['exists']) {
                    $report['failures'][] = $this->storageFailureMessage(
                        "Missing variation [{$variation->label}] for media [{$media->id}]",
                        $media->disk,
                        $variationPath,
                        $variationCheck['error'],
                    );
                }

                if ($verifyRoutes) {
                    $routeReport = $this->verifyRouteContract($media, $variation);
                    $report['route_checks'] += $routeReport['checks'];
                    $report['failures'] = array_merge($report['failures'], $routeReport['failures']);
                }
            }
        }

        return $report;
    }

    /**
     * Verify route-backed URL generation and private signed URL validity.
     *
     * @return array{checks: int, failures: list<string>}
     */
    private function verifyRouteContract(Media $media, ?MediaImageVariation $variation): array
    {
        $parameters = $variation instanceof MediaImageVariation ? ['v' => $variation->label] : [];
        $label = $variation instanceof MediaImageVariation ? " variation [{$variation->label}]" : '';
        $routeName = $media->is_public
            ? MediaConfiguration::string(
                'media.assets.public_route_name',
                'media.assets.show',
            )
            : MediaConfiguration::string(
                'media.assets.private_route_name',
                'media.private.show',
            );

        if (! Route::has($routeName)) {
            return [
                'checks' => 1,
                'failures' => ["Route [{$routeName}] is not registered for media [{$media->id}]{$label}."],
            ];
        }

        if ($media->is_public) {
            return $this->verifyPublicRouteContract($media, $routeName, $parameters, $label);
        }

        return $this->verifyPrivateRouteContract($media, $routeName, $parameters, $label);
    }

    /**
     * Verify a public media URL uses the configured public asset route.
     *
     * @param  array<string, string>  $parameters
     * @return array{checks: int, failures: list<string>}
     */
    private function verifyPublicRouteContract(Media $media, string $routeName, array $parameters, string $label): array
    {
        $url = $this->urlResolver->publicUrl($media, $parameters);
        $expectedPath = $this->pathFromUrl(route($routeName, ['media' => $media->id], false));
        $actualPath = $this->pathFromUrl($url);
        $failures = [];

        if ($actualPath !== $expectedPath) {
            $failures[] = "Public media [{$media->id}]{$label} generated non-route URL [{$url}].";
        }

        $query = $this->queryFromUrl($url);
        if (($parameters['v'] ?? null) !== null && ($query['v'] ?? null) !== $parameters['v']) {
            $failures[] = "Public media [{$media->id}]{$label} URL is missing expected variation query parameter.";
        }

        return [
            'checks' => 1,
            'failures' => $failures,
        ];
    }

    /**
     * Verify a private media URL uses the configured signed private asset route.
     *
     * @param  array<string, string>  $parameters
     * @return array{checks: int, failures: list<string>}
     */
    private function verifyPrivateRouteContract(Media $media, string $routeName, array $parameters, string $label): array
    {
        $owner = $media->uploaded_by
            ?? MediaConfiguration::string(
                'media.assets.private_owner_fallback',
                'system',
            );
        $url = $this->urlResolver->privateUrl($media, $parameters, now()->addMinutes(5), $owner);
        $expectedPath = $this->pathFromUrl(route($routeName, ['owner' => $owner, 'media' => $media->id], false));
        $actualPath = $this->pathFromUrl($url);
        $failures = [];

        if ($actualPath !== $expectedPath) {
            $failures[] = "Private media [{$media->id}]{$label} generated non-route URL [{$url}].";
        }

        $query = $this->queryFromUrl($url);
        if (! isset($query['signature'], $query['expires'])) {
            $failures[] = "Private media [{$media->id}]{$label} URL is missing signed route parameters.";
        }

        if (($parameters['v'] ?? null) !== null && ($query['v'] ?? null) !== $parameters['v']) {
            $failures[] = "Private media [{$media->id}]{$label} URL is missing expected variation query parameter.";
        }

        $request = Request::create($url, 'GET');
        if (! $request->hasValidSignature()) {
            $failures[] = "Private media [{$media->id}]{$label} generated an invalid signed URL.";
        }

        return [
            'checks' => 1,
            'failures' => $failures,
        ];
    }

    /**
     * Check whether an object exists while preserving storage exceptions for reporting.
     *
     * @return array{exists: bool, error: string|null}
     */
    private function checkObjectExists(string $disk, string $path): array
    {
        try {
            return [
                'exists' => $this->existence->exists($disk, $path),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            $message = trim($exception->getMessage());

            return [
                'exists' => false,
                'error' => $message === ''
                    ? $exception::class
                    : $exception::class.': '.$message,
            ];
        }
    }

    /**
     * Build a storage failure message with optional exception detail.
     */
    private function storageFailureMessage(string $prefix, string $disk, string $path, ?string $error): string
    {
        $message = "{$prefix} at [{$disk}:{$path}].";

        if ($error === null) {
            return $message;
        }

        return "{$message} Storage error: {$error}.";
    }

    /**
     * Build the base media query, optionally including soft-deleted records.
     *
     * @return Builder<Media>
     */
    private function baseMediaQuery(bool $includeTrashed): Builder
    {
        if ($includeTrashed) {
            return Media::withTrashed();
        }

        return Media::query();
    }

    /**
     * Extract the URL path from a generated URL.
     */
    private function pathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? $path : $url;
    }

    /**
     * Extract query parameters from a generated URL.
     *
     * @return array<string, mixed>
     */
    private function queryFromUrl(string $url): array
    {
        $queryString = parse_url($url, PHP_URL_QUERY);

        if (! is_string($queryString) || $queryString === '') {
            return [];
        }

        parse_str($queryString, $query);

        $normalized = [];

        foreach ($query as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Inventory or explicitly clean unreferenced objects beneath media.root_folder.
     *
     * @return array{
     *   candidates: int,
     *   protected: int,
     *   deleted: int,
     *   failures: list<string>,
     *   warnings: list<string>
     * }
     */
    private function inventoryOrphans(
        string $disk,
        int $olderThanMinutes,
        bool $cleanup,
    ): array {
        $root = trim(MediaConfiguration::string('media.root_folder', 'media'), '/');
        $restrictToRoot = (bool) config('media.reconciliation.restrict_to_root', true);
        $report = [
            'candidates' => 0,
            'protected' => 0,
            'deleted' => 0,
            'failures' => [],
            'warnings' => [],
        ];

        if ($restrictToRoot && $root === '') {
            $report['failures'][] = 'Orphan reconciliation requires a non-empty media.root_folder.';

            return $report;
        }

        [$livePaths, $tombstonePaths] = $this->referencedObjectPaths($disk);
        $cutoff = now()->subMinutes($olderThanMinutes)->getTimestamp();
        $pageSize = MediaConfiguration::integer(
            'media.reconciliation.page_size',
            500,
            1,
        );
        $page = [];
        $pageNumber = 1;

        try {
            foreach ($this->disks->objects($disk, $root) as $object) {
                $path = $object['path'];

                if (isset($livePaths[$path])) {
                    continue;
                }

                $reason = isset($tombstonePaths[$path])
                    ? 'soft-deleted media'
                    : 'unreferenced';
                $lastModified = $object['last_modified'];
                $eligible = is_int($lastModified) && $lastModified <= $cutoff;
                $page[] = [
                    'path' => $path,
                    'reason' => $reason,
                    'age' => is_int($lastModified)
                        ? max(0, (int) floor((time() - $lastModified) / 60)).'m'
                        : 'unknown',
                    'eligible' => $eligible ? 'yes' : 'no',
                ];
                $report['candidates']++;

                if (! $eligible) {
                    $report['protected']++;
                } elseif ($cleanup) {
                    try {
                        if ($this->files->delete($disk, $path)) {
                            $report['deleted']++;
                        } else {
                            $report['failures'][] = "Failed to delete orphan [{$disk}:{$path}].";
                        }
                    } catch (Throwable $exception) {
                        $report['failures'][] = "Failed to delete orphan [{$disk}:{$path}]: {$exception->getMessage()}";
                    }
                }

                if (count($page) >= $pageSize) {
                    $this->printOrphanPage($page, $pageNumber++);
                    $page = [];
                }
            }
        } catch (Throwable $exception) {
            $report['failures'][] = 'Orphan inventory failed: '.$exception->getMessage();
        }

        if ($page !== []) {
            $this->printOrphanPage($page, $pageNumber);
        }

        if ($report['protected'] > 0) {
            $report['warnings'][] = $cleanup
                ? 'Recent or age-unknown orphan candidates were retained.'
                : 'Recent or age-unknown candidates are protected from cleanup.';
        }

        return $report;
    }

    /**
     * @return array{array<string, true>, array<string, true>}
     */
    private function referencedObjectPaths(string $disk): array
    {
        $live = [];
        $tombstones = [];

        Media::query()
            ->withTrashed()
            ->where('disk', $disk)
            ->with('imageVariations')
            ->chunkById(500, function ($mediaItems) use (&$live, &$tombstones): void {
                foreach ($mediaItems as $media) {
                    /** @var Media $media */
                    $target = $media->trashed() ? $tombstones : $live;
                    $target[$media->buildPath()] = true;

                    foreach ($media->imageVariations as $variation) {
                        /** @var MediaImageVariation $variation */
                        $target[$variation->getPath()] = true;
                    }

                    if ($media->trashed()) {
                        $tombstones = $target;
                    } else {
                        $live = $target;
                    }
                }
            });

        return [$live, $tombstones];
    }

    /**
     * @param  list<array{path: string, reason: string, age: string, eligible: string}>  $page
     */
    private function printOrphanPage(array $page, int $pageNumber): void
    {
        $this->newLine();
        $this->info("Orphan inventory page {$pageNumber}");
        $this->table(['Path', 'Reason', 'Age', 'Cleanup eligible'], $page);
    }

    /**
     * Print warnings and failures consistently.
     *
     * @param  list<string>  $warnings
     * @param  list<string>  $failures
     */
    private function printIssues(array $warnings, array $failures): void
    {
        foreach ($warnings as $warning) {
            $this->warn("WARN: {$warning}");
        }

        foreach ($failures as $failure) {
            $this->error("FAIL: {$failure}");
        }
    }
}
