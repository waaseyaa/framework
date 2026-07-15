<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent;

use Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Entity-system service provider for the agent executor.
 *
 * Owns: registration of the `agent_run` and `agent_audit_log` entity types
 * with {@see EntityTypeManager}; binding of {@see AgentRunRepository} and
 * {@see AgentAuditLogRepository} as singletons; and pre-registration of
 * {@see AgentRunAccessPolicy} so the kernel attribute scanner can discover
 * it.
 *
 * This provider is **separate from** the main `AiAgentServiceProvider`
 * (which carries the executor + tool registry, owned by WP-03 of the
 * agent-executor mission). Splitting the providers lets WP-02 ship the
 * entity-system surface without colliding with WP-03's pending edits to
 * the executor wiring.
 *
 * @api
 */
final class AiAgentEntityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'agent_run',
            label: 'Agent run',
            class: AgentRun::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'id'],
            description: 'One executor invocation. Aggregate root for agent_audit_log.',
            group: 'ai',
            api: true,
        ));

        $this->entityType(new EntityType(
            id: 'agent_audit_log',
            label: 'Agent audit log entry',
            class: AgentAuditLog::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'event_type'],
            description: 'One audit-log row per executor event. Append-only.',
            group: 'ai',
            api: true,
        ));

        $this->singleton(AgentRunRepository::class, fn(): AgentRunRepository => new AgentRunRepository(
            $this->resolve(EntityTypeManager::class)->getRepository('agent_run'),
            $this->resolve(DatabaseInterface::class),
        ));

        $this->singleton(AgentAuditLogRepository::class, fn(): AgentAuditLogRepository => new AgentAuditLogRepository(
            $this->resolve(EntityTypeManager::class)->getRepository('agent_audit_log'),
            $this->resolve(DatabaseInterface::class),
        ));

        // The access policy is auto-discovered via #[PolicyAttribute].
        // We pre-bind it here so the kernel's policy-injector can hand it
        // the AgentRunRepository dependency (manual registration is
        // required when a policy's constructor accepts injected services —
        // see attachment.AttachmentServiceProvider for the established
        // pattern).
        $this->singleton(AgentRunAccessPolicy::class, fn(): AgentRunAccessPolicy => new AgentRunAccessPolicy(
            $this->resolve(AgentRunRepository::class),
        ));
    }
}
