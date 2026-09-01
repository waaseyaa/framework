<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Exception\TransactionCompletionException;
use Waaseyaa\Database\TransactionCompletionInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

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

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly EventDispatcherInterface $eventDispatcher,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

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

        $transaction = $this->database->transaction();
        if (!$transaction instanceof TransactionCompletionInterface) {
            $transaction->rollBack();
            throw new \LogicException(sprintf(
                '%s requires a transaction that implements %s so notifications cannot escape an outer rollback.',
                self::class,
                TransactionCompletionInterface::class,
            ));
        }

        $this->inTransaction = true;
        $this->bufferedEvents = [];
        $this->afterCommit = [];

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->reset();

            throw $e;
        }

        $eventsToDispatch = $this->bufferedEvents;
        $afterCommit = $this->afterCommit;
        $this->reset();
        $transaction->afterCommit(function () use ($afterCommit, $eventsToDispatch): void {
            /** @var \SplQueue<\Throwable> $completionFailures */
            $completionFailures = new \SplQueue();
            foreach ($afterCommit as $afterCommitCallback) {
                $failure = $this->invokeRequiredCompletionCallback($afterCommitCallback);
                if ($failure !== null) {
                    $this->reportCompletionFailure('after_commit_callback', $failure);
                    $completionFailures->enqueue($failure);
                }
            }

            foreach ($eventsToDispatch as [$event, $eventName]) {
                try {
                    $this->eventDispatcher->dispatch($event, $eventName);
                } catch (\Throwable $failure) {
                    $this->reportCompletionFailure('buffered_event', $failure, $event);
                    $completionFailures->enqueue($failure);
                }
            }

            if (!$completionFailures->isEmpty()) {
                /** @var non-empty-list<\Throwable> $failures */
                $failures = iterator_to_array($completionFailures, preserve_keys: false);
                throw new TransactionCompletionException($failures);
            }
        });

        try {
            $transaction->commit();
        } catch (TransactionCompletionException $failure) {
            // The database is committed. Never report a fictional rollback or
            // attempt to roll back a transaction that has already completed.
            throw $failure;
        } catch (\Throwable $failure) {
            $transaction->rollBack();
            throw $failure;
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

    private function reset(): void
    {
        $this->bufferedEvents = [];
        $this->afterCommit = [];
        $this->inTransaction = false;
    }

    private function invokeRequiredCompletionCallback(\Closure $callback): ?\Throwable
    {
        try {
            $this->callCompletionCallback($callback);

            return null;
        } catch (\Throwable $failure) {
            return $failure;
        }
    }

    /** @throws \Throwable A consumer callback may fail at runtime. */
    private function callCompletionCallback(\Closure $callback): void
    {
        $callback();
    }

    private function reportCompletionFailure(
        string $phase,
        \Throwable $failure,
        ?object $event = null,
    ): void {
        $context = [
            'phase' => $phase,
            'failure_class' => $this->safeClassName($failure),
        ];
        if ($event !== null) {
            $context['event_class'] = $this->safeClassName($event);
        }

        try {
            $this->logger->error('entity_storage.post_commit_effect_failed', $context);
        } catch (\Throwable $loggingFailure) {
            // Preserve continuation even when a non-conforming logger throws.
            // The fallback is bounded to class names and contains no event
            // payload, exception message, path, or credential-bearing context.
            error_log(sprintf(
                'entity_storage.post_commit_effect_failed phase=%s failure=%s logger_failure=%s',
                $phase,
                $this->safeClassName($failure),
                $this->safeClassName($loggingFailure),
            ));
        }
    }

    private function safeClassName(object $value): string
    {
        $class = $value::class;

        return str_contains($class, '@anonymous') ? 'anonymous' : substr($class, 0, 200);
    }
}
