<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Scheduler\ScheduleInterface;
use Waaseyaa\Scheduler\ScheduleRunner;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;

/**
 * Admin-only HTTP controller for the scheduler dashboard (M4B WP02).
 *
 * Exposes two actions backing `/scheduler`:
 *   - `index`   — list every registered `ScheduledTask` with its last-run
 *                 status (from `ScheduleStateRepository`) and next-run time
 *                 (computed from the cron expression). Tasks themselves are
 *                 code-defined via attributes (`ScheduleEntriesInterface`);
 *                 there is no edit UI (C-002).
 *   - `trigger` — invoke `ScheduleRunner::runOne()` for one task by name,
 *                 bypassing the cron schedule. The runner records the
 *                 outcome so the dashboard's "last run" reflects the
 *                 manual invocation.
 *
 * Access control: enforced by the route option `_role: admin` (see
 * `BuiltinRouteRegistrar`). NFR-001 — do NOT re-check the role here.
 *
 * FR-010: `trigger` never serialises a `\Throwable` directly. The runner
 * extracts `getMessage()` + `::class` into structured fields on
 * `ScheduleRunResult`; this controller forwards only those scalar fields.
 *
 * Mirrors `QueueController` (M4B WP01) for shape and naming.
 *
 * @api
 */
final class SchedulerController
{
    public function __construct(
        private readonly ScheduleInterface $schedule,
        private readonly ScheduleStateRepository $stateRepository,
        private readonly ScheduleRunner $runner,
    ) {}

    /**
     * `GET /api/scheduler/tasks` — list every registered task with its last
     * recorded run and the next run computed from the cron expression.
     *
     * `last_run_at` / `last_status` are `null` when the task has never run
     * (no row in `waaseyaa_schedule_state`). `next_run_at` is always set —
     * the cron library guarantees a next-fire date for any valid expression.
     *
     * @return array{
     *   data: list<array{
     *     name: string,
     *     description: string|null,
     *     expression: string,
     *     timezone: string|null,
     *     last_run_at: string|null,
     *     last_status: string|null,
     *     next_run_at: string
     *   }>
     * }
     */
    public function index(): array
    {
        $now = new \DateTimeImmutable();
        $rows = [];

        foreach ($this->schedule->tasks() as $task) {
            $state = $this->stateRepository->getState($task->name);
            $rows[] = [
                'name' => $task->name,
                'description' => $task->description,
                'expression' => $task->expression,
                'timezone' => $task->timezone,
                'last_run_at' => $state['last_run_at'] ?? null,
                'last_status' => $state['last_result'] ?? null,
                'next_run_at' => $task->getNextRunDate($now)->format(\DateTimeInterface::ATOM),
            ];
        }

        return ['data' => $rows];
    }

    /**
     * `POST /api/scheduler/tasks/{name}/trigger` — fire one task immediately.
     *
     * Returns 200 with a structured outcome envelope, or 404 if no task with
     * `$name` is registered. The envelope never contains a `\Throwable` — on
     * failure we surface `{status, message, exception_class}` only.
     */
    public function trigger(string $name): Response
    {
        try {
            $result = $this->runner->runOne($name, new \DateTimeImmutable());
        } catch (\InvalidArgumentException $e) {
            return self::errorResponse(404, 'Not Found', $e->getMessage());
        }

        $payload = [
            'status' => $result->status,
            'message' => $result->message,
        ];
        if ($result->exceptionClass !== null) {
            $payload['exception_class'] = $result->exceptionClass;
        }

        return new JsonResponse(
            $payload,
            200,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }

    private static function errorResponse(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => (string) $status,
                'title' => $title,
                'detail' => $detail,
            ]],
        ], $status, ['Content-Type' => 'application/vnd.api+json']);
    }
}
