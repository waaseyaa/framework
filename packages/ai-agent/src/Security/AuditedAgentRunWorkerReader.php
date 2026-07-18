<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Security;

use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\Audit\AuditedFieldRead;

/** Audited system-job reader for the fixed worker projection. @api */
final readonly class AuditedAgentRunWorkerReader implements AgentRunWorkerReaderInterface
{
    public const string ISSUER = 'agent-run.system-worker';
    private const array FIELDS = ['account_id', 'agent_definition_id', 'bundle_json', 'prompt', 'response', 'error_code', 'error_message'];

    public function __construct(private AuditedFieldRead $reader, private CapabilityRegistryInterface $capabilities)
    {
        $this->capabilities->register(new CapabilityDeclaration(
            issuer: self::ISSUER,
            reason: CapabilityReason::SystemJob,
            entityTypes: ['agent_run'],
            bundles: ['agent_run'],
            fields: self::FIELDS,
            actorSemantics: [CapabilityActorSemantics::System],
            maxTtlSeconds: 60,
            justification: 'Execute the exact persisted AgentRun worker payload.',
        ));
    }

    public function read(AgentRun $run): AgentRunWorkerFields
    {
        $boundary = $this->capabilities->openBoundary('agent-run-worker:' . bin2hex(random_bytes(12)));
        try {
            $capability = $this->capabilities->issueValueRead(self::ISSUER, new CapabilityIssueContext(
                executionBoundary: $boundary->correlationId,
                actorSemantics: CapabilityActorSemantics::System,
                actorId: 'agent-run-worker',
                tenantId: null,
                communityId: null,
                expiresAt: new \DateTimeImmutable('+60 seconds'),
                classificationGeneration: 'field-read-active',
                policyGeneration: 'field-read-active',
            ), $boundary);
            $values = $this->reader->readMany($capability, $boundary, $run, self::FIELDS, CapabilityReason::SystemJob);

            return new AgentRunWorkerFields(
                (int) $values['account_id'],
                is_string($values['agent_definition_id']) ? $values['agent_definition_id'] : null,
                (string) $values['bundle_json'],
                (string) $values['prompt'],
                is_string($values['response']) ? $values['response'] : null,
                is_string($values['error_code']) ? $values['error_code'] : null,
                is_string($values['error_message']) ? $values['error_message'] : null,
            );
        } finally {
            $this->capabilities->revokeBoundary($boundary);
        }
    }
}
