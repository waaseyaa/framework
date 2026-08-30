<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Registry;

use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;

/**
 * Capability-scoped decorator over a {@see ToolRegistryInterface}: a tool is
 * visible only if its capability is on an explicit allowlist — **including
 * destructive tools**.
 *
 * This is the transport-neutral home of the behaviour that shipped as
 * `Waaseyaa\Mcp\CapabilityScopedToolRegistry`, which is now a delegating façade
 * over this class (ADR-022 Q-3). Reusing the `waaseyaa/mcp` type from a local
 * plane was not an option: `McpRouteProvider` registers `/mcp/write`
 * unconditionally on install, so requiring that package to obtain a *narrowing*
 * decorator would have added an HTTP route in exchange (ADR-022 C-4, D-1.4).
 * Duplicating the logic instead would have left two capability filters to keep
 * in agreement, which is the worse failure mode for a control that decides
 * visibility. One implementation, two consumers.
 *
 * Narrowing is structural, not advisory: an off-tier tool never appears in
 * `all()` (so a catalogue listing cannot show it) and `get()`/`has()` behave as
 * if it were unregistered. It is **not** an authorization layer and does not
 * replace one — per-tool `AbstractAgentTool::requireCapability()` still runs
 * against the acting principal. The two controls are layered: this one decides
 * what is visible, that one decides what may run.
 *
 * @api
 */
final class CapabilityScopedToolRegistry implements ToolRegistryInterface
{
    /**
     * @param list<string> $allowedCapabilities Tools whose capability is listed
     *        are visible (destructive included). An EMPTY allowlist exposes
     *        nothing — the fail-closed shape a scopeless credential gets when
     *        this registry narrows a request to its token scopes.
     * @param list<string> $blockedToolNames Exact tool names withheld even when
     *        their capability is allowlisted. This lets a tier enforce a
     *        narrower structural policy than the embedded agent catalogue.
     */
    public function __construct(
        private readonly ToolRegistryInterface $inner,
        private readonly array $allowedCapabilities,
        private readonly array $blockedToolNames = [],
    ) {}

    public function register(AgentTool $tool): void
    {
        if (!$this->isVisible($tool)) {
            throw new \LogicException(sprintf(
                'Refusing to register tool "%s" on this capability-scoped MCP tier (capability "%s" is not on the allowlist).',
                $tool->name,
                $tool->capability,
            ));
        }

        $this->inner->register($tool);
    }

    public function get(string $name): AgentTool
    {
        $tool = $this->inner->get($name);
        if (!$this->isVisible($tool)) {
            // Off-tier tools are hidden behind the same error an unregistered name yields.
            throw ToolNotFoundException::forName($name);
        }

        return $tool;
    }

    public function has(string $name): bool
    {
        if (!$this->inner->has($name)) {
            return false;
        }

        return $this->isVisible($this->inner->get($name));
    }

    public function all(): iterable
    {
        foreach ($this->inner->all() as $tool) {
            if ($this->isVisible($tool)) {
                yield $tool;
            }
        }
    }

    private function isVisible(AgentTool $tool): bool
    {
        return !\in_array($tool->name, $this->blockedToolNames, true)
            && \in_array($tool->capability, $this->allowedCapabilities, true);
    }
}
