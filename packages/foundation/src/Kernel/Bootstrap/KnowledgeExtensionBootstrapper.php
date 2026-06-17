<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Plugin\Attribute\WaaseyaaPlugin;
use Waaseyaa\Plugin\DefaultPluginManager;
use Waaseyaa\Plugin\Discovery\AttributeDiscovery;
use Waaseyaa\Plugin\Extension\KnowledgeToolingExtensionRunner;

final class KnowledgeExtensionBootstrapper
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public function boot(string $projectRoot, array $config): KnowledgeToolingExtensionRunner
    {
        $extensionConfig = is_array($config['extensions'] ?? null) ? $config['extensions'] : [];
        $directories = $this->resolveDirectories($projectRoot, $extensionConfig);

        if ($directories === []) {
            return new KnowledgeToolingExtensionRunner([]);
        }

        $attributeClass = $this->resolveAttributeClass($extensionConfig);

        try {
            $discovery = new AttributeDiscovery(
                directories: $directories,
                attributeClass: $attributeClass,
            );
            $manager = new DefaultPluginManager($discovery);

            return KnowledgeToolingExtensionRunner::fromPluginManager($manager);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Failed to boot knowledge extension runner: %s', $e->getMessage()));

            return new KnowledgeToolingExtensionRunner([]);
        }
    }

    /**
     * @param array<string, mixed> $extensionConfig
     * @return list<string>
     */
    private function resolveDirectories(string $projectRoot, array $extensionConfig): array
    {
        $rawDirectories = $extensionConfig['plugin_directories'] ?? [];
        if (is_string($rawDirectories)) {
            $rawDirectories = [$rawDirectories];
        }
        if (!is_array($rawDirectories)) {
            $rawDirectories = [];
        }

        $directories = [];
        foreach ($rawDirectories as $directory) {
            if (!is_string($directory)) {
                continue;
            }
            $trimmed = trim($directory);
            if ($trimmed === '') {
                continue;
            }
            if (!self::isAbsolutePath($trimmed)) {
                $trimmed = $projectRoot . '/' . ltrim($trimmed, '/');
            }
            $directories[] = $trimmed;
        }
        $directories = array_values(array_unique($directories));
        sort($directories);

        return $directories;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    /**
     * @param array<string, mixed> $extensionConfig
     */
    private function resolveAttributeClass(array $extensionConfig): string
    {
        $attributeClass = is_string($extensionConfig['plugin_attribute'] ?? null)
            ? trim((string) $extensionConfig['plugin_attribute'])
            : WaaseyaaPlugin::class;

        return $attributeClass === '' ? WaaseyaaPlugin::class : $attributeClass;
    }
}
