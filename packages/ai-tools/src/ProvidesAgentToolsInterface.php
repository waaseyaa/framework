<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

/**
 * Application/provider extension point for runtime agent tools.
 *
 * Implementations register tools into the supplied registry. They must not
 * resolve the registry recursively, perform request-specific work, or depend
 * on provider discovery order. Duplicate names fail closed in the registry.
 *
 * @api
 */
interface ProvidesAgentToolsInterface
{
    public function registerAgentTools(ToolRegistryInterface $registry): void;
}
