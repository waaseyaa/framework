<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\AsciiStringType;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallIntType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\TableBuilder;

/**
 * Owns ai-vector's rebuildable `embeddings` projection (FW-AIV-PERSIST-01).
 *
 * Creates the table when it is absent. A table with the expected shape,
 * including one the pre-migration runtime code created, is adopted in place
 * with every row. Any other shape is refused: nothing is dropped, recreated,
 * renamed or emptied, and the coordinator rolls the transition back.
 */
return new class extends Migration {
    private const string TABLE = 'embeddings';

    /** Column name => required affinity, in declaration order. */
    private const array COLUMNS = [
        'entity_type' => 'text',
        'entity_id' => 'text',
        'vector' => 'text',
        'updated_at' => 'integer',
    ];

    private const array PRIMARY_KEY = ['entity_type', 'entity_id'];

    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, static function (TableBuilder $table): void {
                $table->string('entity_type', 128);
                $table->string('entity_id', 255);
                $table->text('vector');
                $table->integer('updated_at');
                $table->primary(self::PRIMARY_KEY);
            });

            return;
        }

        $differences = $this->differences($schema->getConnection()->createSchemaManager()->introspectTable(self::TABLE));
        if ($differences !== []) {
            throw new \RuntimeException(sprintf(
                '[AIV-DB001] The existing `%s` table does not have the schema ai-vector owns: %s. Nothing was changed. '
                . 'Recovery (FW-AIV-PERSIST-01, docs/specs/ai-integration.md "Adopting a runtime-created embeddings table"): '
                . 'back up the database, then move this table and its data aside yourself so the migration can create the expected table. '
                . 'Embeddings are rebuildable with `semantic:refresh`.',
                self::TABLE,
                implode('; ', $differences),
            ));
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only: the projection is rebuildable and never dropped by a migration.
    }

    /** @return list<string> */
    private function differences(Table $table): array
    {
        $differences = [];
        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[strtolower($column->getName())] = $column;
        }

        foreach (self::COLUMNS as $name => $affinity) {
            $column = $columns[$name] ?? null;
            if ($column === null) {
                $differences[] = sprintf('missing column "%s"', $name);
                continue;
            }
            if (!$column->getNotnull()) {
                $differences[] = sprintf('column "%s" must be NOT NULL', $name);
            }
            $type = $column->getType();
            $matches = $affinity === 'text'
                ? $type instanceof StringType || $type instanceof TextType || $type instanceof AsciiStringType
                : $type instanceof IntegerType || $type instanceof BigIntType || $type instanceof SmallIntType;
            if (!$matches) {
                $differences[] = sprintf('column "%s" must have %s affinity', $name, $affinity);
            }
        }

        foreach (array_keys($columns) as $name) {
            if (!array_key_exists($name, self::COLUMNS)) {
                $differences[] = sprintf('unexpected column "%s"', $name);
            }
        }

        $primaryKey = array_map(
            static fn($name): string => strtolower($name->getIdentifier()->getValue()),
            $table->getPrimaryKeyConstraint()?->getColumnNames() ?? [],
        );
        if ($primaryKey !== self::PRIMARY_KEY) {
            $differences[] = sprintf('primary key must be (%s), found (%s)', implode(', ', self::PRIMARY_KEY), implode(', ', $primaryKey));
        }

        return $differences;
    }
};
