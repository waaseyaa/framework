<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Bridge;

use Waaseyaa\Foundation\Audit\AuditStage;

/**
 * The result of a tool invocation, classified into an audit stage.
 *
 * The MCP result envelope alone cannot answer "why did this fail" — a
 * capability refusal and an infrastructure failure both arrive as
 * `isError: true`. Auditing them identically is exactly the defect F4 removes,
 * so the bridge classifies at the point where it still has the
 * `AgentToolResult` and its `summary`.
 *
 * @api
 */
final readonly class ToolExecutionOutcome
{
    /**
     * @param array{content: array<int, array{type: string, text?: string, data?: mixed}>, isError?: bool} $envelope
     */
    public function __construct(
        public array $envelope,
        public AuditStage $stage,
    ) {}

    public function isError(): bool
    {
        return ($this->envelope['isError'] ?? false) === true;
    }
}
