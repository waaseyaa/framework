<?php

declare(strict_types=1);

/**
 * Audit probe for AIV-PERSIST-001 (docs/audits/packages/ai-vector.md).
 *
 * Reproduces, on a throwaway SQLite file, that ai-vector's lifecycle listeners
 * create the `embeddings` table on the schema-authoritative database outside
 * the coordinator, with no embedding provider configured, and that the next
 * coordinated transition then refuses with [S1-DB109]. No real application
 * database is touched.
 *
 * Not part of any test suite. Run from the repository root:
 *
 *   php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
 *     docs/audits/packages/probes/ai-vector/EmbeddingsSchemaDriftProbeTest.php
 */

namespace Waaseyaa\Audits\Probes\AiVector;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

#[CoversNothing]
final class EmbeddingsSchemaDriftProbeTest extends TestCase
{
    private string $file = '';
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/waaseyaa_aiv_probe_' . uniqid('', true) . '.sqlite';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file]);
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
        @unlink($this->file);
    }

    /**
     * Control: with no ai-vector activity, a later coordinated transition succeeds.
     */
    #[Test]
    public function control_without_ai_vector_the_next_transition_succeeds(): void
    {
        [$repository, $coordinator] = $this->authoritativeDatabase();

        $coordinator->execute(fn() => $this->connection->executeStatement('CREATE TABLE later (id INTEGER PRIMARY KEY)'));

        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['later']));
        self::assertFalse($this->connection->createSchemaManager()->tablesExist(['embeddings']));
        self::assertSame($repository->schemaAuthorityManifest()?->schemaFingerprint, $repository->currentLogicalSchemaFingerprint());
    }

    /**
     * EntityEmbeddingCleanupListener::onPostDelete (registered by HttpKernel for
     * every entity POST_DELETE) with no embedding provider configured.
     */
    #[Test]
    public function an_entity_delete_creates_embeddings_outside_the_coordinator(): void
    {
        [$repository, $coordinator] = $this->authoritativeDatabase();
        $manifest = $repository->schemaAuthorityManifest();

        $listener = new EntityEmbeddingCleanupListener(new SqliteEmbeddingStorage($this->connection->getNativeConnection()));
        $listener->onPostDelete(new EntityEvent(new ProbeEntity(1, 'note')));

        $this->assertDriftRefusesNextTransition($repository, $coordinator, $manifest?->schemaFingerprint);
    }

    /**
     * EntityEmbeddingListener::onPostSave (registered by HttpKernel for every
     * entity POST_SAVE) with NO embedding provider: a node that is not publicly
     * served is "not indexable", so the listener deletes its (absent) embedding,
     * and that delete runs the lazy CREATE TABLE.
     */
    #[Test]
    public function saving_an_unpublished_node_creates_embeddings_without_a_provider(): void
    {
        [$repository, $coordinator] = $this->authoritativeDatabase();
        $manifest = $repository->schemaAuthorityManifest();

        $listener = new EntityEmbeddingListener(storage: new SqliteEmbeddingStorage($this->connection->getNativeConnection()));
        $listener->onPostSave(new EntityEvent(new ProbeEntity(7, 'node', ['status' => 0, 'workflow_state' => 'draft'])));

        $this->assertDriftRefusesNextTransition($repository, $coordinator, $manifest?->schemaFingerprint);
    }

    /**
     * Counter-case: saving a non-node entity with no provider neither stores
     * nor deletes, so it does not create the table. Creation on save is
     * conditional, not universal.
     */
    #[Test]
    public function saving_a_non_node_entity_without_a_provider_does_not_create_the_table(): void
    {
        [$repository] = $this->authoritativeDatabase();

        $listener = new EntityEmbeddingListener(storage: new SqliteEmbeddingStorage($this->connection->getNativeConnection()));
        $listener->onPostSave(new EntityEvent(new ProbeEntity(3, 'note')));

        self::assertFalse($this->connection->createSchemaManager()->tablesExist(['embeddings']));
        self::assertSame($repository->schemaAuthorityManifest()?->schemaFingerprint, $repository->currentLogicalSchemaFingerprint());
    }

    /**
     * @return array{0: MigrationRepository, 1: SchemaMutationCoordinator}
     */
    private function authoritativeDatabase(): array
    {
        $repository = new MigrationRepository($this->connection);
        $coordinator = new SchemaMutationCoordinator($this->connection, $repository);
        $coordinator->execute(fn() => $this->connection->executeStatement('CREATE TABLE node (id INTEGER PRIMARY KEY, title TEXT)'));
        self::assertNotNull($repository->schemaAuthorityManifest());

        return [$repository, $coordinator];
    }

    private function assertDriftRefusesNextTransition(MigrationRepository $repository, SchemaMutationCoordinator $coordinator, ?string $recordedFingerprint): void
    {
        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['embeddings']), 'embeddings was created on the authoritative database');
        self::assertNotSame($recordedFingerprint, $repository->currentLogicalSchemaFingerprint(), 'the live schema no longer matches the recorded manifest');

        $refusal = null;
        try {
            $coordinator->execute(fn() => $this->connection->executeStatement('CREATE TABLE later (id INTEGER PRIMARY KEY)'));
        } catch (\RuntimeException $exception) {
            $refusal = $exception;
        }
        self::assertNotNull($refusal, 'the next coordinated transition was not refused');
        self::assertStringContainsString('[S1-DB109]', $refusal->getMessage());
        self::assertFalse($this->connection->createSchemaManager()->tablesExist(['later']));
        fwrite(STDERR, 'PROBE refusal: ' . strtok($refusal->getMessage(), "\n") . "\n");
    }
}

final readonly class ProbeEntity implements EntityInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private int $id, private string $type, private array $values = []) {}
    public function id(): int|string|null
    {
        return $this->id;
    }
    public function uuid(): string
    {
        return '';
    }
    public function label(): string
    {
        return 'Probe ' . $this->id;
    }
    public function getEntityTypeId(): string
    {
        return $this->type;
    }
    public function bundle(): string
    {
        return 'default';
    }
    public function isNew(): bool
    {
        return false;
    }
    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }
    public function set(string $name, mixed $value): static
    {
        throw new \LogicException('Readonly');
    }
    public function toArray(): array
    {
        return ['id' => $this->id] + $this->values;
    }
    public function language(): string
    {
        return 'en';
    }
}
