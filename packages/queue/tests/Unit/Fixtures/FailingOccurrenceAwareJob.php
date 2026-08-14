<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Fixtures;

use Waaseyaa\Queue\Occurrence\OccurrenceAwareMessageInterface;
use Waaseyaa\Queue\Occurrence\OccurrenceContextInterface;

final class FailingOccurrenceAwareJob implements OccurrenceAwareMessageInterface
{
    public function handleOccurrence(OccurrenceContextInterface $context): void
    {
        throw new \RuntimeException('occurrence job failed');
    }
}
