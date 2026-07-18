<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Security\AgentRunAccountProjection;
use Waaseyaa\AI\Agent\Security\AgentRunAccountProjectionReaderInterface;
use Waaseyaa\Entity\EntityBase;

/** Test-only fixed account projection; production installs an account read scope. */
final class AgentRunAccountProjectionReaderFixture implements AgentRunAccountProjectionReaderInterface
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

    public function read(AgentRun $run, AccountInterface $account): AgentRunAccountProjection
    {
        unset($account);
        return $this->readWithoutAccount($run);
    }

    public function readWithoutAccount(AgentRun $run): AgentRunAccountProjection
    {
        $values = ($this->obtain)($run);
        $transcript = [];
        $truncated = false;
        try {
            $decoded = json_decode((string) ($values['transcript_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
            $transcript = is_array($decoded) ? array_values($decoded) : [];
        } catch (\JsonException) {
            $truncated = true;
        }

        return new AgentRunAccountProjection(
            id: (string) ($values['id'] ?? ''),
            status: (string) ($values['status'] ?? ''),
            agentDefinitionId: self::stringOrNull($values['agent_definition_id'] ?? null),
            prompt: (string) ($values['prompt'] ?? ''),
            response: self::stringOrNull($values['response'] ?? null),
            transcript: $transcript,
            transcriptTruncated: $truncated,
            tokenUsageIn: (int) ($values['token_usage_in'] ?? 0),
            tokenUsageOut: (int) ($values['token_usage_out'] ?? 0),
            costCents: isset($values['cost_cents']) ? (int) $values['cost_cents'] : null,
            toolCallCount: (int) ($values['tool_call_count'] ?? 0),
            destructiveApproval: (string) ($values['destructive_approval'] ?? 'none'),
            pendingApprovalCallId: self::stringOrNull($values['pending_approval_call_id'] ?? null),
            approvalExpiresAt: self::stringOrNull($values['approval_expires_at'] ?? null),
            queuedAt: (string) ($values['queued_at'] ?? ''),
            startedAt: self::stringOrNull($values['started_at'] ?? null),
            finishedAt: self::stringOrNull($values['finished_at'] ?? null),
            errorCode: self::stringOrNull($values['error_code'] ?? null),
            errorMessage: self::stringOrNull($values['error_message'] ?? null),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
