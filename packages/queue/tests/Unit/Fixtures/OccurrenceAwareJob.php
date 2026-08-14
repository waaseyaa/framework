<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Fixtures;

use Waaseyaa\Queue\Occurrence\OccurrenceAwareMessageInterface;
use Waaseyaa\Queue\Occurrence\OccurrenceContextInterface;

final class OccurrenceAwareJob implements OccurrenceAwareMessageInterface
{
    public static int $handleCount = 0;

    public function handleOccurrence(OccurrenceContextInterface $context): void
    {
        $context->checkpoint();
        $context->effect('queue-test-resource', 'write', static function (): void {
            ++self::$handleCount;
        });
    }
}
