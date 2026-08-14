<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Occurrence;

/** A queued scheduler command that cooperates with renewal and fenced effects. @api */
interface OccurrenceAwareMessageInterface
{
    public function handleOccurrence(OccurrenceContextInterface $context): void;
}
