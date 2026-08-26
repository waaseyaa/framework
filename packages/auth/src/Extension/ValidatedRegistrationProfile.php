<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Application-validated profile payload; never persisted on Framework User. @api */
final readonly class ValidatedRegistrationProfile
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values) {}
}
