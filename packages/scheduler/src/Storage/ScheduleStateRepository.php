<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Storage;

use Waaseyaa\Database\DatabaseInterface;

final class ScheduleStateRepository
{
    private const TABLE = 'waaseyaa_schedule_state';

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function recordRun(string $taskName, string $result): void
    {
        $now = date('Y-m-d\TH:i:sP');

        // Upsert: try update first, insert if no rows affected
        $affected = $this->database->update(self::TABLE)
            ->fields([
                'last_run_at' => $now,
                'last_result' => $result,
            ])
            ->condition('task_name', $taskName)
            ->execute();

        if ($affected === 0) {
            $this->database->insert(self::TABLE)
                ->values([
                    'task_name' => $taskName,
                    'last_run_at' => $now,
                    'last_result' => $result,
                ])
                ->execute();
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
