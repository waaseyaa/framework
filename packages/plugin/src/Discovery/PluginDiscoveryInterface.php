<?php

declare(strict_types=1);

namespace Waaseyaa\Plugin\Discovery;

use Waaseyaa\Plugin\Definition\PluginDefinition;

/**
 * @internal
 */
interface PluginDiscoveryInterface
{
    /** @return array<string, PluginDefinition> */
    public function getDefinitions(): array;
}
