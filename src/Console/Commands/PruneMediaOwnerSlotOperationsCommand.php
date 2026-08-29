<?php

declare(strict_types=1);

namespace Nvl\Media\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Media\Services\MediaOwnerSlotIdempotency;
use Nvl\Media\Support\MediaConfiguration;

/**
 * Prunes expired terminal owner-slot idempotency operations in bounded chunks.
 */
final class PruneMediaOwnerSlotOperationsCommand extends Command
{
    protected $signature = 'nvl:media:owner-slots:prune
        {--days= : Terminal-operation retention in days}
        {--chunk= : Maximum rows deleted per database chunk}
        {--force : Allow a shorter-than-configured retention window in production}';

    protected $description = 'Prune expired terminal Media owner-slot operations';

    public function __construct(
        private readonly MediaOwnerSlotIdempotency $idempotency,
    ) {
        parent::__construct();
    }

    /**
     * Execute bounded terminal-operation pruning.
     */
    public function handle(): int
    {
        $configuredDays = MediaConfiguration::integer(
            'media.owner_slots.idempotency.retention_days',
            7,
            1,
        );
        $configuredChunk = MediaConfiguration::integer(
            'media.owner_slots.idempotency.prune_chunk',
            500,
            1,
        );
        $days = $this->positiveOption('days', $configuredDays, 36_500);
        $chunk = $this->positiveOption('chunk', $configuredChunk, 1_000);

        if ($days === null) {
            $this->components->error('--days must be between 1 and 36500.');

            return self::FAILURE;
        }

        if ($chunk === null) {
            $this->components->error('--chunk must be between 1 and 1000.');

            return self::FAILURE;
        }

        if ($this->getLaravel()->environment('production')
            && $days < $configuredDays
            && ! (bool) $this->option('force')) {
            $this->components->error(
                '--force is required to prune inside the configured retention window in production.',
            );

            return self::FAILURE;
        }

        $deleted = $this->idempotency->prune($days, $chunk);
        $this->components->info("Pruned owner-slot operations: {$deleted}.");

        return self::SUCCESS;
    }

    /**
     * Normalize one positive bounded integer option.
     */
    private function positiveOption(
        string $name,
        int $default,
        int $maximum,
    ): ?int {
        $value = $this->option($name);

        if ($value === null) {
            $value = $default;
        }

        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($normalized)
            && $normalized >= 1
            && $normalized <= $maximum
                ? $normalized
                : null;
    }
}
