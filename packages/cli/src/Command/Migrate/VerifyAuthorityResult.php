<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Migrate;

/** Strict comparison of stored schema authority with current read-only state. */
final readonly class VerifyAuthorityResult
{
    public function __construct(
        public string $status,
        public ?string $storedSchemaFingerprint,
        public string $computedSchemaFingerprint,
        public ?string $storedLedgerFingerprint,
        public string $computedLedgerFingerprint,
        public ?string $storedSourceCatalogFingerprint,
        public string $computedSourceCatalogFingerprint,
    ) {}

    public function isMatch(): bool
    {
        return $this->status === 'match';
    }
}
