<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseTransactionRecord;
use Nvl\Support\Logging\PackageLogger;
use Throwable;

/**
 * Schedules idempotent media file effects against the real root transaction outcome.
 *
 * This service deliberately does not open transactions. Actions own the transaction
 * boundary and use this capability to align external storage effects with its outcome.
 *
 * @internal
 */
final class MediaFileEffectScheduler
{
    /**
     * Create the file-effect scheduler.
     */
    public function __construct(
        private readonly MediaFileOperator $files,
        private readonly Container $container,
        private readonly MediaTransactionRollbackRegistry $rollbackCallbacks,
        private PackageLogger $packageLogger,
    ) {}

    /**
     * Delete object paths only after the root database transaction commits.
     *
     * @param  list<string>  $paths
     */
    public function deleteAfterCommit(string $disk, array $paths, string $operation): void
    {
        $this->afterRootCommit(
            fn (): bool => $this->deletePaths($disk, $paths, $operation, 'commit'),
        );
    }

    /**
     * Delete staged object paths when their savepoint or root transaction rolls back.
     *
     * @param  list<string>  $paths
     */
    public function deleteAfterRollback(string $disk, array $paths, string $operation): void
    {
        $this->afterRootRollback(
            fn (): bool => $this->deletePaths($disk, $paths, $operation, 'rollback'),
        );
    }

    /**
     * Execute a non-file side effect only after the root transaction commits.
     *
     * @param  Closure(): void  $callback
     */
    public function afterCommit(Closure $callback): void
    {
        $this->afterRootCommit($callback);
    }

    /**
     * Delete staged object paths immediately after a pre-commit failure.
     *
     * @param  list<string>  $paths
     */
    public function deleteNow(string $disk, array $paths, string $operation): void
    {
        $this->deletePaths($disk, $paths, $operation, 'pre_commit_failure');
    }

    /**
     * Keep real root commit timing while cancelling rolled-back savepoint effects.
     */
    private function afterRootCommit(Closure $callback): void
    {
        $root = $this->transactionRecord();

        if ($root instanceof DatabaseTransactionRecord) {
            $rolledBack = false;
            $registrationId = $this->rollbackCallbacks->register(
                connection: $root->connection,
                rootLevel: $root->level,
                callback: static function () use (&$rolledBack): void {
                    $rolledBack = true;
                },
                origin: $this->transactionRecord(latest: true),
            );
            $root->addCallback(function () use ($callback, $registrationId, &$rolledBack): void {
                $this->rollbackCallbacks->cancel($registrationId);

                if (! $rolledBack) {
                    $callback();
                }
            });

            return;
        }

        $callback();
    }

    /**
     * Keep staged objects recoverable until their savepoint and root both commit.
     */
    private function afterRootRollback(Closure $callback): void
    {
        $root = $this->transactionRecord();

        if ($root instanceof DatabaseTransactionRecord) {
            $registrationId = $this->rollbackCallbacks->register(
                connection: $root->connection,
                rootLevel: $root->level,
                callback: function () use ($callback): void {
                    $callback();
                },
                origin: $this->transactionRecord(latest: true),
            );
            $root->addCallback(
                fn (): null => $this->rollbackCallbacks->cancel($registrationId),
            );
        }
    }

    private function transactionRecord(bool $latest = false): ?DatabaseTransactionRecord
    {
        $connection = MediaModelRegistry::newInstance()->getConnection();

        if ($connection->transactionLevel() === 0) {
            return null;
        }

        $transactions = $this->container->make('db.transactions');

        $root = $transactions
            ->callbackApplicableTransactions()
            ->filter(
                static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection->getName(),
            )
            ->sortBy('level', descending: $latest)
            ->first();

        return $root instanceof DatabaseTransactionRecord ? $root : null;
    }

    /**
     * Delete paths without allowing cleanup failures to invalidate committed database state.
     *
     * @param  list<string>  $paths
     */
    private function deletePaths(
        string $disk,
        array $paths,
        string $operation,
        string $transactionOutcome,
    ): bool {
        $successful = true;

        foreach (array_values(array_unique($paths)) as $path) {
            try {
                if ($this->files->delete($disk, $path)) {
                    continue;
                }

                $successful = false;
                $this->packageLogger->log('media', 'warning', 'nvl.media.storage_cleanup.rejected', [
                    'disk' => $disk,
                    'path' => $path,
                    'operation' => $operation,
                    'transaction_outcome' => $transactionOutcome,
                ], 'normal');
            } catch (Throwable $exception) {
                $successful = false;
                $this->packageLogger->log('media', 'error', 'nvl.media.storage_cleanup.failed', [
                    'disk' => $disk,
                    'path' => $path,
                    'operation' => $operation,
                    'transaction_outcome' => $transactionOutcome,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ], 'normal');
            }
        }

        return $successful;
    }
}
