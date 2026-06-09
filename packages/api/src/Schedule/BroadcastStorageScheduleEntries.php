<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Schedule;

use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleEntriesInterface;
use Waaseyaa\Scheduler\ScheduleInterface;

/**
 * Registers the nightly _broadcast_log prune task.
 *
 * Default schedule: 0 2 * * * (02:00 UTC daily)
 * Default retention: 7 days (configurable via schedule.broadcast_log_retention_days)
 *
 * To disable: add Waaseyaa\Api\Schedule\BroadcastStorageScheduleEntries to
 * schedule.disabled_entries in your configuration.
 *
 * BroadcastStorage is optional: if the dependency is absent (e.g. minimal SSR
 * or OIDC test kernels that do not bind the broadcasting subsystem), this entry
 * is inert — it logs a warning once and returns an empty task map. Mirrors the
 * M-B NullPolicyDependencyResolver optional-binding pattern.
 *
 * @api
 */
final class BroadcastStorageScheduleEntries implements ScheduleEntriesInterface
{
    private int $retentionDays;
    private LoggerInterface $logger;

    public function __construct(
        private readonly ?BroadcastStorage $broadcastStorage = null,
        array $config = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->retentionDays = (int) ($config['schedule']['broadcast_log_retention_days'] ?? 7);
        $this->logger = $logger ?? new NullLogger();
    }

    public function register(ScheduleInterface $schedule): array
    {
        if ($this->broadcastStorage === null) {
            // Unbound BroadcastStorage is the normal state for apps that do not opt
            // into SSE broadcasting, so this is debug (not warning) to avoid noise on
            // every schedule discovery. Apps that DO want pruning bind it. (#1603)
            $this->logger->debug(
                'BroadcastStorageScheduleEntries: BroadcastStorage not bound; '
                . 'broadcast_log_prune task will not be registered. '
                . 'Bind Waaseyaa\\Api\\Controller\\BroadcastStorage in a ServiceProvider to enable pruning.',
            );

            return [];
        }

        $retentionDays = $this->retentionDays;
        $broadcastStorage = $this->broadcastStorage;

        $pruneTask = new ScheduledTask(
            name: 'broadcast_log_prune',
            expression: '0 2 * * *',
            command: static function () use ($broadcastStorage, $retentionDays): void {
                $broadcastStorage->prune($retentionDays);
            },
            timezone: 'UTC',
            description: 'Nightly _broadcast_log prune (FR-006). Retention: ' . $retentionDays . ' days.',
        );

        $schedule->add($pruneTask);

        return ['prune' => $pruneTask];
    }
}
