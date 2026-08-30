<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Dispatch;

use Waaseyaa\AI\Tools\AgentTool;

/**
 * The transport-neutral tool-dispatch contract both MCP adapters consume
 * (ADR-022 D-9.3).
 *
 * **Why this interface exists.** Before it, the only dispatch path in the
 * framework was `Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge`, which lives in
 * `waaseyaa/mcp` — a package whose `McpRouteProvider` registers `/mcp/write`
 * unconditionally the moment it is installed. A local stdio plane that reused
 * that dispatch path would have had to require that package, and so would have
 * added an HTTP route to every application that installed a development tool
 * (ADR-022 C-4, D-1.4). The dispatch behaviour itself never needed HTTP; only
 * its address did.
 *
 * **The constraint is structural, not stylistic.** No method here accepts or
 * returns an HTTP request, response, header bag, route, or router. The only
 * types crossing this boundary are {@see AgentTool}, plain arrays, and
 * {@see ToolDispatchOutcome} (whose fields are an array and a Layer 0 enum), so
 * a consumer can dispatch a tool without loading a single HTTP class.
 * `tests/Architecture/TransportNeutralToolDispatchTest.php` proves that by
 * running a real dispatch in a child process whose autoloader refuses every
 * HTTP namespace.
 *
 * **Where the layers sit.** This contract is Layer 5 (`waaseyaa/ai-tools`);
 * `waaseyaa/mcp` is Layer 6 and already requires it, and `waaseyaa/ai-agent` is
 * Layer 5 and already requires it. Both adapters therefore consume one
 * implementation with no new dependency edge in either direction.
 *
 * @api
 */
interface ToolDispatcherInterface
{
    /**
     * Every tool this dispatcher can reach, ordered by name.
     *
     * Ordering is part of the contract: `tools/list` output must be stable
     * across processes so a client can diff two catalogues.
     *
     * @return list<AgentTool>
     */
    public function tools(): array;

    /**
     * Look up one tool, or `null` when it is not reachable here.
     *
     * "Not registered" and "not visible on this tier" are deliberately
     * indistinguishable — a narrowing registry hides an off-tier tool behind
     * the same `null` an unknown name yields.
     */
    public function tool(string $name): ?AgentTool;

    /**
     * Validate the arguments against the tool's advertised input schema, then
     * dispatch, then classify the outcome.
     *
     * Implementations MUST NOT throw for a caller-caused failure: an unknown
     * tool, malformed arguments, a refusal, or an unhandled exception inside a
     * tool all resolve to a {@see ToolDispatchOutcome} carrying an error
     * envelope and the stage that describes it.
     *
     * @param array<string, mixed> $arguments
     */
    public function dispatch(string $toolName, array $arguments): ToolDispatchOutcome;
}
