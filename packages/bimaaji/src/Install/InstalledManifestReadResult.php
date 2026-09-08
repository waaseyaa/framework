<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Result of {@see InstalledManifest::readStrict()}.
 *
 * When {@see ManifestReadStatus::Ok}, {@see $manifest} carries the parsed
 * record. Otherwise {@see $detail} names the failure without echoing file
 * contents.
 *
 * @api
 */
final readonly class InstalledManifestReadResult
{
    public function __construct(
        public ManifestReadStatus $status,
        public ?InstalledManifest $manifest = null,
        public ?string $detail = null,
    ) {}

    public function isOk(): bool
    {
        return $this->status === ManifestReadStatus::Ok && $this->manifest !== null;
    }
}
