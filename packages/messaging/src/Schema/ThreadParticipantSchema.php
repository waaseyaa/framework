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
}
