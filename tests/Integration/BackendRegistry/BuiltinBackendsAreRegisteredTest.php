<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\BackendRegistry;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\Tests\Integration\BackendRegistry\Fixtures\BackendRegistryEntityProvider;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

/**
 * #2160: the framework's two built-in field-storage backends must actually be
 * registered in a booted application.
 *
 * Nothing implemented `HasFieldStorageBackendsV2Interface` outside test
 * fixtures, so `BackendRegistrarFactory` built an **empty** registrar in every
 * real application. It stayed invisible because `DefinitionValidator` reaches
 * `BackendResolver::resolve()` — the registrar's only live consumer — only for
 * `isIndexed()` fields, and until #2157 made `indexed: true` declarable from
 * `#[Field]`, no entity type had one.
 *
 * This test therefore boots a **real kernel against a real project root** and
 * runs the real `db:init`. It deliberately does not construct `EntitySchemaSync`
 * by hand: #2157's suite did exactly that, passed, and shipped the defect.
 *
 * On alpha.280 every assertion here fails at `db:init`, which aborts with
 * `UnknownBackendException` before creating a single table.
 */
#[CoversNothing]
final class BuiltinBackendsAreRegisteredTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_backend_registry_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        symlink($this->repoRoot . '/packages', $this->projectRoot . '/packages');

        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);
        $this->writeAutoloadWrapper();

        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/backend-registry-regression',
            'autoload' => ['psr-4' => ['Waaseyaa\\Tests\\' => $this->repoRoot . '/tests/']],
            'extra' => ['waaseyaa' => ['providers' => [BackendRegistryEntityProvider::class]]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\nreturn [];\n");

        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'database' => '{$databasePath}',
                'environment' => 'local',
                'app' => ['url' => 'http://localhost', 'name' => 'Backend registry regression'],
            ];
            PHP);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->projectRoot)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isFile() || $item->isLink() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    // ------------------------------------------------------------------
    // Both ids resolve through the real kernel
    // ------------------------------------------------------------------

    #[Test]
    public function both_reserved_backend_ids_resolve_in_a_booted_kernel(): void
    {
        $this->runPhase('db-init');
        $report = $this->resolveReport();

        self::assertTrue(
            $report['provider_discovered'],
            'the framework backend provider must be discovered through extra.waaseyaa.providers',
        );
        self::assertTrue($report['has_sql_blob'], 'sql-blob must be registered');
        self::assertTrue($report['has_sql_column'], 'sql-column must be registered');
    }

    #[Test]
    public function backend_resolver_returns_the_declared_backend_for_each_entity_type(): void
    {
        // The precise call that threw UnknownBackendException on alpha.280.
        $this->runPhase('db-init');
        $report = $this->resolveReport();

        self::assertSame(ReservedBackendIds::SQL_COLUMN, $report['resolved']['registry_column_entity']);
        self::assertSame(ReservedBackendIds::SQL_BLOB, $report['resolved']['registry_blob_entity']);
    }

    #[Test]
    public function each_backend_registers_exactly_one_gateway_under_its_own_class(): void
    {
        $this->runPhase('db-init');
        $fingerprints = $this->resolveReport()['fingerprints'];

        self::assertArrayHasKey(ReservedBackendIds::SQL_BLOB, $fingerprints);
        self::assertArrayHasKey(ReservedBackendIds::SQL_COLUMN, $fingerprints);
        self::assertStringStartsWith('Waaseyaa\EntityStorage\Backend\SqlBlobBackend:', $fingerprints[ReservedBackendIds::SQL_BLOB]);
        self::assertStringStartsWith('Waaseyaa\EntityStorage\Backend\SqlColumnBackend:', $fingerprints[ReservedBackendIds::SQL_COLUMN]);
    }

    // ------------------------------------------------------------------
    // db:init produces the physical shape
    // ------------------------------------------------------------------

    #[Test]
    public function db_init_completes_for_an_attribute_defined_column_backed_entity(): void
    {
        // The headline regression: on alpha.280 this exits non-zero with
        // 'Backend id "sql-column" is not registered'.
        $process = $this->runPhase('db-init');

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringNotContainsString('UnknownBackendException', $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function declared_column_fields_are_materialised_as_real_columns(): void
    {
        $this->runPhase('db-init');
        $columns = $this->columns('registry_column_entity');

        foreach (['title', 'source_key', 'last_seen', 'note'] as $field) {
            self::assertContains($field, $columns, "{$field} declares Column storage on a sql-column type");
        }
    }

    #[Test]
    public function indexed_true_creates_the_requested_physical_indexes(): void
    {
        $this->runPhase('db-init');

        $leading = [];
        foreach ($this->indexes('registry_column_entity') as $index) {
            $columns = $this->indexColumns($index);
            if ($columns !== []) {
                $leading[$columns[0]][] = $index;
            }
        }

        self::assertArrayHasKey('source_key', $leading, 'indexed: true must produce an index leading with the column');
        self::assertArrayHasKey('last_seen', $leading, 'indexed: true must produce an index leading with the column');
        self::assertNotSame(
            $leading['source_key'][0],
            $leading['last_seen'][0],
            'each indexed field needs its own index',
        );
    }

    #[Test]
    public function a_column_field_that_is_not_indexed_receives_no_index(): void
    {
        $this->runPhase('db-init');
        $indexes = $this->indexes('registry_column_entity');

        // Without this the assertion is vacuous: when db:init aborts, the table
        // does not exist, PRAGMA returns nothing, and the loop below never runs.
        // That is exactly how this test reported "risky, no assertions" instead
        // of failing when run against the unfixed code.
        self::assertNotEmpty($indexes, 'the table must exist and carry indexes before this can mean anything');

        foreach ($indexes as $index) {
            self::assertNotSame(['note'], $this->indexColumns($index), 'only declared-indexed fields get indexes');
        }
    }

    // ------------------------------------------------------------------
    // Backward-compatibility control
    // ------------------------------------------------------------------

    #[Test]
    public function a_default_entity_type_keeps_the_historical_blob_shape(): void
    {
        // Declares nothing new, so nothing about it may change. If this fails,
        // the fix has altered existing applications.
        $this->runPhase('db-init');
        $columns = $this->columns('registry_blob_entity');

        self::assertContains('_data', $columns, 'the default backend must still create the JSON blob');
        self::assertNotContains('facet', $columns, 'a non-key field must still live inside _data');
        self::assertSame([], array_values(array_filter(
            $this->indexes('registry_blob_entity'),
            fn(string $i): bool => $this->indexColumns($i) === ['facet'],
        )), 'a blob-backed field must not acquire an index');
    }

    #[Test]
    public function repeated_db_init_is_idempotent(): void
    {
        $this->runPhase('db-init');
        $before = [$this->columns('registry_column_entity'), $this->indexes('registry_column_entity')];

        $this->runPhase('db-init');
        $this->runPhase('db-init');

        self::assertSame($before, [$this->columns('registry_column_entity'), $this->indexes('registry_column_entity')]);
    }

    // ------------------------------------------------------------------
    // The registry instances refuse IO rather than guessing a table
    // ------------------------------------------------------------------

    #[Test]
    public function a_registry_built_backend_refuses_read_write_and_delete(): void
    {
        // BackendRegistrar keys gateways by backend id alone and constructs
        // providers with `new $fqcn()`, so a registered instance cannot carry an
        // entity table binding. It must say so rather than silently issuing SQL
        // against an empty table name.
        foreach ([
            \Waaseyaa\EntityStorage\Backend\SqlBlobBackend::forQuerySupport(),
            \Waaseyaa\EntityStorage\Backend\SqlColumnBackend::forQuerySupport(),
        ] as $backend) {
            $reflection = new \ReflectionMethod($backend, 'requireBinding');

            foreach (['read', 'write', 'delete'] as $operation) {
                try {
                    $reflection->invoke($backend, $operation);
                    self::fail(sprintf('%s must refuse "%s" without a table binding', $backend::class, $operation));
                } catch (\LogicException $e) {
                    self::assertStringContainsString($operation, $e->getMessage());
                    self::assertStringContainsString($backend->id(), $e->getMessage());
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function runPhase(string $phase): Process
    {
        $process = new Process(
            [PHP_BINARY, __DIR__ . '/Fixtures/backend_registry_runner.php', $this->projectRoot, $phase],
            $this->projectRoot,
        );
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    /** @return array<string, mixed> */
    private function resolveReport(): array
    {
        $process = $this->runPhase('resolve');
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $decoded = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function pdo(): \PDO
    {
        return new \PDO('sqlite:' . $this->projectRoot . '/storage/waaseyaa.sqlite', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map(
            static fn(array $r): string => (string) $r['name'],
            $this->pdo()->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /** @return list<string> */
    private function indexes(string $table): array
    {
        return array_map(
            static fn(array $r): string => (string) $r['name'],
            $this->pdo()->query("PRAGMA index_list({$table})")->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /** @return list<string> */
    private function indexColumns(string $index): array
    {
        return array_map(
            static fn(array $r): string => (string) $r['name'],
            $this->pdo()->query("PRAGMA index_info({$index})")->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    private function writeAutoloadWrapper(): void
    {
        if (!is_dir($this->projectRoot . '/vendor')) {
            mkdir($this->projectRoot . '/vendor', 0o755, true);
        }
        $repoRoot = $this->repoRoot;
        file_put_contents($this->projectRoot . '/vendor/autoload.php', <<<PHP
            <?php

            declare(strict_types=1);

            return require '{$repoRoot}/vendor/autoload.php';
            PHP);
    }
}
