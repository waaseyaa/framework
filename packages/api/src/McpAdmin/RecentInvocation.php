<?php

declare(strict_types=1);

namespace Waaseyaa\Api\McpAdmin;

/**
 * A single recent invocation of an MCP tool from the audit/trace log (M5C WP01 T001).
 *
 * Used by {@see ToolDetail::$recentInvocations}. Populated from the M5A
 * `AiObservabilityReadModelInterface` when available; empty list when unbound.
 *
 * @api
 */
final readonly class RecentInvocation
{
    public function __construct(
        public string $traceUuid,
        public string $invokedAt,
        public ?string $account,
        /** @var 'ok'|'error' */
        public string $outcome,
        public ?string $errorMessage,
        public ?int $latencyMs,
    ) {}
}
