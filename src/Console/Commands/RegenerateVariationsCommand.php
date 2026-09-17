<?php

declare(strict_types=1);

namespace Nvl\Media\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Nvl\Media\Actions\GenerateImageVariationAction;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Jobs\RegenerateMediaVariationsJob;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaConfiguredVariationService;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;
use Throwable;

/**
 * Artisan command for batch-regenerating image variations across media records.
 *
 * Supports filtering by type, disk, preset, and date range. Can run synchronously
 * for small batches or dispatch to the queue for large catalogs.
 */
class RegenerateVariationsCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:media:regenerate
        {--type=image : Media type filter (image, video, etc.)}
        {--disk= : Storage disk filter}
        {--preset=* : Specific preset names to regenerate (default: all enabled)}
        {--after= : Only process media created after this date (Y-m-d)}
        {--before= : Only process media created before this date (Y-m-d)}
        {--dry-run : Show what would be processed without acting}
        {--sync : Process inline instead of dispatching to queue}
        {--force : Skip confirmation prompt}
        {--tenant= : Regenerate media for one explicit tenant UUID}
        {--all-tenants : Regenerate media independently for every active tenant}
        {--actor-type=system : Platform operation actor type for --all-tenants}
        {--actor-id=media-regenerator : Platform operation actor identifier for --all-tenants}
        {--purpose=media.variations.regenerate : Platform operation purpose for --all-tenants}';

    /** @var string */
    protected $description = 'Regenerate image variations for media records matching the given filters.';

    public function __construct(
        private readonly GenerateImageVariationAction $generateVariationAction,
        private readonly MediaConfiguredVariationService $configuredVariationService,
        private readonly TenantRunner $tenantRunner,
        private readonly EffectiveTenantConnection $tenantConnections,
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int Exit code
     */
    public function handle(): int
    {
        if (config('tenancy.enabled') !== true) {
            return $this->handleBounded();
        }

        $tenant = $this->option('tenant');
        $tenantProvided = is_string($tenant) && $tenant !== '';
        $allTenants = (bool) $this->option('all-tenants');
        if ($tenantProvided === $allTenants) {
            $this->components->error('Tenant-aware regeneration requires exactly one of --tenant or --all-tenants.');

            return self::INVALID;
        }

        if ($tenantProvided) {
            return $this->tenantRunner->run(new TenantId($tenant), fn (): int => $this->handleBounded());
        }

        $tenantIds = $this->tenantRunner->platform(
            new PlatformOperation(
                (string) $this->option('purpose'),
                (string) $this->option('actor-type'),
                (string) $this->option('actor-id'),
            ),
            fn (): array => $this->activeTenantIds(),
        );
        $exitCode = self::SUCCESS;
        foreach ($tenantIds as $tenantId) {
            $this->components->info("Tenant {$tenantId}");
            $result = $this->tenantRunner->run(new TenantId($tenantId), fn (): int => $this->handleBounded());
            if ($result !== self::SUCCESS) {
                $exitCode = $result;
            }
        }

        return $exitCode;
    }

    /** Execute regeneration inside an already-established ownership boundary. */
    private function handleBounded(): int
    {
        $type = $this->resolveType();
        $disk = $this->option('disk');
        $presetNames = $this->resolvePresets();
        $after = $this->option('after');
        $before = $this->option('before');

        $count = $this->countAffected($type, is_string($disk) ? $disk : null, is_string($after) ? $after : null, is_string($before) ? $before : null);

        $presetLabel = empty($presetNames) ? 'all enabled presets' : implode(', ', $presetNames);
        $this->info("Found {$count} media records matching filters.");
        $this->info("Presets: {$presetLabel}");

        if ($count === 0) {
            $this->warn('No media records to process.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('[dry-run] Would regenerate variations for '.$count.' media records.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Regenerate variations for {$count} media records?")) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            return $this->processSync($type, is_string($disk) ? $disk : null, $presetNames, is_string($after) ? $after : null, is_string($before) ? $before : null);
        }

        return $this->processQueued($type, is_string($disk) ? $disk : null, $presetNames, is_string($after) ? $after : null, is_string($before) ? $before : null);
    }

    /**
     * Read the package tenant directory as a bounded worklist for this command.
     *
     * @return list<string>
     */
    private function activeTenantIds(): array
    {
        $connection = $this->tenantConnections->core();

        $tenantIds = $connection->table('nvl_tenancy_tenants')
            ->where('status', TenantStatus::Active->value)
            ->orderBy('id')
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        return array_values($tenantIds);
    }

    /**
     * Process regeneration synchronously for small batches.
     *
     * @param  MediaType|null  $type  Media type filter
     * @param  string|null  $disk  Disk filter
     * @param  list<string>  $presetNames  Preset filters
     * @param  string|null  $after  Created-after filter
     * @param  string|null  $before  Created-before filter
     */
    private function processSync(?MediaType $type, ?string $disk, array $presetNames, ?string $after, ?string $before): int
    {
        $presets = $this->resolvePresetConfigs($presetNames);

        if (empty($presets)) {
            $this->error('No valid presets found.');

            return self::FAILURE;
        }

        $query = $this->buildQuery($type, $disk, $after, $before);
        $processed = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($query->count());
        $bar->start();

        $query->chunkById(100, function ($chunk) use ($presets, &$processed, &$errors, $bar): void {
            foreach ($chunk as $media) {
                /** @var Media $media */
                if (! $media->type->supportsConversions()) {
                    $bar->advance();

                    continue;
                }

                foreach ($presets as $name => $config) {
                    $definition = ConversionDefinition::fromPreset((string) $name, $config);

                    try {
                        $this->generateVariationAction->execute($media, $definition);
                    } catch (Throwable $e) {
                        $errors++;
                        $this->newLine();
                        $this->warn("  Failed [{$name}] for media [{$media->id}]: {$e->getMessage()}");
                    }
                }

                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Processed {$processed} media records. Errors: {$errors}.");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Dispatch regeneration to the queue for large batches.
     *
     * @param  MediaType|null  $type  Media type filter
     * @param  string|null  $disk  Disk filter
     * @param  list<string>  $presetNames  Preset filters
     * @param  string|null  $after  Created-after filter
     * @param  string|null  $before  Created-before filter
     */
    private function processQueued(?MediaType $type, ?string $disk, array $presetNames, ?string $after, ?string $before): int
    {
        $job = new RegenerateMediaVariationsJob(
            type: $type,
            disk: $disk,
            createdAfter: $after,
            createdBefore: $before,
            presetNames: ! empty($presetNames) ? $presetNames : null,
            envelope: TenantJobEnvelope::capture($this->tenantContext),
        );

        Bus::dispatch($job);

        $this->info('Regeneration job dispatched to queue.');

        return self::SUCCESS;
    }

    /**
     * Resolve the media type from the --type option.
     */
    private function resolveType(): MediaType
    {
        $typeValue = $this->option('type');

        if (! is_string($typeValue)) {
            return MediaType::IMAGE;
        }

        return MediaType::tryFrom($typeValue) ?? MediaType::IMAGE;
    }

    /**
     * Resolve preset names from the --preset option.
     *
     * @return list<string>
     */
    private function resolvePresets(): array
    {
        return $this->normalizePresetOption($this->option('preset'));
    }

    /**
     * Normalize Laravel's cross-version command option return type.
     *
     * @return list<string>
     */
    private function normalizePresetOption(mixed $presets): array
    {
        if (! is_array($presets)) {
            return [];
        }

        return array_values(array_filter($presets, static fn (mixed $v): bool => is_string($v) && $v !== ''));
    }

    /**
     * Resolve preset configurations from config, optionally filtered by names.
     *
     * @param  list<string>  $names  Preset names to include (empty = all enabled)
     * @return array<string, array<string, mixed>>
     */
    private function resolvePresetConfigs(array $names): array
    {
        return $this->configuredVariationService->presetConfigs(
            names: ! empty($names) ? $names : null,
            enabledOnly: empty($names),
        );
    }

    /**
     * Count matching media records for the given filters.
     *
     * @param  MediaType|null  $type  Media type filter
     * @param  string|null  $disk  Disk filter
     * @param  string|null  $after  Created-after filter
     * @param  string|null  $before  Created-before filter
     */
    private function countAffected(?MediaType $type, ?string $disk, ?string $after, ?string $before): int
    {
        return $this->buildQuery($type, $disk, $after, $before)->count();
    }

    /**
     * Build the filtered query for matching media records.
     *
     * @param  MediaType|null  $type  Media type filter
     * @param  string|null  $disk  Disk filter
     * @param  string|null  $after  Created-after filter
     * @param  string|null  $before  Created-before filter
     * @return Builder<Media>
     */
    private function buildQuery(?MediaType $type, ?string $disk, ?string $after, ?string $before): Builder
    {
        $query = Media::query()->available();

        if ($type !== null) {
            $query->where('type', $type->value);
        } else {
            $query->where('type', MediaType::IMAGE->value);
        }

        if ($disk !== null) {
            $query->where('disk', $disk);
        }

        if ($after !== null) {
            $query->where('created_at', '>=', $after);
        }

        if ($before !== null) {
            $query->where('created_at', '<=', $before);
        }

        return $query->orderBy('id');
    }
}
