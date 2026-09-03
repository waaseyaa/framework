<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Bimaaji;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;

/**
 * Full-graph introspection tool.
 *
 * Calls {@see ApplicationGraphGenerator::generate()} and returns the full
 * `ApplicationGraph::toArray()` payload — six default sections (admin,
 * entities, jsonapi, public_surface, routing, sovereignty) plus the graph
 * version. Gated by `bimaaji.read`; idempotent; no side effects.
 *
 * Capability: `bimaaji.read`.
 *
 * @api
 */
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
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('bimaaji.read', $account);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $graph = $this->generator->generate();
        } catch (\Throwable $e) {
            return $this->internalError('bimaaji_introspect_graph', $e);
        }

        $payload = $graph->toArray();
        $sectionCount = count($payload['sections']);

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return $this->internalError('bimaaji_introspect_graph', $e);
        }

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => $json]],
            summary: sprintf('Application graph: %d sections', $sectionCount),
            structuredContent: $payload,
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        return $this->execute($arguments, $account);
    }
}
