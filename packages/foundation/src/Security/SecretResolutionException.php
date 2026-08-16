<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** @api Typed non-sensitive refusal for governed secret resolution. */
final class SecretResolutionException extends \RuntimeException
{
    public function __construct(
        public readonly SecretResolutionCode $reason,
        public readonly string $referenceFingerprint,
    ) {
        parent::__construct(sprintf(
            '[%s] Secret reference %s could not be resolved.',
            $reason->value,
            $referenceFingerprint,
        ));
    }
}
