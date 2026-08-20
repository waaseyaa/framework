<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;

/**
 * Holds the records a read-only schema plan produces until its outcome is known.
 *
 * The planning traversal stops at the first refused write, so every record it
 * produces is one the replayed apply traversal produces again. Buffering lets
 * `EntitySchemaSync` report each condition once per synchronization: from the
 * plan when no change is found, and from the apply traversal when one is. (#2452)
 *
 * @internal
 */
final class BufferedPlanLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{LogLevel, string|\Stringable, array<array-key, mixed>}> */
    private array $records = [];

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, $message, $context];
    }

    /** Emit everything buffered so far, then forget it. */
    public function replayTo(?LoggerInterface $logger): void
    {
        $records = $this->records;
        $this->records = [];
        foreach ($records as [$level, $message, $context]) {
            $logger?->log($level, $message, $context);
        }
    }
}
