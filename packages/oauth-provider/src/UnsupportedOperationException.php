<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final class UnsupportedOperationException extends \LogicException
{
    public function __construct(string $provider, string $operation)
    {
        parent::__construct(sprintf('Provider "%s" does not support %s.', $provider, $operation));
    }
}
