<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

/**
 * @api
 */
final readonly class ScheduleRunResult
{
    /**
     * Per-task outcome status, populated by `ScheduleRunner::runOne()` (M4B
     * WP02). `null` on results returned by `ScheduleRunner::run()`, which
     * describe a sweep rather than a single task.
     */
    public const string STATUS_SUCCESS = 'success';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_SKIPPED_OVERLAP = 'skipped: overlap';

    /**
     * @param list<string> $taskNames
     * @param self::STATUS_*|null $status Per-task outcome (only set by `runOne()`).
     * @param string|null $message Human-readable detail (success notice or exception message).
     * @param class-string<\Throwable>|null $exceptionClass FQCN of the thrown exception when status is `failed`.
     *                                                     Never the throwable itself, controllers must not have to
     *                                                     serialize a `\Throwable` (M4B WP02 / FR-010).
     * @param int $failedCount Number of due tasks that threw during `run()`'s sweep (audit A7 F9).
     *                         Always `0` on results returned by `runOne()`, which reports its own
     *                         outcome via `status`/`exceptionClass` instead.
     * @param list<string> $failedTaskNames Names of the tasks counted in `failedCount`, in run order.
     */
    public function __construct(
        public int $count,
        public array $taskNames,
        public ?string $status = null,
        public ?string $message = null,
        public ?string $exceptionClass = null,
        public int $failedCount = 0,
        public array $failedTaskNames = [],
    ) {}
}
