<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Migrate;

/**
 * Per-migration outcome of {@see VerifyRunner::verify()}.
 *
 * Status is `match`, `mismatch`, `unknown`, `orphan`, `package_mismatch`, or
 * `plan_mismatch`. Both checksum strings may be null for unknown/orphan.
 */
final readonly class VerifyResultRow
{
    public function __construct(
        public string $migration,
        public string $status,
        public ?string $storedChecksum,
        public ?string $computedChecksum,
    ) {}
}
