<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Diagnostic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport;
use Waaseyaa\Foundation\Diagnostic\HealthChecker;
use Waaseyaa\Foundation\Diagnostic\HealthCheckResult;

#[CoversClass(HealthChecker::class)]
#[CoversClass(HealthCheckResult::class)]
final class HealthCheckerTest extends TestCase
{
    private DBALDatabase $database;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_health_test_' . uniqid();
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectRoot);
    }

    // --- Boot checks ---

    #[Test]
    public function bootCheckPassesWithEnabledTypes(): void
    {
        $checker = $this->createChecker(withTypes: true);
        $results = $checker->checkBoot();

        $this->assertCount(1, $results);
        $this->assertSame('pass', $results[0]->status);
        $this->assertSame('Entity types', $results[0]->name);
    }

    #[Test]
    public function bootCheckFailsWithNoTypes(): void
    {
        $checker = $this->createChecker(withTypes: false);
        $results = $checker->checkBoot();

        $this->assertCount(1, $results);
        $this->assertSame('fail', $results[0]->status);
    }

    // --- Runtime checks ---

    #[Test]
    public function databaseCheckPassesWhenAccessible(): void
    {
        $checker = $this->createChecker(withTypes: true);
        $results = $checker->checkRuntime();

        $dbResult = $this->findResult($results, 'Database');
        $this->assertNotNull($dbResult);
        $this->assertSame('pass', $dbResult->status);
    }

    #[Test]
    public function storageDirectoryCheckPassesWhenExists(): void
    {
        $checker = $this->createChecker(withTypes: true);
        $results = $checker->checkRuntime();

        $storageResult = $this->findResult($results, 'Storage directory');
        $this->assertNotNull($storageResult);
        $this->assertSame('pass', $storageResult->status);
    }

    #[Test]
    public function storageDirectoryCheckWarnsWhenMissing(): void
    {
        $this->removeDir($this->projectRoot . '/storage');
        $checker = $this->createChecker(withTypes: true);
        $results = $checker->checkRuntime();

        $storageResult = $this->findResult($results, 'Storage directory');
        $this->assertNotNull($storageResult);
        $this->assertSame('warn', $storageResult->status);
    }

    // --- Schema drift checks ---

    #[Test]
    public function schemaDriftPassesWithCorrectSchema(): void
    {
        $nodeType = $this->makeContentEntityType('node');
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['node' => $nodeType]);

        // Create table with correct schema.
        $schemaHandler = new SqlSchemaHandler($nodeType, $this->database);
        $schemaHandler->ensureTable();

        $checker = $this->createCheckerWith($manager);
        $results = $checker->checkSchemaDrift();

        $this->assertCount(1, $results);
        $this->assertSame('pass', $results[0]->status);
    }

    #[Test]
    public function schemaDriftDetectsTypeMismatch(): void
    {
        // Create a config entity type (expects TEXT PK).
        $configType = $this->makeConfigEntityType('node_type');
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['node_type' => $configType]);

        // Create table with WRONG schema (INTEGER instead of TEXT for ID).
        $this->database->schema()->createTable('node_type', [
            'fields' => [
                'type' => ['type' => 'serial', 'not null' => true],
                'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'default' => ''],
                'langcode' => ['type' => 'varchar', 'length' => 12, 'not null' => true, 'default' => 'en'],
                '_data' => ['type' => 'text', 'not null' => true, 'default' => '{}'],
            ],
            'primary key' => ['type'],
        ]);

        $checker = $this->createCheckerWith($manager);
        $results = $checker->checkSchemaDrift();

        $driftResult = $this->findResult($results, 'Schema: node_type');
        $this->assertNotNull($driftResult, 'Expected schema drift for node_type');
        $this->assertSame('fail', $driftResult->status);
        $this->assertNotEmpty($driftResult->context['drift']);
    }

    #[Test]
    public function schemaDriftSkipsNonExistentTables(): void
    {
        $nodeType = $this->makeContentEntityType('node');
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['node' => $nodeType]);

        // Don't create the table — simulate lazy initialization.
        $checker = $this->createCheckerWith($manager);
        $results = $checker->checkSchemaDrift();

        $this->assertCount(1, $results);
        $this->assertSame('pass', $results[0]->status);
    }

    // --- Ingestion checks ---

    #[Test]
    public function ingestionCheckPassesWithNoEntries(): void
    {
        $checker = $this->createChecker(withTypes: true);
        $results = $checker->checkIngestion();

        $this->assertCount(1, $results);
        $this->assertSame('pass', $results[0]->status);
    }

    #[Test]
    public function ingestionCheckPassesWithLowErrorRate(): void
    {
        // Write 10 accepted, 1 rejected.
        $logFile = $this->projectRoot . '/storage/framework/ingestion.jsonl';
        for ($i = 0; $i < 10; $i++) {
            file_put_contents($logFile, json_encode([
                'status' => 'accepted',
                'logged_at' => '2026-03-08T12:00:00+00:00',
            ]) . "\n", FILE_APPEND);
        }
        file_put_contents($logFile, json_encode([
            'status' => 'rejected',
            'logged_at' => '2026-03-08T12:00:00+00:00',
            'errors' => [['code' => 'PAYLOAD_FIELD_MISSING']],
        ]) . "\n", FILE_APPEND);

        $checker = $this->createChecker(withTypes: true);
        $results = $checker->checkIngestion();

        $errorRateResult = $this->findResult($results, 'Ingestion error rate');
        $this->assertNotNull($errorRateResult);
        $this->assertSame('pass', $errorRateResult->status);
    }

    #[Test]
    public function ingestionCheckWarnsWithHighErrorRate(): void
    {
        $logFile = $this->projectRoot . '/storage/framework/ingestion.jsonl';
        // 2 accepted, 8 rejected = 80% error rate.
        for ($i = 0; $i < 2; $i++) {
            file_put_contents($logFile, json_encode([
                'status' => 'accepted',
                'logged_at' => '2026-03-08T12:00:00+00:00',
            ]) . "\n", FILE_APPEND);
        }
        for ($i = 0; $i < 8; $i++) {
            file_put_contents($logFile, json_encode([
                'status' => 'rejected',
                'logged_at' => '2026-03-08T12:00:00+00:00',
                'errors' => [],
            ]) . "\n", FILE_APPEND);
        }

        $checker = $this->createChecker(withTypes: true);
        $results = $checker->checkIngestion();

        $errorRateResult = $this->findResult($results, 'Ingestion error rate');
        $this->assertNotNull($errorRateResult);
        $this->assertSame('warn', $errorRateResult->status);
    }

    // --- runAll ---

    #[Test]
    public function runAllReturnsAllChecks(): void
    {
        $checker = $this->createChecker(withTypes: true);
        $results = $checker->runAll();

        $this->assertNotEmpty($results);
        $names = array_map(static fn(HealthCheckResult $r) => $r->name, $results);
        $this->assertContains('Entity types', $names);
        $this->assertContains('Database', $names);
        $this->assertContains('Storage directory', $names);
        $this->assertContains('Cache directory', $names);
    }

    // --- Helpers ---

    private function createChecker(bool $withTypes): HealthChecker
    {
        $types = [];
        if ($withTypes) {
            $types['node'] = $this->makeContentEntityType('node');
        }

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn($types);

        return $this->createCheckerWith($manager);
    }

    private function createCheckerWith(EntityTypeManagerInterface $manager): HealthChecker
    {
        $definitions = $manager->getDefinitions();
        $bootReport = new BootDiagnosticReport(
            registeredTypes: $definitions,
            disabledTypeIds: [],
            schemaCompatibility: [],
        );

        return new HealthChecker(
            bootReport: $bootReport,
            database: $this->database,
            entityTypeManager: $manager,
            projectRoot: $this->projectRoot,
        );
    }

    private function makeContentEntityType(string $id): EntityType
    {
        return new EntityType(
            id: $id,
            label: ucfirst($id),
            class: \stdClass::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );
    }

    private function makeConfigEntityType(string $id): EntityType
    {
        return new EntityType(
            id: $id,
            label: ucfirst($id),
            class: \stdClass::class,
            keys: ['id' => 'type', 'label' => 'name'],
        );
    }

    /** @param list<HealthCheckResult> $results */
    private function findResult(array $results, string $name): ?HealthCheckResult
    {
        foreach ($results as $r) {
            if ($r->name === $name) {
                return $r;
            }
        }
        return null;
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
