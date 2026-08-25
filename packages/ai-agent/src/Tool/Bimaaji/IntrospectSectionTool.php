<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Bimaaji;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
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
 * Capability: `bimaaji.read`.
 *
 * @api
 */
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

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('bimaaji.read', $account);
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
            return $this->internalError('bimaaji_introspect_section', $e);
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

        $payload = $found->toArray();

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return $this->internalError('bimaaji_introspect_section', $e);
        }

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => $json]],
            summary: sprintf('Section "%s" (v%s)', $found->key, $found->version),
            structuredContent: $payload,
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        return $this->execute($arguments, $account);
    }
}
