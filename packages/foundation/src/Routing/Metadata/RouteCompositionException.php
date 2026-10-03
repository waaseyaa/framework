<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Routing\Metadata;

/** Safe, stable readiness refusal. Execution exceptions are never attached. @api */
final class RouteCompositionException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
