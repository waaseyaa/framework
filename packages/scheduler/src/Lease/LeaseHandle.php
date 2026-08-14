<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Lease;

/** @api */
final readonly class LeaseHandle
{
    public function __construct(
        public string $domain,
        public string $ownerToken,
        public int $fence,
        public int $renewalGeneration,
        public string $renewalNonce,
        public int $expiresAtMs,
    ) {}
}
