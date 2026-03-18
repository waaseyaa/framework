<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Database\DatabaseInterface;

final class RelationshipSchemaManager
{
    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function ensure(): void
    {
        $schema = $this->database->schema();
        if (!$schema->tableExists('relationship')) {
            return;
        }

        $this->ensureColumns();
        $this->ensureIndexes();
    }

    private function ensureColumns(): void
    {
        $schema = $this->database->schema();
        $columns = [
            'from_entity_type' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
            'from_entity_id' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
            'to_entity_type' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
            'to_entity_id' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
            'directionality' => ['type' => 'varchar', 'length' => 32, 'not null' => true, 'default' => 'directed'],
            'status' => ['type' => 'int', 'not null' => true, 'default' => 1],
            'weight' => ['type' => 'float'],
            'start_date' => ['type' => 'int'],
            'end_date' => ['type' => 'int'],
            'confidence' => ['type' => 'float'],
            'source_ref' => ['type' => 'varchar', 'length' => 255],
            'notes' => ['type' => 'text'],
        ];

        foreach ($columns as $name => $spec) {
            if ($schema->fieldExists('relationship', $name)) {
                continue;
            }
            $schema->addField('relationship', $name, $spec);
        }
    }

    private function ensureIndexes(): void
    {
        $schema = $this->database->schema();
        $indexes = [
            'relationship_from_status_idx' => ['from_entity_type', 'from_entity_id', 'status'],
            'relationship_to_status_idx' => ['to_entity_type', 'to_entity_id', 'status'],
            'relationship_type_status_idx' => ['relationship_type', 'status'],
            'relationship_temporal_idx' => ['start_date', 'end_date'],
        ];

        foreach ($indexes as $name => $fields) {
            if ($this->indexExists($name)) {
                continue;
            }

            $schema->addIndex('relationship', $name, $fields);
        }
    }

    private function indexExists(string $name): bool
    {
        $rows = $this->database->query(
            "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type = 'index' AND name = ?",
            [$name],
        );
        $data = iterator_to_array($rows, false);

        return (int) ($data[0]['cnt'] ?? 0) > 0;
    }
}
