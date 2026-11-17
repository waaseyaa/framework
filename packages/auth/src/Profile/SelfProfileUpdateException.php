<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Profile;

/** A public-safe refusal of a self-profile operation. @api */
final class SelfProfileUpdateException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status)
    {
        parent::__construct($reason);
    }
}
