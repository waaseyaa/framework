<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Enum\EventType;
use Waaseyaa\Entity\EntityBase;

/** Test-only exact event-type projection. */
final class AgentAuditEventTypeReaderFixture
{
    /** @var \Closure(EntityBase): array<string, mixed> */
    private readonly \Closure $obtain;

    public function __construct()
    {
        $this->obtain = \Closure::bind(
            static fn(EntityBase $entity): array => $entity->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
    }

    public function read(AgentAuditLog $row): EventType
    {
        $values = ($this->obtain)($row);

        return EventType::from((string) ($values['event_type'] ?? ''));
    }

    public function toolResultSummary(AgentAuditLog $row): ?string
    {
        $values = ($this->obtain)($row);

        return is_string($values['tool_result_summary'] ?? null) ? $values['tool_result_summary'] : null;
    }

    public function runId(AgentAuditLog $row): string
    {
        $values = ($this->obtain)($row);

        return (string) ($values['run_id'] ?? '');
    }

    public function success(AgentAuditLog $row): bool
    {
        $values = ($this->obtain)($row);

        return (bool) ($values['success'] ?? false);
    }

    /** @return array{iteration: int, toolName: ?string, toolArgumentsJson: ?string} */
    public function toolCall(AgentAuditLog $row): array
    {
        $values = ($this->obtain)($row);

        return [
            'iteration' => (int) ($values['iteration'] ?? 0),
            'toolName' => is_string($values['tool_name'] ?? null) ? $values['tool_name'] : null,
            'toolArgumentsJson' => is_string($values['tool_arguments_json'] ?? null) ? $values['tool_arguments_json'] : null,
        ];
    }
}
