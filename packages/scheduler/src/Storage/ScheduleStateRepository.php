<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Storage;

use Waaseyaa\Database\DatabaseInterface;

/**
 * @api
 */
final class ScheduleStateRepository
{
    private const TABLE = 'waaseyaa_schedule_state';

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function recordRun(string $taskName, string $result, ?\DateTimeInterface $now = null): void
    {
        $ts = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');

        // Portable upsert (task_name is the PRIMARY KEY). The previous
        // `INSERT OR REPLACE` is SQLite-only syntax and throws a syntax error on
        // MySQL/Postgres. Use the platform-agnostic query builders inside a
        // transaction: UPDATE first (the common case — the task already has a
        // state row), and INSERT only when no row was affected. The scheduler
        // serialises runs of a given task behind its overlap lock, so there is a
        // single writer per task_name and no insert/insert race in practice.
        $transaction = $this->database->transaction();

        try {
            $affected = $this->database->update(self::TABLE)
                ->fields(['last_run_at' => $ts, 'last_result' => $result])
                ->condition('task_name', $taskName)
                ->execute();

            if ($affected === 0) {
                $this->database->insert(self::TABLE)
                    ->values([
                        'task_name' => $taskName,
                        'last_run_at' => $ts,
                        'last_result' => $result,
                    ])
                    ->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * @return array{task_name: string, last_run_at: string, last_result: string}|null
     */
    public function getState(string $taskName): ?array
    {
        $rows = $this->database->select(self::TABLE, 'ss')
            ->fields('ss', ['task_name', 'last_run_at', 'last_result'])
            ->condition('task_name', $taskName)
            ->execute();

        foreach ($rows as $row) {
            return [
                'task_name' => $row['task_name'],
                'last_run_at' => $row['last_run_at'],
                'last_result' => $row['last_result'],
            ];
        }

        return null;
    }
}
