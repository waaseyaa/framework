<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Discovery;

use Waaseyaa\Migration\MigrationDefinition;

/**
 * Provider capability that contributes concrete {@see MigrationDefinition}
 * instances to {@see MigrationRegistry} at boot time (FR-013).
 *
 * This interface ships whole, fully-formed migrations authored against the
 * plugin contracts.
 *
 * Migrations may also be registered via the filesystem fallback
 * (`migration.manifest_paths` in `config/waaseyaa.php`), scanned by
 * {@see FilesystemManifestLoader}. Both mechanisms feed the same registry.
 *
 * **When to use which:**
 *
 * - Implement this interface on a `ServiceProvider` shipping migrations as
 *   first-class package surface — e.g. a WordPress-content source-reader
 *   package that ships ready-made `wp_users_to_accounts`, `wp_posts_to_teachings`
 *   manifests.
 * - Use the filesystem fallback for application-specific one-off migrations
 *   that don't warrant their own package.
 *
 * @api
 */
interface HasMigrationsInterface
{
    /**
     * Return the migrations this provider contributes.
     *
     * The iterable is consumed exactly once at boot and may be a generator.
     * Each yielded value MUST be a {@see MigrationDefinition} instance;
     * {@see MigrationRegistry} type-checks the iterable as it consumes it.
     *
     * @return iterable<MigrationDefinition>
     */
    public function migrations(): iterable;
}
