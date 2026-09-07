<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\ClientRegistry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Oidc\ClientRegistry\OidcClientSeeder;
use Waaseyaa\Oidc\ClientRegistry\OidcClientSystemReader;
use Waaseyaa\Oidc\Entity\OidcClient;

/**
 * #2766 -- two boot processes can both observe findByClientId() === null for
 * the same config-defined client_id and both attempt to create it. The
 * database-enforced unique key (2026_09_06_000009 migration) is the
 * race-closing authority: exactly one insert wins, and the loser must
 * recover by updating the winner's row rather than crashing the boot.
 *
 * Mirrors the RevisionIdAllocationRaceTest "stealing database" idiom
 * (packages/entity-storage/tests/Unit/Driver/RevisionIdAllocationRaceTest.php):
 * a real UniqueConstraintViolationException from a real physical constraint,
 * not a hand-built stand-in.
 */
#[CoversClass(OidcClientSeeder::class)]
final class OidcClientSeederConcurrencyTest extends TestCase
{
    #[Test]
    public function seedRecoversFromAConcurrentWinnerInsteadOfCrashing(): void
    {
        // File-backed (not :memory:) on purpose: the "winner" write below must
        // land through a genuinely separate DBAL connection that commits and
        // releases independently of our own connection's open transaction --
        // an in-memory SQLite handle cannot be shared across two connections.
        // Without this, the winner's row would live inside the SAME physical
        // transaction our insert fails in, and the seeder's own rollback (see
        // UnitOfWork::transaction()) would silently erase the winner row too,
        // masking the very race this test exists to prove.
        $path = sys_get_temp_dir() . '/waaseyaa_oidc_seeder_race_' . uniqid('', true) . '.sqlite';
        $database = DBALDatabase::createSqlite($path);
        try {
            $this->installRealSchemaWithUniqueKey($database);

            $entityType = new EntityType(
                id: 'oidc_client',
                label: 'OIDC Client',
                class: OidcClient::class,
                keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
            );

            // The "winner": a concurrent process/request commits its own row
            // for this client_id -- with its own distinct uuid, over its own
            // connection -- in the window between our seeder's
            // findByClientId() (which must still see no row) and our own
            // insert reaching the database. The stealing decorator injects
            // that independently-committed write at exactly that point.
            $winnerUuid = 'winner-uuid-0000-0000-000000000000';
            $winnerConnection = DBALDatabase::createSqlite($path);
            $winnerRepository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $entityType,
                new SqlStorageDriver(new SingleConnectionResolver($winnerConnection)),
                new EventDispatcher(),
                database: $winnerConnection,
            );
            $stealing = $this->stealingDatabaseOnceFor(
                $database,
                'oidc_client',
                function () use ($winnerRepository, $winnerUuid): void {
                    $winner = $winnerRepository->create([
                        'client_id' => 'race-client',
                        'uuid' => $winnerUuid,
                        'name' => 'Concurrent winner (pre-recovery placeholder)',
                        'redirect_uris' => ['https://winner.test/cb'],
                    ]);
                    $winner->enforceIsNew();
                    $winnerRepository->save($winner);
                },
            );

            $repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $entityType,
                new SqlStorageDriver(new SingleConnectionResolver($stealing)),
                new EventDispatcher(),
                // The seeder's own pre-insert lookup (OidcClientLookup::findByClientId())
                // runs through getQuery(), which queries via this `database:` param
                // directly -- NOT through the SqlStorageDriver above. Both must point at
                // $stealing so the winner injection fires at the right moment regardless
                // of which path the seeder happens to read through.
                database: $stealing,
            );

            $seeder = new OidcClientSeeder($repository);

            // Must not throw: the seeder catches the real UniqueConstraintViolationException
            // raised by the physical unique key and recovers by updating the winner.
            $seeder->seed([
                'race-client' => [
                    'name' => 'Config-defined name',
                    'redirect_uris' => ['https://race.test/cb'],
                ],
            ]);

            $ids = $repository->getQuery()->accessCheck(false)->condition('client_id', 'race-client')->execute();
            self::assertCount(1, $ids, 'the race must never leave two rows for the same client_id');

