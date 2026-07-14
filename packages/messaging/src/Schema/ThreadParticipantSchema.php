<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging\Schema;

use Waaseyaa\Database\DBALDatabase;

/** Materializes the participant identity columns and their uniqueness rule. */
final class ThreadParticipantSchema
{
    private const TABLE = 'thread_participant';
    private const UNIQUE_KEY = 'thread_participant_thread_user_unique';

    public function __construct(
        private readonly DBALDatabase $database,
    ) {}

    public function ensureTable(): void
    {
        $schema = $this->database->schema();
        if (!$schema->tableExists(self::TABLE)) {
            return;
        }

        $needsColumns = !$schema->fieldExists(self::TABLE, 'thread_id')
            || !$schema->fieldExists(self::TABLE, 'user_id');
        $indexes = $this->database->getConnection()->createSchemaManager()->listTableIndexes(self::TABLE);
        if (!$needsColumns && isset($indexes[self::UNIQUE_KEY])) {
            return;
        }

        $transaction = $this->database->transaction();
        try {
            foreach (['thread_id', 'user_id'] as $field) {
                if (!$schema->fieldExists(self::TABLE, $field)) {
                    $schema->addField(self::TABLE, $field, [
                        'type' => 'int',
                        'not null' => true,
                        'default' => 0,
                    ]);
                }
            }

            $this->backfillIdentityColumns();
            $this->mergeDuplicateParticipants();

            $indexes = $this->database->getConnection()->createSchemaManager()->listTableIndexes(self::TABLE);
            if (!isset($indexes[self::UNIQUE_KEY])) {
                $schema->addUniqueKey(self::TABLE, self::UNIQUE_KEY, ['thread_id', 'user_id']);
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function backfillIdentityColumns(): void
    {
        foreach ($this->database->select(self::TABLE)->fields(self::TABLE, ['tpid', '_data'])->execute() as $row) {
            try {
                $data = \json_decode((string) ($row['_data'] ?? ''), true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!\is_array($data) || !isset($data['thread_id'], $data['user_id'])) {
                continue;
            }

            $this->database->update(self::TABLE)
                ->fields(['thread_id' => (int) $data['thread_id'], 'user_id' => (int) $data['user_id']])
                ->condition('tpid', (string) $row['tpid'])
                ->execute();
        }
    }

    /**
     * Heal legacy duplicate memberships before installing the unique key.
     *
     * The lowest tpid is the deterministic survivor. Duplicate rows carry
     * meaningful membership state, so preserve the strongest role, earliest
     * join, and furthest read position rather than silently discarding them.
     */
    private function mergeDuplicateParticipants(): void
    {
        $groups = [];
        $rows = $this->database->select(self::TABLE)
            ->fields(self::TABLE, ['tpid', 'thread_id', 'user_id', 'role', '_data'])
            ->execute();
        foreach ($rows as $row) {
            $threadId = (int) ($row['thread_id'] ?? 0);
            $userId = (int) ($row['user_id'] ?? 0);
            if ($threadId <= 0 || $userId <= 0) {
                continue;
            }
            $groups[$threadId . ':' . $userId][] = $row;
        }

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            usort($group, static fn(array $left, array $right): int => (int) $left['tpid'] <=> (int) $right['tpid']);
            $survivor = array_shift($group);
            if (!is_array($survivor)) {
                continue;
            }
            $data = $this->decodeData($survivor['_data'] ?? null);
            $role = (string) ($survivor['role'] ?? 'member');

            foreach ($group as $duplicate) {
                $data = $this->mergeParticipantData($data, $this->decodeData($duplicate['_data'] ?? null));
                if (($duplicate['role'] ?? null) === 'owner') {
                    $role = 'owner';
                }
                $this->database->delete(self::TABLE)
                    ->condition('tpid', (string) $duplicate['tpid'])
                    ->execute();
            }

            $this->database->update(self::TABLE)
                ->fields(['role' => $role, '_data' => json_encode($data, JSON_THROW_ON_ERROR)])
                ->condition('tpid', (string) $survivor['tpid'])
                ->execute();
        }
    }

    /** @return array<string, mixed> */
    private function decodeData(mixed $encoded): array
    {
        try {
            $data = json_decode(is_string($encoded) ? $encoded : '', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $survivor
     * @param array<string, mixed> $duplicate
     * @return array<string, mixed>
     */
    private function mergeParticipantData(array $survivor, array $duplicate): array
    {
        $joined = array_filter(
            [$survivor['joined_at'] ?? null, $duplicate['joined_at'] ?? null],
            static fn(mixed $value): bool => is_int($value) || ctype_digit((string) $value),
        );
        if ($joined !== []) {
            $survivor['joined_at'] = min(array_map('intval', $joined));
        }

        $lastRead = array_filter(
            [$survivor['last_read_at'] ?? null, $duplicate['last_read_at'] ?? null],
            static fn(mixed $value): bool => is_int($value) || ctype_digit((string) $value),
        );
        if ($lastRead !== []) {
            $survivor['last_read_at'] = max(array_map('intval', $lastRead));
        }

        return $survivor;
    }
}
