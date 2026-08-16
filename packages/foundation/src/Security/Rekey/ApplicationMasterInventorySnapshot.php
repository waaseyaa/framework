<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** Immutable non-secret inventory commitment returned by one adapter. @api */
final readonly class ApplicationMasterInventorySnapshot
{
    public function __construct(
        public string $token,
        public int $totalRecords,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 || $totalRecords < 0) {
            throw new \InvalidArgumentException('Application-master inventory snapshots require a SHA-256 token and non-negative total.');
        }
    }
}
