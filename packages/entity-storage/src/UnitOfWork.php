<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Waaseyaa\Database\DatabaseInterface;

/**
 * Unit of Work with transaction support.
 *
 * Wraps database operations in a transaction. Domain events are
 * buffered during the transaction and dispatched only after a
 * successful commit. On failure, events are discarded and the
 * transaction is rolled back.
 */
final class UnitOfWork
{
    /** @var array<int, array{0: Event, 1: string}> Buffered events to dispatch after commit. */
    private array $bufferedEvents = [];

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

        $transaction = $this->database->transaction();

        try {
            $result = $callback();
            $transaction->commit();

            // Dispatch buffered events after successful commit.
            $eventsToDispatch = $this->bufferedEvents;
            $this->bufferedEvents = [];
            $this->inTransaction = false;

            foreach ($eventsToDispatch as [$event, $eventName]) {
                $this->eventDispatcher->dispatch($event, $eventName);
            }

            return $result;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->bufferedEvents = [];
            $this->inTransaction = false;

            throw $e;
        }
    }

    /**
     * Buffer a domain event for dispatch after commit.
     *
     * If not inside a transaction, the event is dispatched immediately.
     *
     * @param Event $event The event object.
     * @param string $eventName The event name.
     */
    public function bufferEvent(Event $event, string $eventName): void
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
}
