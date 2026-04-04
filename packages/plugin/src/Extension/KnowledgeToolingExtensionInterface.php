<?php

declare(strict_types=1);

namespace Waaseyaa\Plugin\Extension;

use Waaseyaa\Plugin\PluginInspectionInterface;

/**
 * @internal
 */
interface KnowledgeToolingExtensionInterface extends PluginInspectionInterface
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function alterWorkflowContext(array $context): array;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function alterTraversalContext(array $context): array;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function alterDiscoveryContext(array $context): array;
}
