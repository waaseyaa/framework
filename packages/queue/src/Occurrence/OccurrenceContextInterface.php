<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Occurrence;

/** Queue-side view of a scheduler-owned renewable lease and effect fence. @api */
interface OccurrenceContextInterface
{
    public function occurrenceId(): string;

    public function fence(): int;

    public function checkpoint(): void;

    public function effect(string $resource, string $effectId, callable $effect): mixed;
}
