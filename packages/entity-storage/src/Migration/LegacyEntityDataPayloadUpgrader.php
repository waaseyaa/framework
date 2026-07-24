<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Migration;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Idempotently canonicalizes the historical empty-list `_data` shape.
 *
 * @api
 */
final readonly class LegacyEntityDataPayloadUpgrader
{
    public function __construct(
        private DatabaseInterface $database,
        private EntityTypeManagerInterface $entityTypes,
    ) {}

    public function upgrade(): LegacyEntityDataPayloadUpgradeResult
    {
        $tables = $this->entityTablesWithDataColumn();
        $scanned = 0;
        $changed = 0;
        $changedByTable = [];
        $transaction = $this->database->transaction('legacy_entity_data_payload_upgrade');

        try {
            foreach ($tables as $table) {
                $candidates = [];
                foreach ($this->database->query(
                    'SELECT ' . $this->database->quoteIdentifier('_data')
                    . ' FROM ' . $this->database->quoteIdentifier($table),
                ) as $row) {
                    ++$scanned;
                    $raw = $row['_data'] ?? null;
                    if (is_string($raw) && $this->isEmptyJsonList($raw)) {
                        $candidates[$raw] = true;
                    }
                }

                $tableChanged = 0;
                foreach (array_keys($candidates) as $raw) {
                    $tableChanged += $this->database->update($table)
                        ->fields(['_data' => '{}'])
                        ->condition('_data', $raw)
                        ->execute();
                }
                if ($tableChanged > 0) {
                    $changedByTable[$table] = $tableChanged;
                    $changed += $tableChanged;
                }
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return new LegacyEntityDataPayloadUpgradeResult($scanned, $changed, $changedByTable);
    }

    /** @return list<string> */
    private function entityTablesWithDataColumn(): array
    {
        $registered = array_fill_keys(array_keys($this->entityTypes->getDefinitions()), true);
        $tables = [];
        foreach ($this->database->schema()->listTableNames() as $table) {
            $entityType = explode('__', $table, 2)[0];
            if (isset($registered[$entityType]) && $this->database->schema()->fieldExists($table, '_data')) {
                $tables[] = $table;
            }
        }
        sort($tables);

        return $tables;
    }

    private function isEmptyJsonList(string $payload): bool
    {
        return preg_match('/\A[ \t\r\n]*\[[ \t\r\n]*\][ \t\r\n]*\z/', $payload) === 1;
    }
}
