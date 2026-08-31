<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use Composer\Autoload\ClassLoader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Provider\MigrateServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\CLI\Tests\Fixtures\EntityEvolutionV2Migration;
use Waaseyaa\CLI\Tests\Fixtures\EntityEvolutionV2MigrationAutoloader;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\EntitySchemaTableMaterializer;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Migration\Executor\IncompatibleSchemaStateException;
use Waaseyaa\Foundation\Migration\Executor\OpPreconditionResolver;
use Waaseyaa\Foundation\Migration\Executor\SqliteTableDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * The column-exactness contract at the public boundary it actually ships behind.
 *
 * Everything here is the real composition `waaseyaa migrate` builds:
 * `MigrateServiceProvider` wires the canonical `EntitySchemaTableMaterializer`,
 * a real `Migrator`, the SQLite compiler, `OpPreconditionResolver` and the
 * ledger against a real database file, and the commands run through `CliTester`.
 * Nothing is stubbed — a stubbed materializer emitting the v2 compiler's own SQL
 * vocabulary would hide exactly the comparison this exercises. Where an
 * "existing site" is needed, its table is produced by that same materializer
 * with the future field simply not yet registered, rather than by hand-written
 * DDL asserted to match it.
 *
 * The point of running it here rather than only at the resolver is that the
 * resolver's answer has consequences the resolver cannot show: whether a fresh
 * `sql-column` install still converges, whether `checksum` and `diff_hash` still
 * hash authored intent alone, whether a refusal leaves the ledger clean, and
 * whether `--dry-run` stays read-only.
 *
 * @see docs/change-records/FW-2701.md
 */
#[CoversClass(MigrateServiceProvider::class)]
#[CoversClass(OpPreconditionResolver::class)]
#[CoversClass(SqliteTableDefinition::class)]
final class V2ColumnExactnessProviderBoundaryTest extends TestCase
{
    private const MIGRATION_ID = 'acme/application:v2:add-account-user-id';

