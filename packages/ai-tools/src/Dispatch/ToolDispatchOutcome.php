<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Dispatch;

use Waaseyaa\Foundation\Audit\AuditStage;

/**
 * The result of one tool dispatch, classified into an audit stage.
 *
 * The result envelope alone cannot answer "why did this fail" — a capability
 * refusal and an infrastructure failure both arrive as `isError: true`.
 * Auditing them identically flattens the trail, so the dispatcher classifies at
 * the point where it still holds the {@see \Waaseyaa\AI\Tools\AgentToolResult}
 * and its `summary`.
 *
 * Transport-neutral by construction (ADR-022 D-9.3): the envelope is a plain
 * array and the stage is a Layer 0 enum. Nothing here knows about HTTP, and an
 * stdio adapter consumes it without a route registrar behind it.
 *
 * @api
 */
final readonly class ToolDispatchOutcome
{
    /**
     * @param array{content: array<int, array{type: string, text?: string, data?: mixed}>, isError?: bool, structuredContent?: array<string, mixed>} $envelope
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
