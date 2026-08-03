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
     */
    public function __construct(
        public string $name,
        public string $capability,
        public bool $destructive,
        public bool $dryRunSupported,
        public string $category,
        public array $inputSchema,
        public AgentToolInterface $impl,
    ) {}

    /**
     * MCP-compliant tool descriptor (`{name, description, inputSchema, annotations}`).
     *
     * The {@see $impl} field is deliberately omitted — the MCP endpoint
     * receives only the declarative descriptor. `annotations.destructiveHint`
     * is the spec-standard projection of {@see $destructive}; by MCP spec it is
     * advisory display metadata, and server-side enforcement (the write tier's
     * approval gate) reads {@see $destructive} itself, never the hint.
     *
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array{destructiveHint: bool}}
     */
    public function toMcpDescriptor(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->impl->description(),
            'inputSchema' => $this->inputSchema,
            'annotations' => ['destructiveHint' => $this->destructive],
        ];
    }
}
