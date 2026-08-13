<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract;

/** @api */
final readonly class FrameworkIdentity
{
    public function __construct(
        public string $revisionPolicy,
        public string $observedLockSha256,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'revision_policy' => $this->revisionPolicy,
            'observed_lock_sha256' => $this->observedLockSha256,
        ];
    }
}
