<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DatabaseInterface;

/**
 * Unit of Work with transaction support.
 *
 * Wraps database operations in a transaction. Domain events are
 * buffered during the transaction and dispatched only after a
 * successful commit. On failure, events are discarded and the
 * transaction is rolled back.
 * @api
 */
final class UnitOfWork
{
    /** @var array<int, array{0: object, 1: string}> Buffered events to dispatch after commit. */
    private array $bufferedEvents = [];

    /** @var list<\Closure(): void> */
    private array $afterCommit = [];

    private bool $inTransaction = false;

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Execute a callback within a database transaction.
     *
     * Domain events dispatched during the callback are buffered
     * and only dispatched after a successful commit.
     *
     * @template T
     * @param \Closure(): T $callback The work to execute.
     * @return T The callback's return value.
     * @throws \Throwable Re-throws any exception from the callback after rollback.
     */
    public function transaction(\Closure $callback): mixed
    {
        if ($this->inTransaction) {
            // Nested call: just run the callback without extra transaction wrapping.
            return $callback();
        }

        $this->inTransaction = true;
        $this->bufferedEvents = [];
        $this->afterCommit = [];

        $transaction = $this->database->transaction();

        try {
            $result = $callback();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->bufferedEvents = [];
            $this->afterCommit = [];
            $this->inTransaction = false;

            throw $e;
        }

        // Post-commit callbacks and events are deliberately outside the
        // rollback catch: committed data cannot be reinterpreted as rolled
        // back if an in-memory successor install or listener fails.
        $eventsToDispatch = $this->bufferedEvents;
        $afterCommit = $this->afterCommit;
        $this->bufferedEvents = [];
        $this->afterCommit = [];
        $this->inTransaction = false;

        foreach ($afterCommit as $afterCommitCallback) {
            $afterCommitCallback();
        }

        foreach ($eventsToDispatch as [$event, $eventName]) {
            $this->eventDispatcher->dispatch($event, $eventName);
        }

        return $result;
    }

    /**
     * Buffer a domain event for dispatch after commit.
     *
     * If not inside a transaction, the event is dispatched immediately.
     * Accepts any object — PSR-14 dispatchers route by class hierarchy, not
     * by a shared base class. (Widened from `Symfony\Contracts\EventDispatcher\Event`
     * to allow GitHub #1449's lifecycle events — {@see \Waaseyaa\EntityStorage\Event\BeforeSaveEvent}
     * / {@see \Waaseyaa\EntityStorage\Event\AfterSaveEvent} — which are
     * plain classes implementing only {@see \Waaseyaa\EntityStorage\Event\EntityLifecycleEventInterface}.)
     *
     * @param object $event The event object.
     * @param string $eventName The event name.
     */
    public function bufferEvent(object $event, string $eventName): void
    {
        if ($this->inTransaction) {
            $this->bufferedEvents[] = [$event, $eventName];
        } else {
            $this->eventDispatcher->dispatch($event, $eventName);
        }
    }

    /**
     * Whether we are currently inside a transaction.
     */
    public function isInTransaction(): bool
    {
        return $this->inTransaction;
    }

    /** Register an in-memory successor update that is valid only after commit. */
    public function afterCommit(\Closure $callback): void
    {
        if (!$this->inTransaction) {
            throw new \LogicException('afterCommit() requires an active unit-of-work transaction.');
        }
        $this->afterCommit[] = $callback;
    }
}
