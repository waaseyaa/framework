<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
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
 * @api
 */
final class CapabilityScopedToolRegistry implements ToolRegistryInterface
{
    /**
     * @param list<string> $allowedCapabilities Tools whose capability is listed
     *        are visible (destructive included). An EMPTY allowlist exposes
     *        nothing — the fail-closed shape a scopeless credential gets when
     *        this registry narrows a request to its token scopes (#2177 F3).
     */
    public function __construct(
        private readonly ToolRegistryInterface $inner,
        private readonly array $allowedCapabilities,
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
        return \in_array($tool->capability, $this->allowedCapabilities, true);
    }
}
