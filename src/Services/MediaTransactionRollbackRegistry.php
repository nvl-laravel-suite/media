<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Tracks root-transaction rollback callbacks behind Laravel's stable connection event.
 *
 * The provider owns one listener for this singleton registry, while scoped Media services
 * register callbacks that are removed as soon as their root transaction finishes.
 */
final class MediaTransactionRollbackRegistry
{
    /**
     * @var array<int, array{
     *     connection: string,
     *     root_level: int,
     *     callback: Closure(): void,
     * }>
     */
    private array $callbacks = [];

    private int $nextRegistrationId = 0;

    /**
     * Register a callback for rollback below its owning root transaction level.
     *
     * @param  Closure(): void  $callback
     */
    public function register(string $connection, int $rootLevel, Closure $callback): int
    {
        $registrationId = ++$this->nextRegistrationId;
        $this->callbacks[$registrationId] = [
            'connection' => $connection,
            'root_level' => $rootLevel,
            'callback' => $callback,
        ];

        return $registrationId;
    }

    /**
     * Cancel a callback whose root transaction committed.
     */
    public function cancel(int $registrationId): void
    {
        unset($this->callbacks[$registrationId]);
    }

    /**
     * Return the number of callbacks awaiting a root transaction outcome.
     */
    public function count(): int
    {
        return count($this->callbacks);
    }

    /**
     * Execute callbacks whose owning root transaction was rolled back.
     */
    public function handle(TransactionRolledBack $event): void
    {
        $transactionLevel = $event->connection->transactionLevel();
        $registrationIds = [];

        foreach ($this->callbacks as $registrationId => $registration) {
            if ($registration['connection'] === $event->connectionName
                && $transactionLevel < $registration['root_level']) {
                $registrationIds[] = $registrationId;
            }
        }

        foreach ($registrationIds as $registrationId) {
            $this->execute($registrationId);
        }
    }

    /**
     * Remove and execute one registered callback exactly once.
     */
    private function execute(int $registrationId): void
    {
        $registration = $this->callbacks[$registrationId] ?? null;

        if ($registration === null) {
            return;
        }

        unset($this->callbacks[$registrationId]);

        ($registration['callback'])();
    }
}
