<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Discovery;

use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;

/**
 * Provider capability marker for packages that ship migration plugins.
 *
 * The {@see PluginRegistry} scans the provider list in Composer
 * `installed.json` order; any provider implementing this interface contributes
 * its plugins to the registry. Mirrors the provider-capability discovery
 * pattern used throughout the framework.
 *
 * @api
 */
interface HasMigrationPluginsInterface
{
    /**
     * Yield this provider's plugin instances.
     *
     * Implementations should construct fresh instances (or memoise per
     * provider — never per process) and yield them in any order; the registry
     * indexes by `id()` so source order does not affect lookup.
     *
     * @return iterable<SourcePluginInterface|ProcessPluginInterface|DestinationPluginInterface>
     */
    public function migrationPlugins(): iterable;
}
