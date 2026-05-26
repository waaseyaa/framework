<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

/**
 * Runtime value object representing a tool registered with the
 * {@see \Waaseyaa\AI\Tools\ToolRegistryInterface}.
 *
 * Pairs the `#[AsAgentTool]` attribute payload with the concrete
 * {@see AgentToolInterface} implementation resolved from the container.
 *
 * @api
 */
final readonly class AgentTool
{
    /**
     * @param array<string, mixed> $inputSchema JSON Schema draft 2020-12
     * @param bool $touchesGovernedData False for metadata-only tools carrying
     *        `#[Capability(governedData: false)]`; true (default) for tools that
     *        touch user-data records and MUST consult EntityAccessHandler per record.
     */
    public function __construct(
        public string $name,
        public string $capability,
        public bool $destructive,
        public bool $dryRunSupported,
        public string $category,
        public array $inputSchema,
        public AgentToolInterface $impl,
        public bool $touchesGovernedData = true,
    ) {}

    /**
     * MCP-compliant tool descriptor (`{name, description, inputSchema}`).
     *
     * The {@see $impl} field is deliberately omitted — the MCP endpoint
     * receives only the declarative descriptor.
     *
     * @return array{name: string, description: string, inputSchema: array<string, mixed>}
     */
    public function toMcpDescriptor(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->impl->description(),
            'inputSchema' => $this->inputSchema,
        ];
    }
}
