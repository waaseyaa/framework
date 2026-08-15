<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** Per-purpose post-transition count and non-secret verification commitment. @api */
final readonly class ApplicationMasterPurposeVerification
{
    public function __construct(
        public int $verifiedRecords,
        public string $verificationHash,
    ) {
        if ($verifiedRecords < 0 || preg_match('/^[a-f0-9]{64}$/D', $verificationHash) !== 1) {
            throw new \InvalidArgumentException('Application-master verification requires a non-negative count and SHA-256 commitment.');
        }
    }
}
