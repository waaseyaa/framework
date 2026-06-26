<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Schedule;

use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleEntriesInterface;
use Waaseyaa\Scheduler\ScheduleInterface;

/**
 * Registers the periodic audit checkpoint sealing task.
 *
 * Default schedule: every 15 minutes (`* /15 * * * *`)
 * Configurable via `schedule.audit_checkpoint_cron` in your application config.
 *
 * To disable: add Waaseyaa\Audit\Schedule\AuditCheckpointScheduleEntries to
 * schedule.disabled_entries in your configuration.
 *
 * AuditCheckpointBuilder is optional: if the dependency is absent (e.g. minimal
 * kernels that do not bind the audit subsystem), this entry is inert — it logs a
 * debug message once and returns an empty task map. Mirrors the
 * BroadcastStorageScheduleEntries optional-binding pattern.
 *
 * @api
 */
final class AuditCheckpointScheduleEntries implements ScheduleEntriesInterface
{
    private readonly LoggerInterface $logger;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly ?AuditCheckpointBuilder $builder = null,
        private readonly array $config = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function register(ScheduleInterface $schedule): array
    {
        if ($this->builder === null) {
            $this->logger->debug(
                'AuditCheckpointScheduleEntries: AuditCheckpointBuilder not bound; '
                . 'audit_checkpoint task will not be registered. '
                . 'Bind Waaseyaa\\Audit\\Integrity\\AuditCheckpointBuilder in a ServiceProvider to enable checkpointing.',
            );

            return [];
        }

        $builder    = $this->builder;
        $expression = $this->config['schedule']['audit_checkpoint_cron'] ?? '*/15 * * * *';

        $task = new ScheduledTask(
            name: 'audit_checkpoint',
            expression: $expression,
            command: static function () use ($builder): void {
                $builder->build();
            },
            timezone: 'UTC',
            description: 'Periodic audit_event checkpoint sealing (WP2). Seals unsealed rows into a tamper-evidence checkpoint.',
        );

        $schedule->add($task);

        return ['audit_checkpoint' => $task];
    }
}
