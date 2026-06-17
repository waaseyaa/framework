<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;

/**
 * Read-only decorator over a {@see ToolRegistryInterface}: only non-destructive
 * tools (optionally narrowed to an explicit capability allowlist) are visible.
 *
 * This is the **structural layer** of the public read-only MCP boundary. A
 * write/destructive tool is not merely denied — it is *absent*: it never appears
 * in `all()` (so `tools/list` cannot show it) and `get()`/`has()` behave as if it
 * were unregistered (so `tools/call` rejects it with "unknown tool"). Combined
 * with the capability layer ({@see Auth\PublicAnonymousAuth}) and each tool's
 * own `accessCheck(true)`, a write tool is structurally unreachable on the
 * public surface.
 *
 * @api
 */
final class ReadOnlyToolRegistry implements ToolRegistryInterface
{
    /**
     * @param ?list<string> $allowedCapabilities When non-null, a tool is visible
     *                                            only if its capability is listed
     *                                            (and it is non-destructive).
     */
    public function __construct(
        private readonly ToolRegistryInterface $inner,
        private readonly ?array $allowedCapabilities = null,
    ) {}

    public function register(AgentTool $tool): void
    {
        // The public surface never registers write tools. Reject loudly rather
        // than silently dropping — a caller trying to expose a destructive tool
        // here is a programming error, not a runtime condition.
        if (!$this->isReadable($tool)) {
            throw new \LogicException(sprintf(
                'Refusing to register tool "%s" on the read-only MCP surface (destructive or not on the allowlist).',
                $tool->name,
            ));
        }

        $this->inner->register($tool);
    }

    public function get(string $name): AgentTool
    {
        $tool = $this->inner->get($name);
        if (!$this->isReadable($tool)) {
            // Hide write tools behind the same error an unregistered name yields.
            throw ToolNotFoundException::forName($name);
        }

        return $tool;
    }

    public function has(string $name): bool
    {
        if (!$this->inner->has($name)) {
            return false;
        }

        return $this->isReadable($this->inner->get($name));
    }

    public function all(): iterable
    {
        foreach ($this->inner->all() as $tool) {
            if ($this->isReadable($tool)) {
                yield $tool;
            }
        }
    }

    private function isReadable(AgentTool $tool): bool
    {
        if ($tool->destructive) {
            return false;
        }

        if ($this->allowedCapabilities !== null && !\in_array($tool->capability, $this->allowedCapabilities, true)) {
            return false;
        }

        return true;
    }
}
