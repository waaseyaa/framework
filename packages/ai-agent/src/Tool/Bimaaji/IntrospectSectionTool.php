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
 * Section-scoped introspection tool.
 *
 * Returns the payload for a single section key (e.g. `routing`,
 * `entities`). Unknown section keys produce a structured error envelope
 * listing the available section keys so an agent can self-correct
 * without an extra round-trip. Gated by `bimaaji.read`; idempotent; no
 * side effects.
 *
 * Capability: `bimaaji.read`. Metadata-only: no user-data records exposed,
 * so `#[Capability(governedData: false)]` opts this tool out of the
 * mandatory per-record EntityAccessHandler consultation (FR-003 / DIR-004).
 *
 * @api
 */
#[Capability(governedData: false)]
#[AsAgentTool(
    name: 'bimaaji_introspect_section',
    capability: 'bimaaji.read',
    destructive: false,
    dryRunSupported: true,
    category: 'bimaaji',
)]
final class IntrospectSectionTool extends AbstractAgentTool
{
    public function __construct(
        private readonly ApplicationGraphGenerator $generator,
    ) {}

    public function description(): string
    {
        return 'Return the introspection payload for a single section (admin, entities, jsonapi, public_surface, routing, sovereignty).';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'section' => [
                    'type' => 'string',
                    'enum' => ['admin', 'entities', 'jsonapi', 'public_surface', 'routing', 'sovereignty'],
                ],
            ],
            'required' => ['section'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('bimaaji.read', $context);
        if ($denied !== null) {
            return $denied;
        }

        $section = $arguments['section'] ?? null;
        if (!is_string($section) || $section === '') {
            return AgentToolResult::error(
                message: 'bimaaji_introspect_section: missing required argument "section".',
                summary: 'missing argument',
            );
        }

        try {
            $graph = $this->generator->generate();
        } catch (\Throwable $e) {
            return AgentToolResult::error(
                message: sprintf('bimaaji_introspect_section: [%s] %s', $e::class, $e->getMessage()),
                summary: 'graph generation failed',
            );
        }

        $found = $graph->getSection($section);
        if ($found === null) {
            $available = array_keys($graph->sections);
            sort($available);

            return AgentToolResult::error(
                message: sprintf(
                    'Unknown section "%s". Available sections: %s',
                    $section,
                    implode(', ', $available),
                ),
                summary: 'unknown section',
            );
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => $found->toArray()]],
            summary: sprintf('Section "%s" (v%s)', $found->key, $found->version),
        );
    }

    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult
    {
        return $this->execute($arguments, $context);
    }
}
