<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

final readonly class CapabilityDeclaration
{
    public function __construct(
        public string $id,
        public int $version,
        public string $authorityFingerprint,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]+$/D', $id) !== 1 || $version < 1 || $authorityFingerprint === '') {
            throw new \InvalidArgumentException('Capability declarations require a stable id, positive version, and authority fingerprint.');
        }
    }
}
