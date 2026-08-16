<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Occurrence;

use Waaseyaa\Queue\Occurrence\OccurrenceAwareMessageInterface;
use Waaseyaa\Queue\OccurrenceQueueInterface;

final readonly class OccurrenceOutboxDispatcher
{
    public function __construct(
        private OccurrenceOutboxRepository $outbox,
        private OccurrenceQueueInterface $queue,
        private int $maxAttempts = 5,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Occurrence outbox max attempts must be positive.');
        }
    }

    public function dispatchPending(int $limit = 100): int
    {
        $dispatched = 0;
        foreach ($this->outbox->pending($limit) as $entry) {
            if ($this->dispatchEntry($entry)) {
                ++$dispatched;
            }
        }

        return $dispatched;
    }

    public function dispatchOccurrence(string $occurrenceId): OccurrenceDispatchResult
    {
        $entry = $this->outbox->pending(1, $occurrenceId)[0] ?? null;
        if ($entry === null) {
            return $this->outbox->state($occurrenceId) === 'dispatched'
                ? OccurrenceDispatchResult::AlreadyDispatched
                : OccurrenceDispatchResult::Failed;
        }

        return $this->dispatchEntry($entry)
            ? OccurrenceDispatchResult::Dispatched
            : OccurrenceDispatchResult::Failed;
    }

    private function dispatchEntry(OccurrenceOutboxEntry $entry): bool
    {
        try {
            $message = new ($entry->messageClass)();
            if (!$message instanceof OccurrenceAwareMessageInterface) {
                throw new \RuntimeException('Occurrence outbox message no longer implements its reviewed contract.');
            }
            $this->queue->dispatchOccurrence($message, $entry->queueOccurrence());
            $this->outbox->markDispatched($entry->occurrenceId);

            return true;
        } catch (\Throwable $error) {
            $this->outbox->markFailed($entry->occurrenceId, $error::class, $this->maxAttempts);

            return false;
        }
    }
}
