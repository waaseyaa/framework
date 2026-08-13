<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Version;

use Waaseyaa\SiteContract\SiteManifestSchema;

/** @api */
final class SiteManifestVersionPolicy
{
    public function disposition(int $observedVersion): ManifestVersionDisposition
    {
        return match (true) {
            $observedVersion < SiteManifestSchema::CURRENT_VERSION => ManifestVersionDisposition::MigrationRequired,
            $observedVersion > SiteManifestSchema::CURRENT_VERSION => ManifestVersionDisposition::UnsupportedFuture,
            default => ManifestVersionDisposition::Current,
        };
    }
}
