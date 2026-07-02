<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\AttachmentRepository;
use Waaseyaa\Attachment\Schema\AttachmentSchema;
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
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;

/**
 * Adversarial-review hardening for AttachmentSchema::ensureTable()'s
 * self-healing branch (WP3 same-day review, two BLOCKERs):
 *
 *   1. Healing an already-populated degraded table (rows saved while the
 *      parent linkage lived only in the `_data` JSON blob) must BACKFILL
 *      the newly-added columns from the blob. Without backfill, the added
 *      columns' static defaults ('', 0) shadow the blob values at read
 *      time — `SqlStorageDriver::mergeFromRead()` lets real columns win
 *      over `_data` on key collision — silently destroying every
 *      pre-existing row's parent linkage/active flag/timestamps.
 *   2. The heal path must never crash kernel boot: the index-existence
 *      probe must not assume SQLite (`sqlite_master` does not exist on
 *      PostgreSQL/MySQL), and ANY mid-heal failure must degrade to a
 *      logged warning, mirroring ensureActivePartialUniqueIndex()'s
 *      defensive posture.
 */
#[CoversClass(AttachmentSchema::class)]
final class AttachmentSchemaSelfHealTest extends TestCase
{
    /**
     * BLOCKER-1 repro (reviewer's exact round-trip): a row written under
     * the degraded generic-only schema keeps its parent linkage, active
     * flag, and timestamps in `_data`. After self-heal adds the real
     * columns, the row must read back IDENTICALLY — and the healed table
     * must support listFor() and setActive() on that pre-existing row.
     */
    #[Test]
    public function selfHealBackfillsPreExistingRowsFromTheDataBlob(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = EntityType::fromClass(Attachment::class);
        new SqlSchemaHandler($entityType, $database)->ensureTable();

        $entityRepository = new EntityRepository(
            entityType: $entityType,
            driver: new SqlStorageDriver(new SingleConnectionResolver($database), 'id'),
            eventDispatcher: new EventDispatcher(),
        );

        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '42',
            'is_active' => 1,
            'filename' => 'degraded-era.pdf',
            'created_at' => 1_111,
            'updated_at' => 1_111,
        ]);
        $attachment->enforceIsNew();
        $entityRepository->save($attachment);
        $id = (string) $attachment->id();

        // Pre-heal sanity: the degraded row reads back fine (values come
        // from the `_data` blob because the real columns do not exist yet).
        $preHeal = $entityRepository->find($id);
        self::assertInstanceOf(Attachment::class, $preHeal);
        self::assertSame('node', (string) $preHeal->get('parent_entity_type'));
        self::assertSame('42', (string) $preHeal->get('parent_entity_id'));

        $spyLogger = new SchemaSpyLogger();
        new AttachmentSchema($database, $spyLogger)->ensureTable();

        // Post-heal: the same row must still carry its data — the newly
        // added columns must have been backfilled from the blob, because
        // real columns now win over `_data` at every read boundary.
        $postHeal = $entityRepository->find($id);
        self::assertInstanceOf(Attachment::class, $postHeal);
        self::assertSame('node', (string) $postHeal->get('parent_entity_type'), 'parent_entity_type must survive the heal');
        self::assertSame('42', (string) $postHeal->get('parent_entity_id'), 'parent_entity_id must survive the heal');
        self::assertSame(1, (int) $postHeal->get('is_active'), 'is_active must survive the heal');
        self::assertSame(1_111, (int) $postHeal->get('created_at'), 'created_at must survive the heal');

        // The healed columns must be REAL column values, not blob shadows:
        // a raw column read (no mergeFromRead) must see the backfill.
        $rows = iterator_to_array($database->select('attachment', 'a')
            ->fields('a', ['parent_entity_type', 'parent_entity_id', 'is_active'])
            ->condition('id', $id)
            ->execute());
        self::assertCount(1, $rows);
        self::assertSame('node', (string) $rows[0]['parent_entity_type']);
        self::assertSame('42', (string) $rows[0]['parent_entity_id']);
        self::assertSame(1, (int) $rows[0]['is_active']);

        // Operator visibility: the heal must say how many rows it touched.
        self::assertNotEmpty($spyLogger->infosAndNotices, 'Backfill must log the healed row count.');
        self::assertStringContainsString('1', implode(' ', $spyLogger->infosAndNotices));

        // The healed table must actually work: listFor() finds the row,
        // setActive() (raw column UPDATEs) succeeds against it.
        $repository = new AttachmentRepository(
            entityRepository: $entityRepository,
            database: $database,
        );
        $list = $repository->listFor('node', '42');
        self::assertCount(1, $list, 'listFor() must find the healed row.');

        $repository->setActive($id);
        $reloaded = $entityRepository->find($id);
        self::assertInstanceOf(Attachment::class, $reloaded);
        self::assertSame(1, (int) $reloaded->get('is_active'));
    }

    /**
     * Backfill must honor the strict is_active allow-list
     * (AttachmentActiveInvariant::isActive() semantics): blob garbage like
     * the string 'false' must backfill as 0, never as active.
     */
    #[Test]
    public function selfHealInterpretsBlobIsActiveWithTheStrictAllowList(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = EntityType::fromClass(Attachment::class);
        new SqlSchemaHandler($entityType, $database)->ensureTable();

        // Hand-craft a degraded row whose blob is_active is PHP-truthy
        // garbage — hydration-era code never wrote this, but the backfill
        // must not promote it to active.
        $row = [
            'uuid' => bin2hex(random_bytes(8)),
            'bundle' => 'attachment',
            'filename' => 'garbage.pdf',
            'langcode' => 'en',
            '_data' => json_encode([
                'parent_entity_type' => 'node',
                'parent_entity_id' => '7',
                'is_active' => 'false',
            ], \JSON_THROW_ON_ERROR),
        ];
        $database->insert('attachment')->fields(array_keys($row))->values($row)->execute();

        new AttachmentSchema($database)->ensureTable();

        $rows = iterator_to_array($database->select('attachment', 'a')
            ->fields('a', ['parent_entity_type', 'is_active'])
            ->execute());
        self::assertCount(1, $rows);
        self::assertSame('node', (string) $rows[0]['parent_entity_type'], 'Scalar blob values must backfill.');
        self::assertSame(0, (int) $rows[0]['is_active'], "Garbage 'false' must NOT backfill as active.");
    }

    /**
     * Heal idempotency: a second ensureTable() against an already-healed
     * table must not re-run the backfill (no rows re-touched, no
     * healed-count log) and must not clobber values written since.
     */
    #[Test]
    public function selfHealIsIdempotentAndDoesNotReTouchHealedRows(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = EntityType::fromClass(Attachment::class);
        new SqlSchemaHandler($entityType, $database)->ensureTable();
        new AttachmentSchema($database)->ensureTable();

        // Write a post-heal value directly to the real column.
        $row = [
            'uuid' => bin2hex(random_bytes(8)),
            'bundle' => 'attachment',
            'filename' => 'post-heal.pdf',
            'langcode' => 'en',
            'parent_entity_type' => 'node',
            'parent_entity_id' => '9',
            'is_active' => 1,
            'created_at' => 5,
            'updated_at' => 5,
            // Stale blob copy of the same keys — must NOT win on a re-run.
            '_data' => json_encode(['parent_entity_id' => 'STALE'], \JSON_THROW_ON_ERROR),
        ];
        $database->insert('attachment')->fields(array_keys($row))->values($row)->execute();

        $spyLogger = new SchemaSpyLogger();
        new AttachmentSchema($database, $spyLogger)->ensureTable();

        $rows = iterator_to_array($database->select('attachment', 'a')
            ->fields('a', ['parent_entity_id'])
            ->execute());
        self::assertSame('9', (string) $rows[0]['parent_entity_id'], 'A re-run must not backfill over real column data.');
        self::assertSame([], $spyLogger->infosAndNotices, 'An already-healed table must not log a heal.');
    }

    /**
     * BLOCKER-2 repro: on PostgreSQL/MySQL there is no `sqlite_master`
     * catalog — the index-existence probe (which now runs on EVERY boot,
     * since boot() calls ensureTable() and the table exists after first
     * boot) exploded with an uncaught exception, crashing kernel boot on
     * 2 of 3 supported platforms. The heal must degrade to a logged
     * warning instead.
     */
    #[Test]
    public function ensureTableDoesNotThrowWhenCatalogQueriesFailOnNonSqlitePlatforms(): void
    {
        $spyLogger = new SchemaSpyLogger();
        $database = new NonSqliteThrowingDatabaseStub(
            fieldExists: true, // columns already present → heal goes straight to the index probe
            addFieldThrows: false,
        );

        $schema = new AttachmentSchema($database, $spyLogger);
        $schema->ensureTable(); // must not throw

        self::assertNotEmpty(
            $spyLogger->warnings,
            'A failed heal must log a warning so operators can see the degradation.',
        );
    }

    /**
     * ANY mid-heal failure (here: DDL addField exploding) must not crash
     * boot — schema healing is best-effort, exactly like
     * ensureActivePartialUniqueIndex()'s existing posture.
     */
    #[Test]
    public function midHealFailureDegradesToAWarningInsteadOfCrashingBoot(): void
    {
        $spyLogger = new SchemaSpyLogger();
        $database = new NonSqliteThrowingDatabaseStub(
            fieldExists: false, // columns "missing" → heal attempts addField
            addFieldThrows: true,
        );

        $schema = new AttachmentSchema($database, $spyLogger);
        $schema->ensureTable(); // must not throw

        self::assertNotEmpty(
            $spyLogger->warnings,
            'A mid-heal DDL failure must log a warning, not crash boot.',
        );
    }
}

