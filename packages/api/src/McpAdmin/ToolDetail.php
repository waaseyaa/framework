<?php

declare(strict_types=1);

namespace Waaseyaa\Api\McpAdmin;

/**
 * Full per-tool detail record (M5C WP01 T001).
 *
 * Returned by {@see ToolRegistryReadModelInterface::findTool()}.
 *
 * @api
 */
final readonly class ToolDetail
{
    /**
     * @param list<string>           $requiredCapabilities Capability slugs required to call this tool.
     * @param array<string, mixed>   $inputSchema          JSON Schema draft 2020-12.
     * @param list<RecentInvocation> $recentInvocations    Most-recent invocations (max 25).
     */
    public function __construct(
        public string $name,
        public ?string $summary,
        public ?string $description,
        public string $category,
        public array $requiredCapabilities,
        public array $inputSchema,
        public array $recentInvocations,
    ) {}
}
