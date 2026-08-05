<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Schedules idempotent media file effects against the real root transaction outcome.
 *
 * This service deliberately does not open transactions. Actions own the transaction
 * boundary and use this capability to align external storage effects with its outcome.
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
     * Delete staged object paths only when the root database transaction rolls back.
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
     * Register against the root record so Laravel 12 also runs the callback only
     * after the real outer commit.
     */
    private function afterRootCommit(Closure $callback): void
    {
        $root = $this->rootTransaction();

        if ($root instanceof DatabaseTransactionRecord) {
            $root->addCallback($callback);

            return;
        }

        $callback();
    }

    /**
     * Register against the root record so a committed nested savepoint remains
     * recoverable when Laravel 12 later rolls the outer transaction back.
     */
    private function afterRootRollback(Closure $callback): void
    {
        $root = $this->rootTransaction();

        if ($root instanceof DatabaseTransactionRecord) {
            $registrationId = $this->rollbackCallbacks->register(
                connection: $root->connection,
                rootLevel: $root->level,
                callback: function () use ($callback): void {
                    $callback();
                },
            );
            $root->addCallback(
                fn (): null => $this->rollbackCallbacks->cancel($registrationId),
            );
        }
    }

    private function rootTransaction(): ?DatabaseTransactionRecord
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() === 0) {
            return null;
        }

        $transactions = $this->container->make('db.transactions');

        $root = $transactions
            ->callbackApplicableTransactions()
            ->filter(
                static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection->getName(),
            )
            ->sortBy('level')
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
                Log::warning('Media storage cleanup reported failure.', [
                    'disk' => $disk,
                    'path' => $path,
                    'operation' => $operation,
                    'transaction_outcome' => $transactionOutcome,
                ]);
            } catch (Throwable $exception) {
                $successful = false;
                Log::error('Media storage cleanup threw an exception.', [
                    'disk' => $disk,
                    'path' => $path,
                    'operation' => $operation,
                    'transaction_outcome' => $transactionOutcome,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $successful;
    }
}
