<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/**
 * Capability for the provider that owns the agent-tool registry.
 *
 * Foundation carries only the provider collection seam. The concrete
 * contributor contract remains owned by the Layer-5 ai-tools package and is
 * discovered by the kernel without a compile-time upward dependency.
 *
 * @api
 */
interface AcceptsAgentToolProvidersInterface
{
    /**
     * @param list<object> $providers Kernel-discovered tool contributors.
     */
    public function withAgentToolProviders(array $providers): void;
}
