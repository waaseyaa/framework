<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport;
use Waaseyaa\Foundation\Diagnostic\HealthChecker;
use Waaseyaa\Groups\GroupsServiceProvider;

/**
 * Standalone consumption of waaseyaa/groups: no application-level bundle
 * registrations, no Minoo code, just the framework plus the package.
 *
 * Covers the contract for a fresh install where the base `group` table must
 * exist, no subtables are created (no bundles have registered fields), and
 * HealthChecker reports no drift.
 */
#[CoversNothing]
final class StandaloneConsumptionTest extends TestCase
{
    private DBALDatabase $database;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->database->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_groups_standalone_' . uniqid();
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectRoot);
    }

    #[Test]
    public function providerRegistersBothEntityTypes(): void
    {
        $provider = new GroupsServiceProvider();
        $provider->register();

        $ids = array_map(static fn($t) => $t->id(), $provider->getEntityTypes());

        self::assertContains('group', $ids);
        self::assertContains('group_type', $ids);
    }

    #[Test]
    public function baseTableIsCreatedWithExpectedShape(): void
    {
        $type = $this->groupEntityType();

        (new SqlSchemaHandler($type, $this->database, new FieldDefinitionRegistry()))->ensureTable();

        $schema = $this->database->schema();
        self::assertTrue($schema->tableExists('group'), 'Expected base table "group" to exist.');

        $columns = array_map(
            static fn(array $col): string => (string) $col['name'],
            iterator_to_array(
                $this->database->query("PRAGMA table_info(\"group\")", []),
                false,
            ),
        );

        foreach (['gid', 'uuid', 'type', 'name', 'langcode', '_data'] as $required) {
            self::assertContains($required, $columns, sprintf('Missing required column "%s" on base group table.', $required));
        }
    }

    #[Test]
    public function noSubtablesMaterializedWhenZeroBundlesRegistered(): void
    {
        $type = $this->groupEntityType();

        (new SqlSchemaHandler($type, $this->database, new FieldDefinitionRegistry()))->ensureTable();

        $tables = array_map(
            static fn(array $row): string => (string) $row['name'],
            iterator_to_array(
                $this->database->query(
                    "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'group\\_\\_%' ESCAPE '\\'",
                    [],
                ),
                false,
            ),
        );

        self::assertSame([], $tables, 'No group__* subtables should exist for a fresh install with zero bundles.');
    }

    #[Test]
    public function healthCheckerReportsNoDriftForStandaloneInstall(): void
    {
        $type = $this->groupEntityType();
        $registry = new FieldDefinitionRegistry();
        (new SqlSchemaHandler($type, $this->database, $registry))->ensureTable();

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([$type->id() => $type]);

        $report = new BootDiagnosticReport(
            registeredTypes: [$type->id() => $type],
            disabledTypeIds: [],
            schemaCompatibility: [],
        );

        $checker = new HealthChecker(
            bootReport: $report,
            database: $this->database,
            entityTypeManager: $manager,
            projectRoot: $this->projectRoot,
            fieldRegistry: $registry,
        );

        foreach ($checker->checkSchemaDrift() as $result) {
            self::assertNotSame('fail', $result->status, sprintf(
                'Unexpected drift for standalone install: %s — %s',
                $result->name,
                $result->message,
            ));
        }
        foreach ($checker->checkRuntime() as $result) {
            self::assertNotSame('fail', $result->status, sprintf(
                'Unexpected runtime failure: %s — %s',
                $result->name,
                $result->message,
            ));
        }
    }

    private function groupEntityType(): \Waaseyaa\Entity\EntityTypeInterface
    {
        $provider = new GroupsServiceProvider();
        $provider->register();
        foreach ($provider->getEntityTypes() as $t) {
            if ($t->id() === 'group') {
                return $t;
            }
        }
        self::fail('Groups provider did not register "group" entity type.');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
