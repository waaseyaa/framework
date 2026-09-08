<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\ProjectInit;

final class InitialProjectConfigActivationException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $outcomeUncertain = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
