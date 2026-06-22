<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Binary;

/**
 * Result of an {@see Installer::install()} run.
 *
 * @api
 */
final readonly class InstallOutcome
{
    public function __construct(
        /** Absolute path to the installed binary. */
        public string $path,
        /** True when an existing binary was kept (idempotent no-op). */
        public bool $skipped,
        /** True when the download's sha256 was verified against the GitHub release digest. */
        public bool $checksumVerified,
    ) {}
}
