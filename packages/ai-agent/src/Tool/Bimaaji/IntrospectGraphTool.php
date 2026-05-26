<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Bimaaji;

use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolContext;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\AI\Tools\Attribute\Capability;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;

/**
 * Full-graph introspection tool.
 *
 * Calls {@see ApplicationGraphGenerator::generate()} and returns the full
 * `ApplicationGraph::toArray()` payload — six default sections (admin,
 * entities, jsonapi, public_surface, routing, sovereignty) plus the graph
 * version. Gated by `bimaaji.read`; idempotent; no side effects.
 *
 * Capability: `bimaaji.read`. Metadata-only: no user-data records exposed,
 * so `#[Capability(governedData: false)]` opts this tool out of the
 * mandatory per-record EntityAccessHandler consultation (FR-003 / DIR-004).
 *
 * @api
 */
#[Capability(governedData: false)]
#[AsAgentTool(
    name: 'bimaaji_introspect_graph',
    capability: 'bimaaji.read',
    destructive: false,
    dryRunSupported: true,
    category: 'bimaaji',
)]
final class IntrospectGraphTool extends AbstractAgentTool
{
    public function __construct(
        private readonly ApplicationGraphGenerator $generator,
    ) {}

    public function description(): string
    {
        return 'Return the full application introspection graph (six default sections).';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('bimaaji.read', $context);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $graph = $this->generator->generate();
        } catch (\Throwable $e) {
            return AgentToolResult::error(
                message: sprintf('bimaaji_introspect_graph: [%s] %s', $e::class, $e->getMessage()),
                summary: 'graph generation failed',
            );
        }

        $payload = $graph->toArray();
        $sectionCount = count($payload['sections']);

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => $payload]],
            summary: sprintf('Application graph: %d sections', $sectionCount),
        );
    }

    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult
    {
        return $this->execute($arguments, $context);
    }
}
