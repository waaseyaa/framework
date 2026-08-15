<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Mcp;

/** @api Process-local outbound MCP readiness/degradation view. */
final class McpIntegrationHealth
{
    /** @var array<string, array{state: 'healthy'|'degraded'|'blocked', reason: string}> */
    private array $servers = [];

    public function healthy(string $alias): void
    {
        $this->servers[$alias] = ['state' => 'healthy', 'reason' => 'ready'];
    }

    public function degraded(string $alias, string $reason): void
    {
        $this->servers[$alias] = ['state' => 'degraded', 'reason' => $reason];
    }

    public function blocked(string $alias, string $reason): void
    {
        $this->servers[$alias] = ['state' => 'blocked', 'reason' => $reason];
    }

    /** @return array{state: 'healthy'|'degraded'|'blocked', reason: string}|null */
    public function status(string $alias): ?array
    {
        return $this->servers[$alias] ?? null;
    }

    /** @return array<string, array{state: 'healthy'|'degraded'|'blocked', reason: string}> */
    public function snapshot(): array
    {
        return $this->servers;
    }
}
