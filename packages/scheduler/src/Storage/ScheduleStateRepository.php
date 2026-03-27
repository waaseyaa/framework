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

        // Atomic upsert — task_name is PRIMARY KEY
        $this->database->query(
            'INSERT OR REPLACE INTO ' . self::TABLE . ' (task_name, last_run_at, last_result) VALUES (?, ?, ?)',
            [$taskName, $now, $result],
        );
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
