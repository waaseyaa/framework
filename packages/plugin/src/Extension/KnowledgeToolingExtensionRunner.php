<?php

declare(strict_types=1);

namespace Waaseyaa\Plugin\Extension;

use Waaseyaa\Plugin\PluginManagerInterface;

final class KnowledgeToolingExtensionRunner
{
    /** @var array<string, KnowledgeToolingExtensionInterface> */
    private array $extensions;

    /**
     * @param array<string, KnowledgeToolingExtensionInterface> $extensions
     */
    public function __construct(array $extensions)
    {
        ksort($extensions);
        $this->extensions = $extensions;
    }

    public static function fromPluginManager(PluginManagerInterface $pluginManager): self
    {
        $definitions = $pluginManager->getDefinitions();
        $pluginIds = array_keys($definitions);
        sort($pluginIds);

        $extensions = [];
        foreach ($pluginIds as $pluginId) {
            $instance = $pluginManager->createInstance($pluginId);
            if ($instance instanceof KnowledgeToolingExtensionInterface) {
                $extensions[$pluginId] = $instance;
            }
        }

        return new self($extensions);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function applyWorkflowContext(array $context): array
    {
        foreach ($this->extensions as $extension) {
            $context = $extension->alterWorkflowContext($context);
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function applyTraversalContext(array $context): array
    {
        foreach ($this->extensions as $extension) {
            $context = $extension->alterTraversalContext($context);
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function applyDiscoveryContext(array $context): array
    {
        foreach ($this->extensions as $extension) {
            $context = $extension->alterDiscoveryContext($context);
        }

        return $context;
    }

    /**
     * @return list<array{
     *   plugin_id: string,
     *   label: string
     * }>
     */
    public function describeExtensions(): array
    {
        $rows = [];
        foreach ($this->extensions as $extension) {
            $rows[] = [
                'plugin_id' => $extension->getPluginId(),
                'label' => $extension->getPluginDefinition()->label,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp($a['plugin_id'], $b['plugin_id']);
        });

        return $rows;
    }
}
