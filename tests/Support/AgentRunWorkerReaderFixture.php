<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Security\AgentRunWorkerFields;
use Waaseyaa\AI\Agent\Security\AgentRunWorkerReaderInterface;
use Waaseyaa\Entity\EntityBase;

/** Test-only fixed worker projection; production uses the audited reader. */
final class AgentRunWorkerReaderFixture implements AgentRunWorkerReaderInterface
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

    public function read(AgentRun $run): AgentRunWorkerFields
    {
        $values = ($this->obtain)($run);

        return new AgentRunWorkerFields(
            (int) ($values['account_id'] ?? 0),
            is_string($values['agent_definition_id'] ?? null) ? $values['agent_definition_id'] : null,
            (string) ($values['bundle_json'] ?? ''),
            (string) ($values['prompt'] ?? ''),
            is_string($values['response'] ?? null) ? $values['response'] : null,
            is_string($values['error_code'] ?? null) ? $values['error_code'] : null,
            is_string($values['error_message'] ?? null) ? $values['error_message'] : null,
        );
    }
}
