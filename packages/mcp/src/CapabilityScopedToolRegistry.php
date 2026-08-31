<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\Registry\CapabilityScopedToolRegistry as SharedCapabilityScopedToolRegistry;
use Waaseyaa\AI\Tools\ToolRegistryInterface;

/**
 * Capability-scoped decorator over a {@see ToolRegistryInterface}: a tool is
 * visible only if its capability is on an explicit allowlist — **including
 * destructive tools**.
 *
 * This is the structural layer of an *authenticated write tier*: the dual of
 * {@see ReadOnlyToolRegistry}. The read-only registry hides every destructive
 * tool; this one exposes exactly the capability set a tier is for (e.g. just
 * `present guided content` for the Wayfinding write tools), and nothing else —
 * so a separate authenticated endpoint surfaces only its own tools and never
 * the whole destructive catalogue. The public `/mcp` surface and its
 * {@see ReadOnlyToolRegistry} are untouched (C-001); per-tool
 * `AbstractAgentTool::requireCapability` remains the authorization layer.
 *
 * **The filtering itself now lives in `waaseyaa/ai-tools`** as
 * {@see SharedCapabilityScopedToolRegistry}, and this class delegates to it
 * (ADR-022 Q-3). The local development plane cannot require `waaseyaa/mcp` to
 * obtain a narrowing decorator — `McpRouteProvider` registers `/mcp/write`
 * unconditionally on install (ADR-022 C-4, D-1.4) — and duplicating the
 * predicate would leave two visibility filters to keep in agreement. One
 * implementation, two consumers; this type stays so the MCP tier wiring and
 * its `LogicException` message are unchanged.
 *
 * @api
 */
final class CapabilityScopedToolRegistry implements ToolRegistryInterface
{
    private readonly SharedCapabilityScopedToolRegistry $delegate;

    /**
     * The constructor arguments are retained as properties, not merely forwarded:
     * `McpServiceProviderTest` reflects into `$blockedToolNames` to prove the
     * write tier's structural block on generic entity mutations is wired, and
     * that proof must keep working across this delegation.
     *
     * @param list<string> $allowedCapabilities Tools whose capability is listed
     *        are visible (destructive included). An EMPTY allowlist exposes
     *        nothing — the fail-closed shape a scopeless credential gets when
     *        this registry narrows a request to its token scopes (#2177 F3).
     * @param list<string> $blockedToolNames Exact tool names withheld even when
     *        their capability is allowlisted. This lets a network tier enforce
     *        a narrower structural policy than the embedded agent catalogue.
     */
    public function __construct(
        private readonly ToolRegistryInterface $inner,
        private readonly array $allowedCapabilities,
        private readonly array $blockedToolNames = [],
    ) {
        $this->delegate = new SharedCapabilityScopedToolRegistry(
            $this->inner,
            $this->allowedCapabilities,
            $this->blockedToolNames,
        );
    }

    public function register(AgentTool $tool): void
    {
        $this->delegate->register($tool);
    }

    public function get(string $name): AgentTool
    {
        return $this->delegate->get($name);
    }

    public function has(string $name): bool
    {
        return $this->delegate->has($name);
    }

    public function all(): iterable
    {
        yield from $this->delegate->all();
    }
}