            $reloaded = $repository->find((string) $ids[0]);
            self::assertSame($winnerUuid, $reloaded->uuid(), 'recovery updates the winner row in place, never a second insert');
            $registration = new OidcClientSystemReader()->registration($reloaded);
            self::assertSame('Config-defined name', $registration->name);
            self::assertSame(['https://race.test/cb'], $registration->redirectUris);
        } finally {
            @unlink($path);
        }
    }

    private function installRealSchemaWithUniqueKey(DBALDatabase $database): void
    {
        $schema = new SchemaBuilder($database->getConnection());
        foreach ([
            '2026_04_26_000001_oidc_client_schema.php',
            '2026_09_06_000009_oidc_client_id_unique_key.php',
        ] as $file) {
            $migration = require dirname(__DIR__, 3) . '/migrations/' . $file;
            self::assertInstanceOf(Migration::class, $migration);
            $migration->up($schema);
        }

        // The raw migrations above only guarantee the base + client_id
        // columns and the unique index. ensureTable() keeps the rest of the
        // base-entity column set (uuid/bundle/langcode/_data) consistent with
        // what EntityRepository expects, exactly as OidcClientSeederTest does.
        new SqlSchemaHandler(
            new EntityType(
                id: 'oidc_client',
                label: 'OIDC Client',
                class: OidcClient::class,
                keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
            ),
            $database,
        )->ensureTable();
    }

    /**
     * A {@see DatabaseInterface} that, exactly once and only for the given
     * table, turns the seeder's own pre-insert lookup into the trigger for a
     * lost race: immediately after the real SELECT executes (and finds no
     * row -- the seeder's own `findByClientId() === null` check), it runs
     * $onFirstSelect, which independently creates and commits a "winner" row
     * over its OWN connection (see the caller). Because that commit happens
     * on a separate connection, before our own transaction ever opens
     * `BEGIN`, there is no lock contention, and our transaction's later
     * rollback cannot erase it. Our own subsequent INSERT of the same
     * client_id then hits the physical unique key and raises a REAL
     * UniqueConstraintViolationException from Doctrine DBAL/SQLite.
     */
    private function stealingDatabaseOnceFor(
        DatabaseInterface $inner,
        string $table,
        \Closure $onFirstSelect,
    ): DatabaseInterface {
        return new class ($inner, $table, $onFirstSelect) implements DatabaseInterface {
            private bool $armed = true;

            public function __construct(
                private readonly DatabaseInterface $inner,
                private readonly string $table,
                private readonly \Closure $onFirstSelect,
            ) {}

            public function insert(string $table): InsertInterface
            {
                return $this->inner->insert($table);
            }

            public function select(string $table, string $alias = ''): SelectInterface
            {
                $real = $this->inner->select($table, $alias);
                if (!$this->armed || $table !== $this->table) {
                    return $real;
                }
                $this->armed = false;

                return new class ($real, $this->onFirstSelect) implements SelectInterface {
                    public function __construct(
                        private readonly SelectInterface $real,
                        private readonly \Closure $onFirstSelect,
                    ) {}

                    public function fields(string $tableAlias, array $fields = []): static
                    {
                        (void) $this->real->fields($tableAlias, $fields);

                        return $this;
                    }

                    public function addField(string $tableAlias, string $field, string $alias = ''): static
                    {
                        (void) $this->real->addField($tableAlias, $field, $alias);

                        return $this;
                    }

                    public function condition(string $field, mixed $value, string $operator = '='): static
                    {
                        (void) $this->real->condition($field, $value, $operator);

                        return $this;
                    }

                    public function isNull(string $field): static
                    {
                        (void) $this->real->isNull($field);

                        return $this;
                    }

                    public function isNotNull(string $field): static
                    {
                        (void) $this->real->isNotNull($field);

                        return $this;
                    }

                    public function orderBy(string $field, string $direction = 'ASC'): static
                    {
                        (void) $this->real->orderBy($field, $direction);

                        return $this;
                    }

                    public function whereRaw(string $expression, array $parameters = []): static
                    {
                        (void) $this->real->whereRaw($expression, $parameters);

                        return $this;
                    }

                    public function orderByRaw(string $expression, string $direction): static
                    {
                        (void) $this->real->orderByRaw($expression, $direction);

                        return $this;
                    }

                    public function range(int $offset, int $limit): static
                    {
                        (void) $this->real->range($offset, $limit);

                        return $this;
                    }

                    public function join(string $table, string $alias, string $condition): static
                    {
                        (void) $this->real->join($table, $alias, $condition);

                        return $this;
                    }

                    public function leftJoin(string $table, string $alias, string $condition): static
                    {
                        (void) $this->real->leftJoin($table, $alias, $condition);

                        return $this;
                    }

                    public function countQuery(): static
                    {
                        (void) $this->real->countQuery();

                        return $this;
                    }

                    public function execute(): \Traversable
                    {
                        // Materialize the seeder's "does this client_id
                        // already exist?" result (expected: empty) BEFORE the
                        // concurrent winner commits, exactly mirroring what a
                        // real interleaving would observe.
                        $rows = iterator_to_array($this->real->execute());

                        ($this->onFirstSelect)();

                        return new \ArrayIterator($rows);
                    }
                };
            }

            public function update(string $table): UpdateInterface
            {
                return $this->inner->update($table);
            }

            public function delete(string $table): DeleteInterface
            {
                return $this->inner->delete($table);
            }

            public function schema(): SchemaInterface
            {
                return $this->inner->schema();
            }

            public function transaction(string $name = ''): TransactionInterface
            {
                return $this->inner->transaction($name);
            }

            public function query(string $sql, array $args = []): \Traversable
            {
                return $this->inner->query($sql, $args);
            }

            public function quoteIdentifier(string $identifier): string
            {
                return $this->inner->quoteIdentifier($identifier);
            }
        };
    }
}
