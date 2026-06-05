<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent;

use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Runtime service provider for the agent executor.
 *
 * Companion to {@see AiAgentEntityServiceProvider} which owns the
 * `agent_run` / `agent_audit_log` entity types and their repositories.
 * This provider binds the executor-runtime surface:
 *
 *  - {@see AgentDefinitionRegistry} (singleton) — manifest-driven catalogue
 *    of `#[AsAgentDefinition]` classes.
 *  - {@see AgentExecutor} (singleton) — the run-loop driver.
 *
 * @api
 */
final class AiAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(
            AgentDefinitionRegistry::class,
            fn(): AgentDefinitionRegistry => new AgentDefinitionRegistry(
                $this->resolve(PackageManifest::class),
            ),
        );

        $this->singleton(
            AgentExecutor::class,
            function (): AgentExecutor {
                $logger = $this->safeResolve(LoggerInterface::class) ?? new NullLogger();

                return new AgentExecutor(
                    toolRegistry: $this->resolve(ToolRegistryInterface::class),
                    runRepository: $this->resolve(AgentRunRepository::class),
                    auditRepository: $this->resolve(AgentAuditLogRepository::class),
                    transcriptMaxBytes: (int) ($this->config['ai']['transcript_max_bytes'] ?? 262144),
                    hitlPollIntervalMs: (int) ($this->config['ai']['hitl_poll_interval_ms'] ?? 1000),
                    hitlTimeoutSeconds: (int) ($this->config['ai']['hitl_timeout_seconds'] ?? 300),
                    logger: $logger,
                );
            },
        );
    }

    /**
     * Resolve an optional binding, returning `null` if absent so we can
     * fall back to a {@see NullLogger} without crashing the kernel when
     * the logger has not been bound yet (e.g. during isolated tests).
     */
    private function safeResolve(string $abstract): mixed
    {
        try {
            return $this->resolve($abstract);
        } catch (\Throwable) {
            return null;
        }
    }
}
