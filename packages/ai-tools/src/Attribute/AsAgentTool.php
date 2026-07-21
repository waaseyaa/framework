<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Attribute;

/**
 * Marks a class as an agent tool discoverable via {@see PackageManifestCompiler}.
 *
 * Tool classes carrying this attribute MUST implement
 * {@see \Waaseyaa\AI\Tools\AgentToolInterface}; the registry will refuse
 * registration of classes that do not.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsAgentTool
{
    public function __construct(
        public readonly string $name,
        public readonly string $capability,
        public readonly bool $destructive = false,
        public readonly bool $dryRunSupported = false,
        public readonly string $category = 'general',
        public readonly ?string $requiresPackage = null,
    ) {}
}
