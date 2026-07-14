<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Log;

/**
 * Logger channel names emitted by the migration platform.
 *
 * Centralising these constants makes channel-based log filtering (e.g. for
 * deprecation dashboards) safe to refactor.
 *
 * @api
 */
final class Channels
{
    /**
     * Channel for "experimental plugin used" deprecation notices.
     *
     * Reserved for migration implementations that report experimental use.
     */
    public const string MIGRATION_DEPRECATION = 'migration.deprecation';

    /**
     * Channel for migration-discovery operator notices.
     *
     * Emitted by {@see \Waaseyaa\Migration\Discovery\FilesystemManifestLoader}
     * when scanning manifest paths (e.g. "path scanned, no .php files found" —
     * see risk R-discovery-silent in the mission spec).
     */
    public const string MIGRATION_DISCOVERY = 'migration.discovery';

    /**
     * Private constructor — constants-only holder.
     */
    private function __construct() {}
}
