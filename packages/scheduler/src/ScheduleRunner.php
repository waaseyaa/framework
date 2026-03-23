<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Scheduler\Lock\LockInterface;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;

final class ScheduleRunner
{
    public function __construct(
        private readonly ScheduleInterface $schedule,
        private readonly QueueInterface $queue,
        private readonly LockInterface $lock,
        private readonly ?ScheduleStateRepository $stateRepository = null,
    ) {}

    public function run(\DateTimeInterface $now): ScheduleRunResult
    {
        $ran = [];

        foreach ($this->schedule->tasks() as $task) {
            if (!$task->isDue($now)) {
                continue;
            }

            if ($task->preventOverlap && !$this->lock->acquire($task->name, 300)) {
                continue;
            }

            try {
                if (is_string($task->command)) {
                    $this->queue->dispatch(new ($task->command)());
                } else {
                    ($task->command)();
                }
                $this->stateRepository?->recordRun($task->name, 'success');
                $ran[] = $task->name;
            } catch (\Throwable $e) {
                $this->stateRepository?->recordRun($task->name, 'failed: ' . $e->getMessage());
                error_log("[scheduler] Task {$task->name} failed: {$e->getMessage()}");
            } finally {
                if ($task->preventOverlap) {
                    $this->lock->release($task->name);
                }
            }
        }

        return new ScheduleRunResult(count($ran), $ran);
    }
}
