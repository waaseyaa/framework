<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Occurrence;

use Waaseyaa\Queue\Occurrence\OccurrenceContextInterface;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;

final readonly class QueueOccurrenceExecutionContext implements OccurrenceContextInterface
{
    public function __construct(private LeaseExecutionContext $context) {}

    public function occurrenceId(): string
    {
        return $this->context->occurrenceId();
    }

    public function fence(): int
    {
        return $this->context->fence();
    }

    public function checkpoint(): void
    {
        $this->context->checkpoint();
    }

    public function effect(string $resource, string $effectId, callable $effect): mixed
    {
        return $this->context->effect($resource, $effectId, \Closure::fromCallable($effect));
    }
}
