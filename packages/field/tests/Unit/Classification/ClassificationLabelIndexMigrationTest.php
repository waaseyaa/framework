<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Proves the 000005 migration introspects live tables and creates an index on
 * every `classification_label` column it finds, while leaving unrelated
 * tables untouched. Idempotency (running `up()` twice) is also verified.
 *
 * Uses #[CoversNothing] because the subject is a migration file, not a class.
 */
#[CoversNothing]
final class ClassificationLabelIndexMigrationTest extends TestCase
{
    #[Test]
    public function upIndexesClassificationLabelColumnOnMatchingTablesOnly(): void
    {
        $db   = DBALDatabase::createSqlite();
        $conn = $db->getConnection();

        // One table WITH the column, one WITHOUT.
        $conn->executeStatement(
            'CREATE TABLE docs (id INTEGER PRIMARY KEY, classification_label VARCHAR(255))',
        );
        $conn->executeStatement(
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name VARCHAR(255))',
        );

        $schema    = new SchemaBuilder($conn);
        $migration = require dirname(__DIR__, 3) . '/migrations/2026_05_25_000005_index_classification_label.php';
        $migration->up($schema);

        $sm       = $conn->createSchemaManager();
        $docsIdxs = $sm->listTableIndexes('docs');

        $found = false;
        foreach ($docsIdxs as $idx) {
            if ($idx->getColumns() === ['classification_label']) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected an index covering docs.classification_label after migration');

        // The widgets table must not gain a classification_label index.
        $widgetIdxs = $sm->listTableIndexes('widgets');
        foreach ($widgetIdxs as $idx) {
            self::assertNotEquals(
                ['classification_label'],
                $idx->getColumns(),
                'widgets must not gain a classification_label index — it has no such column',
            );
        }
    }

    #[Test]
    public function upIsIdempotentRunningTwiceDoesNotThrow(): void
    {
        $db   = DBALDatabase::createSqlite();
        $conn = $db->getConnection();

        $conn->executeStatement(
            'CREATE TABLE articles (id INTEGER PRIMARY KEY, classification_label VARCHAR(255))',
        );

        $schema    = new SchemaBuilder($conn);
        $migration = require dirname(__DIR__, 3) . '/migrations/2026_05_25_000005_index_classification_label.php';

        $migration->up($schema);
        $migration->up($schema); // second run must not throw

        $this->addToAssertionCount(1);
    }
}