/**
 * DatabaseInterface stub mimicking a non-SQLite platform: any raw query
 * touching `sqlite_master` (or anything else — e.g. the partial-index
 * CREATE) throws the way PostgreSQL rejects an unknown relation. The
 * schema handle reports the attachment table as existing so ensureTable()
 * takes the heal branch.
 */
final class NonSqliteThrowingDatabaseStub implements DatabaseInterface
{
    public function __construct(
        private readonly bool $fieldExists,
        private readonly bool $addFieldThrows,
    ) {}

    public function select(string $table, string $alias = ''): SelectInterface
    {
        throw new \RuntimeException('SQLSTATE[42P01]: relation does not exist (stub)');
    }

    public function insert(string $table): InsertInterface
    {
        throw new \LogicException('insert() not expected in the heal path');
    }

    public function update(string $table): UpdateInterface
    {
        throw new \LogicException('update() not expected in the heal path');
    }

    public function delete(string $table): DeleteInterface
    {
        throw new \LogicException('delete() not expected in the heal path');
    }

    public function schema(): SchemaInterface
    {
        $fieldExists = $this->fieldExists;
        $addFieldThrows = $this->addFieldThrows;

        return new class ($fieldExists, $addFieldThrows) implements SchemaInterface {
            public function __construct(
                private readonly bool $fieldExists,
                private readonly bool $addFieldThrows,
            ) {}

            public function tableExists(string $table): bool
            {
                return true;
            }

            public function fieldExists(string $table, string $field): bool
            {
                return $this->fieldExists;
            }

            public function createTable(string $name, array $spec): void {}

            public function dropTable(string $table): void {}

            public function addField(string $table, string $field, array $spec): void
            {
                if ($this->addFieldThrows) {
                    throw new \RuntimeException('DDL failed (stub)');
                }
            }

            public function dropField(string $table, string $field): void {}

            public function addIndex(string $table, string $name, array $fields): void {}

            public function dropIndex(string $table, string $name): void {}

            public function addUniqueKey(string $table, string $name, array $fields): void {}

            public function addPrimaryKey(string $table, array $fields): void {}

            public function listTableNames(): array
            {
                return ['attachment'];
            }
        };
    }

    public function transaction(string $name = ''): TransactionInterface
    {
        throw new \LogicException('transaction() not expected in the heal path');
    }

    public function query(string $sql, array $args = []): \Traversable
    {
        throw new \RuntimeException(
            'SQLSTATE[42P01]: Undefined table: relation "sqlite_master" does not exist (stub)',
        );
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

/**
 * Spy logger for the heal path: records warnings and info/notice messages.
 */
final class SchemaSpyLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $infosAndNotices = [];

    public function emergency(string|\Stringable $message, array $context = []): void {}

    public function alert(string|\Stringable $message, array $context = []): void {}

    public function critical(string|\Stringable $message, array $context = []): void {}

    public function error(string|\Stringable $message, array $context = []): void {}

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = (string) $message;
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->infosAndNotices[] = (string) $message;
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->infosAndNotices[] = (string) $message;
    }

    public function debug(string|\Stringable $message, array $context = []): void {}

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        match ($level) {
            LogLevel::WARNING => $this->warnings[] = (string) $message,
            LogLevel::INFO, LogLevel::NOTICE => $this->infosAndNotices[] = (string) $message,
            default => null,
        };
    }
}
