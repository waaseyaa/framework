<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

use Waaseyaa\Queue\Envelope\QueueOccurrenceV1;

/** Persistent dispatch boundary that preserves scheduler occurrence identity. @api */
interface OccurrenceQueueInterface extends QueueInterface
{
    public function dispatchOccurrence(object $message, QueueOccurrenceV1 $occurrence): void;
}
