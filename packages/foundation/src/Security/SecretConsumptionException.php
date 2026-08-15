<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** @api Typed non-sensitive refusal for guarded same-process consumption. */
final class SecretConsumptionException extends \RuntimeException
{
    public function __construct(
        public readonly SecretConsumptionCode $reason,
        public readonly string $referenceFingerprint,
    ) {
        parent::__construct(sprintf(
            '[%s] Secret reference %s could not be consumed.',
            $reason->value,
            $referenceFingerprint,
        ));
    }
}
