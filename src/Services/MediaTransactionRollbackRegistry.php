<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Tracks transaction rollback callbacks behind Laravel's stable connection event.
 *
 * The provider owns one listener for this singleton registry, while scoped Media services
 * register callbacks that are removed as soon as their root transaction finishes.
 *
 * @internal
 */
final class MediaTransactionRollbackRegistry
{
    /**
     * @var array<int, array{
     *     connection: string,
     *     root_level: int,
     *     origin: ?DatabaseTransactionRecord,
     *     callback: Closure(): void,
     * }>
     */
    private array $callbacks = [];

    private int $nextRegistrationId = 0;

    /**
     * Resolve the transaction manager used by the connection event listener.
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Register root rollback work, optionally cancelled with its originating savepoint.
     *
     * @param  Closure(): void  $callback
     */
    public function register(
        string $connection,
        int $rootLevel,
        Closure $callback,
        ?DatabaseTransactionRecord $origin = null,
    ): int {
        $registrationId = ++$this->nextRegistrationId;
        $this->callbacks[$registrationId] = [
            'connection' => $connection,
            'root_level' => $rootLevel,
            'origin' => $origin,
            'callback' => $callback,
        ];

        return $registrationId;
    }

    /**
     * Run a callback once when the Media connection's open root transaction commits or rolls back.
     *
     * @param  Closure(): void  $callback
     * @return bool False when no such transaction is open, so the caller runs the work itself
     */
    public function afterRootTransaction(Closure $callback): bool
    {
        return $this->afterRootTransactionOutcome($callback, $callback);
    }

    /**
     * Run distinct commit or rollback callbacks when the Media connection's open root transaction finishes.
     *
     * @param  Closure(): void  $onCommit
     * @param  Closure(): void  $onRollback
     * @return bool False when no callback-applicable transaction is open on the Media connection
     */
    public function afterRootTransactionOutcome(Closure $onCommit, Closure $onRollback): bool
    {
        $connection = MediaModelRegistry::newInstance()->getConnection();

        if ($connection->transactionLevel() === 0) {
            return false;
        }

        $root = $this->container->make('db.transactions')
            ->callbackApplicableTransactions()
            ->filter(static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection->getName())
            ->sortBy('level')
            ->first();

        if (! $root instanceof DatabaseTransactionRecord) {
            return false;
        }

        $registrationId = $this->register($root->connection, $root->level, $onRollback);
        $root->addCallback(function () use ($registrationId, $onCommit): void {
            $this->cancel($registrationId);
            $onCommit();
        });

        return true;
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
     * Execute callbacks whose root or originating transaction was rolled back.
     */
    public function handle(TransactionRolledBack $event): void
    {
        $transactionLevel = $event->connection->transactionLevel();
        $registrationIds = [];
        $transactions = $this->container->make('db.transactions');
        $remainingTransactions = $transactions->getPendingTransactions()
            ->merge($transactions->getCommittedTransactions());

        foreach ($this->callbacks as $registrationId => $registration) {
            if ($registration['connection'] === $event->connectionName
                && ($transactionLevel < $registration['root_level']
                    || ($registration['origin'] !== null
                        && ! $remainingTransactions->contains(
                            static fn (DatabaseTransactionRecord $transaction): bool => $transaction === $registration['origin'],
                        )))) {
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
