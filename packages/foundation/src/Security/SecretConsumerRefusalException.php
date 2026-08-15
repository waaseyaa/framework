<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** Internal non-sensitive signal translated at the guarded reference boundary. */
final class SecretConsumerRefusalException extends \RuntimeException
{
    public function __construct(public readonly SecretConsumptionCode $reason)
    {
        parent::__construct('A governed secret consumer refused the operation.');
    }
}
