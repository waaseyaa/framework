<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Security;

require_once __DIR__ . '/../../Fixtures/AppProfileEntity.php';

use Doctrine\DBAL\Schema\Column;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\Preflight\FieldAccessPreflightScanner;
use Waaseyaa\Foundation\Kernel\Preflight\LiveEntitySchemaFingerprint;

/**
 * #2171: a reserved-word column must not become a phantom, unclassifiable
 * field key.
 *
 * Doctrine keys `listTableColumns()` by the **quoted** name whenever the
 * identifier needs quoting — a column named `key` arrives under the array key
 * `'"key"'` while the `Column` object's `getName()` is the canonical `'key'`.
 * `DatabaseFieldAccessInventoryScanner` read `array_keys(...)`, so it emitted a
 * live key of `<type>|*|"key"` that no field definition could ever classify.
 * The preflight therefore reported `unclassified_entries: ['<type>|*|"key"']`
 * and `ready: false` **forever**, for every consumer with a reserved-word
 * column, with no remedy that was not a schema change forced by a framework
 * bug.
 *
 * This is the same defect as #2163 (`DBALSchema::fieldExists()`) at callsites
 * that fix did not reach. The third instance of one mistake is why the safe
 * accessor is now the only one used — see `TableColumnNames`.
 */
final class ReservedWordColumnPreflightTest extends TestCase
{
    /**
     * Reserved across the platforms the framework targets. `key` is the one
     * RHT Circle actually ships; the rest guard the general claim.
     *
     * @return iterable<string, array{string}>
     */
    public static function reservedWords(): iterable
    {
        yield 'key' => ['key'];
        yield 'order' => ['order'];
        yield 'group' => ['group'];
        yield 'index' => ['index'];
    }

    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('reservedWords')]
    public function a_reserved_word_column_is_inventoried_under_its_canonical_name(string $word): void
    {
        $database = $this->databaseWithReservedColumn($word);
        $manager = $this->managerFor($word);

        $inventory = new DatabaseFieldAccessInventoryScanner($database, $manager)->scan('candidate-1');

        self::assertContains(
            'monitor_source|*|' . $word,
            $inventory->liveKeys,
            'the canonical column name must reach the inventory',
        );
        self::assertNotContains(
            'monitor_source|*|"' . $word . '"',
            $inventory->liveKeys,
            'a quoted identifier literal is not a column name and must never be a live key',
        );

        // The general form of the claim: nothing in the inventory is quoted.
        foreach ($inventory->liveKeys as $liveKey) {
            self::assertStringNotContainsString('"', $liveKey, 'no live key may carry quote characters');
        }
    }

    #[Test]
    #[DataProvider('reservedWords')]
    public function a_classified_reserved_word_field_leaves_the_preflight_ready(string $word): void
    {
        // The end-to-end consequence, and the single fact that was false
        // before #2171: an application that classifies every field it declares
        // can actually reach activation.
        $database = $this->databaseWithReservedColumn($word);
        $manager = $this->managerFor($word);

        $inventory = new DatabaseFieldAccessInventoryScanner($database, $manager)->scan('candidate-1');
        $result = new FieldAccessPreflightScanner()->scan($manager, $inventory);

        self::assertSame([], $result->data->unclassifiedEntries, 'no field may be unclassifiable');
        self::assertTrue($result->ready, 'a fully classified schema must be ready for activation');
    }

    #[Test]
    #[DataProvider('reservedWords')]
    public function the_boot_side_and_artifact_side_fingerprints_agree(string $word): void
    {
        // The lockstep that makes the fix safe to ship. `LiveEntitySchemaFingerprint`
        // (boot) and `DatabaseFieldAccessInventoryScanner` (artifact) both derive
        // a fingerprint from the same tables. They were consistently WRONG before
        // this fix — both quoted — so they agreed by accident. Correcting only one
        // side would make every production boot fail as "stale for the current
        // schema" on any entity with a reserved-word column.
        $database = $this->databaseWithReservedColumn($word);
        $manager = $this->managerFor($word);

        $artifactSide = new DatabaseFieldAccessInventoryScanner($database, $manager)
            ->scan('candidate-1')
            ->schemaFingerprint;
        $bootSide = LiveEntitySchemaFingerprint::compute(
            $database,
            array_keys($manager->getDefinitions()),
        );

        self::assertSame($bootSide, $artifactSide, 'boot and artifact fingerprints must not diverge');
    }

    #[Test]
    public function the_fingerprint_still_distinguishes_a_genuinely_different_schema(): void
    {
        // Mutation control for the test above: if `compute()` and `scan()` both
        // returned a constant, or ignored columns entirely, the agreement
        // assertion would pass vacuously. A real schema difference must still
        // move the fingerprint.
        $withColumn = $this->databaseWithReservedColumn('key');
        $manager = $this->managerFor('key');
        $baseline = LiveEntitySchemaFingerprint::compute($withColumn, array_keys($manager->getDefinitions()));

        $withExtra = $this->databaseWithReservedColumn('key');
        $withExtra->query('ALTER TABLE monitor_source ADD COLUMN extra TEXT');
        $changed = LiveEntitySchemaFingerprint::compute($withExtra, array_keys($manager->getDefinitions()));

        self::assertNotSame($baseline, $changed, 'an added column must change the fingerprint');
    }

    #[Test]
    public function doctrine_still_keys_the_column_by_its_quoted_name(): void
    {
        // Pins the upstream behaviour the fix exists to absorb. If a future
        // Doctrine release stops quoting these keys, this test fails and tells
        // the next reader that the workaround's premise changed — rather than
        // the fix silently becoming dead code.
        $database = $this->databaseWithReservedColumn('key');
        $columns = $database->getConnection()->createSchemaManager()->listTableColumns('monitor_source');

        self::assertArrayHasKey('"key"', $columns, 'Doctrine keys a reserved word by its quoted name');
        self::assertSame(
            'key',
            $columns['"key"']->getName(),
            'while getName() remains canonical — which is why getName() is the authority',
        );
        self::assertContainsOnlyInstancesOf(Column::class, $columns);
    }

    // ------------------------------------------------------------------

    private function databaseWithReservedColumn(string $word): DBALDatabase
    {
        $database = DBALDatabase::createSqlite();
        $database->query(sprintf(
            'CREATE TABLE monitor_source (id INTEGER PRIMARY KEY, uuid TEXT, "%s" TEXT, label TEXT)',
            $word,
        ));
        $database->query(sprintf(
            'INSERT INTO monitor_source (id, uuid, "%s", label) VALUES (1, \'u1\', \'sagamok\', \'Public site\')',
            $word,
        ));

        return $database;
    }

    private function managerFor(string $word): EntityTypeManager
    {
        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: $registry);
        $manager->registerEntityType(new EntityType(
            id: 'monitor_source',
            label: 'Monitor source',
            class: DatabasePreflightProfile::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
            _fieldDefinitions: [
                $word => new FieldDefinition(
                    $word,
                    'string',
                    targetEntityTypeId: 'monitor_source',
                    read: FieldReadLevel::Public,
                ),
                'label' => new FieldDefinition(
                    'label',
                    'string',
                    targetEntityTypeId: 'monitor_source',
                    read: FieldReadLevel::Public,
                ),
            ],
        ));

        return $manager;
    }
}
