<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Entity;

use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;

/**
 * The `agent_run` aggregate root — one row per executor invocation.
 *
 * Authoritative shape lives in `kitty-specs/agent-executor-01KRWPK7/data-model.md`
 * § AgentRun. Persisted via `EntityRepository` over `SqlStorageDriver`.
 *
 * Column mapping (storage-canonical keys passed to the constructor):
 *
 * - `id`                       — uuid PK
 * - `account_id`               — initiator account id (int)
 * - `agent_definition_id`      — string|null
 * - `bundle_json`              — frozen bundle JSON string
 * - `status`                   — {@see RunStatus} string value
 * - `destructive_approval`     — {@see HitlMode} string value
 * - `pending_approval_call_id` — string|null
 * - `approval_expires_at`      — DateTimeImmutable|null
 * - `prompt`                   — resolved user prompt
 * - `response`                 — final response (terminal:completed)
 * - `transcript_json`          — conversation snapshot (string)
 * - `token_usage_in`           — int
 * - `token_usage_out`          — int
 * - `cost_cents`               — int|null
 * - `tool_call_count`          — int
 * - `queued_at`                — DateTimeImmutable
 * - `started_at`               — DateTimeImmutable|null
 * - `finished_at`              — DateTimeImmutable|null
 * - `error_code`               — string|null
 * - `error_message`            — string|null
 *
 * Constructor accepts a storage-canonical array (per the entity-storage
 * invariant) and hardcodes `entityTypeId` + `entityKeys`. `SqlEntityStorage`
 * reflects on this single-array signature.
 *
 * @api
 */
#[ContentEntityType(id: 'agent_run', storageBackend: PrimaryStorageBackend::SQL_COLUMN)]
#[ContentEntityKeys(id: 'id', uuid: 'id', label: 'id')]
final class AgentRun extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $id = '';
    #[Field(type: 'integer', settings: ['authorizationInput' => true], read: FieldReadLevel::Protected)] public int $account_id = 0;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public mixed $agent_definition_id = null;
    #[Field(type: 'string', read: FieldReadLevel::Internal)] public string $bundle_json = '';
    #[Field(type: 'string', read: FieldReadLevel::Public)] public mixed $status = null;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public mixed $destructive_approval = null;
    #[Field(type: 'string', read: FieldReadLevel::Protected)] public mixed $pending_approval_call_id = null;
    #[Field(type: 'string', read: FieldReadLevel::Protected)] public mixed $approval_expires_at = null;
    #[Field(type: 'text', read: FieldReadLevel::Protected)] public string $prompt = '';
    #[Field(type: 'text', read: FieldReadLevel::Protected)] public mixed $response = null;
    #[Field(type: 'text', read: FieldReadLevel::Protected)] public string $transcript_json = '';
    #[Field(type: 'integer', read: FieldReadLevel::Protected)] public int $token_usage_in = 0;
    #[Field(type: 'integer', read: FieldReadLevel::Protected)] public int $token_usage_out = 0;
    #[Field(type: 'integer', read: FieldReadLevel::Protected)] public mixed $cost_cents = null;
    #[Field(type: 'integer', read: FieldReadLevel::Protected)] public int $tool_call_count = 0;
    #[Field(type: 'datetime', read: FieldReadLevel::Protected)] public mixed $queued_at = null;
    #[Field(type: 'datetime', read: FieldReadLevel::Protected)] public mixed $started_at = null;
    #[Field(type: 'datetime', read: FieldReadLevel::Protected)] public mixed $finished_at = null;
    #[Field(type: 'string', read: FieldReadLevel::Protected)] public mixed $error_code = null;
    #[Field(type: 'text', read: FieldReadLevel::Protected)] public mixed $error_message = null;

    /**
     * @param array<string, mixed> $values Initial storage-canonical values.
     * @param string $entityTypeId Hydration override; default hardcoded.
     * @param array<string, string> $entityKeys Hydration override; default hardcoded.
     * @param array<string, mixed> $fieldDefinitions Reserved for the field-definition registry.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = 'agent_run',
        array $entityKeys = ['id' => 'id', 'uuid' => 'id', 'label' => 'id'],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    /**
     * Returns the current {@see RunStatus}, resolving the stored string.
     */
    public function getStatus(): RunStatus
    {
        $raw = $this->get('status');
        if ($raw instanceof RunStatus) {
            return $raw;
        }

        return RunStatus::from((string) $raw);
    }

    /**
     * Returns the destructive-approval mode for this run.
     */
    public function getDestructiveApproval(): HitlMode
    {
        $raw = $this->get('destructive_approval');
        if ($raw instanceof HitlMode) {
            return $raw;
        }

        return HitlMode::from((string) ($raw ?? HitlMode::None->value));
    }

    /**
     * Initiator account id.
     */
    public function getAccountId(): int
    {
        return (int) $this->get('account_id');
    }

    /**
     * Whether the run has reached a terminal status (cancelled / completed /
     * failed). Delegates to {@see RunStatus::terminals()}.
     */
    public function isTerminal(): bool
    {
        return $this->getStatus()->isTerminal();
    }
}
