<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

/**
 * One row of the `waaseyaa_migrations` ledger as a typed DTO.
 *
 * Returned by {@see MigrationRepository::allWithChecksums()} for the
 * verify CLI (WP10) and any other consumer that wants structured access
 * instead of the raw associative array.
 *
 * Pre-WP09 rows can have `checksum === null` and `diffHash === null`; strict
 * S1 verification refuses them. New v2 and legacy-procedural applies populate
 * both fields with their respective canonical identities.
 * @api
 */
final readonly class LedgerRow
{
    public function __construct(
        public string $migration,
        public string $package,
        public int $batch,
        public ?string $checksum,
        public ?string $diffHash,
    ) {}
}
