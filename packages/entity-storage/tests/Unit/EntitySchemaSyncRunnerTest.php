<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\EntityStorage\SchemaSyncReport;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;

#[CoversClass(EntitySchemaSyncRunner::class)]
#[CoversClass(SchemaSyncReport::class)]
final class EntitySchemaSyncRunnerTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
    }

    #[Test]
    public function it_creates_missing_tables_and_reports_them(): void
    {
        $defs = [
            $this->makeEntityType('widget', 'Widget'),
            $this->makeEntityType('gadget', 'Gadget'),
        ];

        $report = new EntitySchemaSyncRunner($this->database)->run($defs);

        $this->assertSame(['gadget', 'widget'], $report->created, 'both tables reported created (sorted)');
        $this->assertSame([], $report->existing);
        $this->assertTrue($report->changed());
        $this->assertSame(2, $report->total());
        $this->assertFalse($report->dryRun);
        $this->assertTrue($this->database->schema()->tableExists('widget'));
        $this->assertTrue($this->database->schema()->tableExists('gadget'));
    }

    #[Test]
    public function a_second_run_is_an_idempotent_no_op(): void
    {
        $defs = [$this->makeEntityType('widget', 'Widget')];
        $runner = new EntitySchemaSyncRunner($this->database);

        $runner->run($defs);
        $report = $runner->run($defs);

        $this->assertSame([], $report->created, 'nothing created on the second run');
        $this->assertSame(['widget'], $report->existing);
        $this->assertFalse($report->changed());
        $this->assertTrue($this->database->schema()->tableExists('widget'));
    }

    #[Test]
    public function dry_run_reports_what_would_be_created_without_writing(): void
    {
        $defs = [$this->makeEntityType('widget', 'Widget')];

        $report = new EntitySchemaSyncRunner($this->database)->run($defs, dryRun: true);

        $this->assertSame(['widget'], $report->created);
        $this->assertTrue($report->dryRun);
        $this->assertFalse(
            $this->database->schema()->tableExists('widget'),
            'dry run must not create the table',
        );
    }

    #[Test]
    public function mixed_run_separates_created_from_existing(): void
    {
        $runner = new EntitySchemaSyncRunner($this->database);
        $runner->run([$this->makeEntityType('widget', 'Widget')]);

        $report = $runner->run([
            $this->makeEntityType('widget', 'Widget'),
            $this->makeEntityType('gadget', 'Gadget'),
        ]);

        $this->assertSame(['gadget'], $report->created);
        $this->assertSame(['widget'], $report->existing);
    }

    /**
     * #2732 — the reported repro: an entity type gets a new non-key field
     * (with an index) registered against an already-materialized table.
     */
    #[Test]
    public function dry_run_reports_a_column_and_index_that_would_be_added_to_an_existing_table(): void
    {
        new EntitySchemaSyncRunner($this->database)->run([$this->makeSqlColumnEntityType('report_probe', [])]);

        $evolved = $this->makeSqlColumnEntityType('report_probe', [
            'facet' => new FieldDefinition(
                name: 'facet',
                type: 'string',
                targetEntityTypeId: 'report_probe',
                fieldIndexed: true,
            ),
        ]);

        $report = new EntitySchemaSyncRunner($this->database)->run([$evolved], dryRun: true);

        $this->assertSame([], $report->created, 'the table already exists; it is not "created"');
        $this->assertSame(['report_probe'], $report->existing);
        $this->assertSame(['report_probe'], $report->altered, 'the additive column+index is real work, not a no-op');
        $this->assertSame([], $report->unchanged());
        $this->assertTrue($report->changed(), 'a dry run that would add a column must report changed() === true');
        $this->assertFalse(
            $this->database->schema()->fieldExists('report_probe', 'facet'),
            'dry run must not write the column',
        );
    }

    #[Test]
    public function apply_adds_the_column_and_index_and_reports_the_table_as_altered(): void
    {
        new EntitySchemaSyncRunner($this->database)->run([$this->makeSqlColumnEntityType('report_probe', [])]);

        $evolved = $this->makeSqlColumnEntityType('report_probe', [
            'facet' => new FieldDefinition(
                name: 'facet',
                type: 'string',
                targetEntityTypeId: 'report_probe',
                fieldIndexed: true,
            ),
        ]);

        $report = new EntitySchemaSyncRunner($this->database)->run([$evolved]);

        $this->assertSame([], $report->created);
        $this->assertSame(['report_probe'], $report->existing);
        $this->assertSame(['report_probe'], $report->altered);
        $this->assertTrue($report->changed());
        $this->assertTrue(
            $this->database->schema()->fieldExists('report_probe', 'facet'),
            'apply must actually add the column',
        );

        // A subsequent run against the now-satisfied definition is a true no-op.
        $noop = new EntitySchemaSyncRunner($this->database)->run([$evolved]);
        $this->assertSame([], $noop->altered);
        $this->assertFalse($noop->changed());
    }

    /** @param array<string, FieldDefinition> $fields */
    private function makeSqlColumnEntityType(string $id, array $fields): EntityType
    {
        return new EntityType(
            id: $id,
            label: 'Probe',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            primaryStorageBackend: 'sql-column',
            _fieldDefinitions: $fields,
        );
    }

    private function makeEntityType(string $id, string $label): EntityType
    {
        return new EntityType(
            id: $id,
            label: $label,
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
    }
}
