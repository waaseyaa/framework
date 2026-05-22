<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Fixture;

use Waaseyaa\AI\Agent\Attribute\AsAgentDefinition;

/**
 * Reference agent definition for the M2 in-process bimaaji integration tests.
 *
 * Marker class that ties the four bimaaji tools (read + mutate) into a single
 * named demo agent. Real production agents would extend or copy this shape;
 * the test harness uses the agent name (`bimaaji_demo`) only as a label —
 * `AgentExecutor::executeRun()` takes raw messages, not an
 * `#[AsAgentDefinition]` class, so this fixture's primary job is to
 * document the demo flow and pin the tool allow-list.
 *
 * **Capability:** declares `bimaaji.read` as the entry gate. The agent
 * cannot make progress without read access (`bimaaji_introspect_section`).
 * Per-tool capabilities (`bimaaji.read` for introspect, `bimaaji.mutate`
 * for the two mutation tools) are enforced inside each tool's
 * {@see \Waaseyaa\AI\Tools\AbstractAgentTool::requireCapability()} call. An
 * account holding only `bimaaji.read` can run this agent but its mutation
 * steps will surface a `forbidden` envelope in the audit log — the negative
 * path verified by `BimaajiAgentRunCapabilityTest`.
 *
 * **Note on `requires_capability` shape:** the WP04 spec sketched
 * `requires_capability: ['bimaaji.read', 'bimaaji.mutate']` as an array,
 * but the shipped {@see AsAgentDefinition} attribute carries a single
 * `?string $requiresCapability`. The single read-capability gate matches
 * the existing attribute contract; broader allow-lists would require
 * extending the attribute (out of WP04 scope).
 *
 * @api
 */
#[AsAgentDefinition(
    id: 'bimaaji_demo',
    label: 'Bimaaji demo agent',
    description: 'Reference agent that introspects an application, proposes a schema mutation, and emits an in-memory PatchSet — exercises all four M2 bimaaji tools end-to-end.',
    prompt: <<<'PROMPT'
You have access to four bimaaji tools (bimaaji_introspect_graph,
bimaaji_introspect_section, bimaaji_propose_mutation, bimaaji_generate_patch).

Workflow:
1. Call bimaaji_introspect_section with section="entities" to see registered
   entity types.
2. Call bimaaji_propose_mutation to validate a schema change against the graph.
3. If the validation succeeds, call bimaaji_generate_patch to emit a PatchSet.

Stop after returning the PatchSet payload. Do not propose mutations that
target unknown entity types.
PROMPT,
    system: 'You are a schema-evolution agent. Make exactly one introspect → propose → generate pass.',
    tools: [
        'bimaaji_introspect_graph',
        'bimaaji_introspect_section',
        'bimaaji_propose_mutation',
        'bimaaji_generate_patch',
    ],
    maxIterations: 8,
    requiresCapability: 'bimaaji.read',
)]
final class BimaajiDemoAgent
{
    // Marker class — discovered by attribute scan; no executable surface.
}
