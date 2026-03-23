<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final class MaxIterationsException extends \RuntimeException
{
    public function __construct(int $maxIterations)
    {
        parent::__construct("Agent tool loop exceeded maximum iterations ({$maxIterations}).");
    }
}
