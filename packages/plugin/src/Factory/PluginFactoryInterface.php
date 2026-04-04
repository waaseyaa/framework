<?php

declare(strict_types=1);

namespace Waaseyaa\Plugin\Factory;

use Waaseyaa\Plugin\PluginInspectionInterface;

/**
 * @internal
 */
interface PluginFactoryInterface
{
    public function createInstance(string $pluginId, array $configuration = []): PluginInspectionInterface;
}
