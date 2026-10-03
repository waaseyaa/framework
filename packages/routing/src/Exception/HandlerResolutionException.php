<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Exception;

/** Non-secret execution diagnostic; contains no factory exception or service state. @api */
final class HandlerResolutionException extends \RuntimeException
{
    public function __construct(public readonly string $routeName, public readonly string $handlerId, public readonly string $reason)
    {
        parent::__construct(sprintf('Cannot resolve route "%s" handler "%s" (%s).', $routeName, $handlerId, $reason));
    }
}
