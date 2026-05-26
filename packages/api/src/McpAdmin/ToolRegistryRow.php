<?php

declare(strict_types=1);

namespace Waaseyaa\Api\McpAdmin;

/**
 * Registry-index row for a single MCP tool (M5C WP01 T001).
 *
 * Returned by {@see ToolRegistryReadModelInterface::listTools()}.
 * Subset of the full {@see ToolDetail} — no input schema or recent invocations.
 *
 * @api
 */
final readonly class ToolRegistryRow
{
    /**
     * @param list<string> $requiredCapabilities Capability slugs required to call this tool.
     */
    public function __construct(
        public string $name,
        public ?string $summary,
        public string $category,
        public array $requiredCapabilities,
    ) {}
}
