<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

/**
 * Raised when an `#[AsAgentTool]` class cannot be resolved because a dependency
 * it needs is not available in THIS kernel configuration — not because the tool
 * is broken.
 *
 * Tool discovery is best-effort: a kernel that omits an optional feature (no
 * RouteCollection bound for routing-introspection tools, no embedding provider
 * for vector-search tools, etc.) simply does not expose the tools that need it.
 * {@see \Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry} treats this signal
 * as a quiet, expected skip (debug-level) and keeps the tool out of the
 * catalogue — whereas any OTHER throwable from resolution is a genuine bug and
 * stays at error level. This is what stops the boot / `tools/list` log spam from
 * optional tools whose deps are absent, without changing the resolved tool set.
 *
 * @api
 */
final class ToolDependencyUnavailableException extends \RuntimeException
{
    public static function forDependency(string $owner, string $dependency, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Tool "%s" is unavailable in this kernel: dependency "%s" could not be resolved.', $owner, $dependency),
            0,
            $previous,
        );
    }
}