    private string $root;
    private ?ClassLoader $migrationClassLoader = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_2712_' . bin2hex(random_bytes(8));
        mkdir($this->root . '/vendor/composer', 0o755, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', '{"packages":[]}');
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'acme/application',
            'extra' => ['waaseyaa' => ['migrations' => ['Waaseyaa\\CLI\\Tests\\Fixtures']]],
        ], JSON_THROW_ON_ERROR));

        // Registered last: the filesystem work above can throw, and an
        // autoloader registered before it would never be unregistered.
        $this->migrationClassLoader = EntityEvolutionV2MigrationAutoloader::register();
    }

    protected function tearDown(): void
    {
        EntityEvolutionV2Migration::$specType = 'text';
        $this->migrationClassLoader?->unregister();
        try {
            new Filesystem()->remove($this->root);
        } catch (IOException) {
            // The provider retains its SQLite connection for the process lifetime.
        }
    }

    /**
     * The materializer-produced column must stay `already_satisfied` on every
     * supported logical type. It emits Doctrine's spelling — `CLOB`, `BOOLEAN`,
     * `DOUBLE PRECISION` — with no `COLLATE`, no conflict clause, no `CHECK`, no
     * `UNIQUE` constraint and no foreign key, so a comparison that read any of
     * those wrongly would abort every fresh `sql-column` install.
     */
    #[Test]
    #[DataProvider('supportedLogicalTypes')]
    public function a_fresh_sql_column_install_is_still_satisfied_through_the_real_materializer(
        string $fieldType,
        string $specType,
    ): void {
        $database = $this->root . '/application.sqlite';

        self::assertSame(0, $this->migrate($database, $specType, $fieldType, 'sql-column'));

        $read = self::connect($database);
        self::assertContains('user_id', self::columnNames($read, 'account'));
        self::assertSame('already_satisfied', $this->applyMode($read));
        $read->close();
    }

    /** @return array<string, array{string, string}> */
    public static function supportedLogicalTypes(): array
    {
        return [
            'string' => ['string', 'text'],
            'integer' => ['integer', 'int'],
            'boolean' => ['boolean', 'boolean'],
            'float' => ['float', 'float'],
        ];
    }

    /**
     * Both lifecycles, on **both** backends, must record the same authored
     * evidence. `checksum` and `diff_hash` hash the authored plan, not what the
     * runtime decided to issue, so this repair cannot have moved them — even
     * though the two backends reach different `apply_mode` values on a fresh
     * install, which is precisely the case where a wrong comparison would show.
     *
     * @param non-empty-string|null $backend
     */
    #[Test]
    #[DataProvider('backends')]
    public function both_lifecycles_record_the_same_authored_checksums_on_each_backend(
        ?string $backend,
        string $freshApplyMode,
    ): void {
        $fresh = $this->root . '/fresh.sqlite';
        self::assertSame(0, $this->migrate($fresh, backend: $backend));
        $freshRead = self::connect($fresh);
        self::assertContains('user_id', self::columnNames($freshRead, 'account'));
        self::assertSame($freshApplyMode, $this->applyMode($freshRead));
        $freshEvidence = $this->authoredEvidence($freshRead);
        $freshRead->close();

        // The "existing site": the same canonical materializer, run before the
        // field this migration adds was registered at all.
        $existing = $this->root . '/existing.sqlite';
        $this->materializeAccountWithoutTheField($existing, $backend);
        $seed = self::connect($existing);
        self::assertNotContains('user_id', self::columnNames($seed, 'account'));
        $seed->close();

        self::assertSame(0, $this->migrate($existing, backend: $backend));
        $existingRead = self::connect($existing);
        self::assertContains('user_id', self::columnNames($existingRead, 'account'));
        self::assertSame(
            'applied',
            $this->applyMode($existingRead),
            'an existing table is never touched by the materializer, so the node must apply',
        );
        self::assertSame(
            $freshEvidence,
            $this->authoredEvidence($existingRead),
            'checksum and diff_hash hash authored intent, so they cannot vary by lifecycle',
        );
        self::assertNotNull($freshEvidence['checksum']);
        self::assertNotNull($freshEvidence['diff_hash']);
        $existingRead->close();
    }

    /** @return array<string, array{?string, string}> */
    public static function backends(): array
    {
        return [
            // The default backend emits keys plus `_data` and no field column,
            // so the authored node is genuinely outstanding in both lifecycles.
            'sql-blob (framework default)' => [null, 'applied'],
            // sql-column emits the field column itself, so a fresh install has
            // nothing left to apply.
            'sql-column' => ['sql-column', 'already_satisfied'],
        ];
    }

    /**
     * The repair, at the boundary. A live column that carries something the
     * authored `ColumnSpec` cannot express must abort the run rather than be
     * reported satisfied — and must leave nothing behind.
     */
    #[Test]
    #[DataProvider('unauthorableLiveColumns')]
    public function an_unauthorable_live_column_aborts_the_command_and_writes_no_ledger_row(
        string $columnDefinitions,
        string $expectedReason,
    ): void {
        $database = $this->root . '/application.sqlite';
        $seed = self::connect($database);
        $seed->executeStatement(sprintf(
            'CREATE TABLE account (eid INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, %s)',
            $columnDefinitions,
        ));
        // Every fixture declares `langcode`, so the same seeding works for the
        // generated one — a generated column cannot be inserted into.
        $seed->executeStatement("INSERT INTO account (uuid, langcode) VALUES ('seed', 'en')");
        $seed->close();

        try {
            $this->migrate($database);
            self::fail('an unauthorable live column must abort the migration');
        } catch (IncompatibleSchemaStateException $e) {
            self::assertStringContainsString('S1-DB110', $e->getMessage());
            self::assertStringContainsString($expectedReason, $e->getMessage());
        }

        $read = self::connect($database);
        self::assertSame([], self::ledgerIds($read), 'a refused node leaves no ledger row');
        self::assertSame(
            ['uuid' => 'seed', 'langcode' => 'en'],
            $read->fetchAssociative('SELECT uuid, langcode FROM account'),
            'pre-existing data is untouched',
        );
        $read->close();
    }

    /** @return array<string, array{string, string}> */
    public static function unauthorableLiveColumns(): array
    {
        return [
            'a CHECK that can read the column' => [
                "user_id TEXT CHECK (user_id != ''), langcode TEXT",
                'a CHECK constraint can read the column',
            ],
            'a CHECK attached to another column' => [
                "user_id TEXT, langcode TEXT CHECK (user_id != '')",
                'a CHECK constraint can read the column',
            ],
            'a non-BINARY collation' => [
                'user_id TEXT COLLATE NOCASE, langcode TEXT',
                'the column declares COLLATE NOCASE',
            ],
            'a UNIQUE constraint' => [
                'user_id TEXT UNIQUE, langcode TEXT',
                'found a member of a UNIQUE constraint',
            ],
            'a NOT NULL conflict policy' => [
                "user_id TEXT NOT NULL ON CONFLICT IGNORE DEFAULT '', langcode TEXT",
                "not the compiler's ABORT",
            ],
            'a generated column' => [
                'langcode TEXT, user_id TEXT AS (langcode)',
                'found a VIRTUAL generated column',
            ],
        ];
    }

    /**
     * A live column that merely sits beside unrelated structure is still the
     * column the operation declares. This is the control the refusals above
     * would otherwise be free to break: a blanket rule would abort here too.
     */
    #[Test]
    public function an_ordinary_column_beside_unrelated_structure_still_converges(): void
    {
        $database = $this->root . '/application.sqlite';
        $seed = self::connect($database);
        $seed->executeStatement(
            'CREATE TABLE account (eid INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT UNIQUE, '
            . "langcode TEXT CHECK (langcode != ''), user_id TEXT COLLATE BINARY NULL)",
        );
        // The shape EntitySchemaSync itself emits for an entity uuid column.
        $seed->executeStatement('CREATE UNIQUE INDEX account_user_id ON account (user_id)');
        $seed->close();

        self::assertSame(0, $this->migrate($database));

        $read = self::connect($database);
        self::assertSame('already_satisfied', $this->applyMode($read));
        $read->close();
    }

    /** `--dry-run` reports without applying: nothing in sqlite_master may move. */
    #[Test]
    public function a_dry_run_against_a_satisfied_schema_writes_nothing(): void
    {
        $database = $this->root . '/application.sqlite';
        $this->materializeAccountWithoutTheField($database, null);
        $seed = self::connect($database);
        $seed->executeStatement('ALTER TABLE account ADD COLUMN user_id TEXT');
        $before = self::schemaSnapshot($seed);
        $seed->close();

        self::assertSame(0, $this->migrate($database, dryRun: true));

        $read = self::connect($database);
        self::assertSame(
            $before,
            self::schemaSnapshot($read),
            'a dry run issues no DDL at all, ledger tables included',
        );
        self::assertSame([], self::ledgerIds($read), 'a dry run records nothing');
        $read->close();
    }

    private function migrate(
        string $databasePath,
        string $specType = 'text',
        string $fieldType = 'string',
        ?string $backend = null,
        bool $dryRun = false,
    ): int {
        EntityEvolutionV2Migration::$specType = $specType;

        $provider = new MigrateServiceProvider();
        $provider->setKernelContext($this->root, [
            'environment' => 'testing',
            'database' => $databasePath,
        ], []);
        $provider->setKernelServices(self::servicesWith(
            self::accountEntityType($backend, $fieldType, withField: true),
        ));
        $provider->register();

        $commands = [];
        foreach ($provider->consoleCommands() as $command) {
            $commands[$command->name] = $command;
        }

        $tester = CliTester::for($commands['migrate'], self::containerFor($provider));
        $tester->execute($dryRun ? ['--dry-run'] : []);

        return $tester->getExitCode();
    }

    /**
     * An "existing site", built by the canonical materializer at the point
     * before this migration's field existed.
     */
    private function materializeAccountWithoutTheField(string $databasePath, ?string $backend): void
    {
        $database = DBALDatabase::createSqlite($databasePath, 'testing');
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(self::accountEntityType($backend, 'string', withField: false));

        $created = new EntitySchemaTableMaterializer(
            $database,
            static fn(): iterable => $manager->getDefinitions(),
        )->materialize(['account']);

        self::assertSame(['account'], $created, 'the materializer must own the entity base table');
        $database->getConnection()->close();
    }

    private static function accountEntityType(
        ?string $backend,
        string $fieldType,
        bool $withField,
    ): EntityType {
        return new EntityType(
            id: 'account',
            label: 'Account',
            class: \stdClass::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid'],
            primaryStorageBackend: $backend,
            _fieldDefinitions: $backend === null || !$withField ? [] : [
                'user_id' => new FieldDefinition(
                    name: 'user_id',
                    type: $fieldType,
                    targetEntityTypeId: 'account',
                ),
            ],
        );
    }

    private static function servicesWith(EntityType $type): KernelServicesInterface
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($type);

        return new class ($manager) implements KernelServicesInterface {
            public function __construct(private EntityTypeManager $manager) {}

            public function get(string $id): ?object
            {
                return $id === EntityTypeManager::class ? $this->manager : null;
            }
        };
    }

    private static function containerFor(MigrateServiceProvider $provider): ContainerInterface
    {
        return new class ($provider) implements ContainerInterface {
            public function __construct(private MigrateServiceProvider $provider) {}

            public function get(string $id): mixed
            {
                return $this->provider->resolve($id);
            }

            public function has(string $id): bool
            {
                return isset($this->provider->getBindings()[$id]);
            }
        };
    }

    private function applyMode(Connection $connection): ?string
    {
        $mode = $connection->fetchOne(
            'SELECT apply_mode FROM waaseyaa_migrations WHERE migration = ?',
            [self::MIGRATION_ID],
        );

        return is_string($mode) ? $mode : null;
    }

    /** @return array{checksum: ?string, diff_hash: ?string} */
    private function authoredEvidence(Connection $connection): array
    {
        $row = $connection->fetchAssociative(
            'SELECT checksum, diff_hash FROM waaseyaa_migrations WHERE migration = ?',
            [self::MIGRATION_ID],
        );
        self::assertIsArray($row, 'the node must have recorded a ledger row');

        return [
            'checksum' => is_string($row['checksum']) ? $row['checksum'] : null,
            'diff_hash' => is_string($row['diff_hash']) ? $row['diff_hash'] : null,
        ];
    }

    private static function connect(string $path): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
    }

    /** @return list<string> */
    private static function columnNames(Connection $connection, string $table): array
    {
        return array_values(array_column(
            $connection->executeQuery(sprintf('PRAGMA table_xinfo(%s)', $table))->fetchAllAssociative(),
            'name',
        ));
    }

    /** @return list<string> */
    private static function ledgerIds(Connection $connection): array
    {
        if ((int) $connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'waaseyaa_migrations'",
        ) === 0) {
            return [];
        }

        return array_values(array_column(
            $connection->fetchAllAssociative('SELECT migration FROM waaseyaa_migrations ORDER BY migration'),
            'migration',
        ));
    }

    /** Every row, ledger and sequence tables included. @return list<array<string, mixed>> */
    private static function schemaSnapshot(Connection $connection): array
    {
        return $connection->fetchAllAssociative(
            'SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name',
        );
    }
}
