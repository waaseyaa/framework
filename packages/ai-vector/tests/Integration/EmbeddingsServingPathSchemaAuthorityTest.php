<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\AI\Vector\Testing\FakeEmbeddingProvider;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * FW-AIV-PERSIST-01 / #3138: ai-vector's serving paths (listener save and
 * delete, semantic search with a provider configured) perform no DDL on a real
 * SQLite file, so the recorded manifest still describes the live schema and
 * the next coordinated transition succeeds.
 */
#[CoversNothing]
final class EmbeddingsServingPathSchemaAuthorityTest extends TestCase
{
    private TemporarySqliteDatabase $temporary;
    private DBALDatabase $database;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->temporary = new TemporarySqliteDatabase();
        $database = $this->temporary->database();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $this->database = $database;
        $this->connection = $database->getConnection();
        new EntitySchemaSyncRunner($database)->run([self::entityType('probe_first')]);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->temporary->remove();
    }

    #[Test]
    public function withTheMigrationAppliedTheServingPathsKeepTheManifestValid(): void
    {
        RuntimeSchemaMigrations::aiVector($this->database);
        $recorded = $this->manifestFingerprint();

        $this->exerciseServingPaths();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM embeddings'), 'the save stored one vector and the delete removed the other');
        $this->assertManifestUnchangedAndNextTransitionSucceeds($recorded);
    }

    #[Test]
    public function withoutTheMigrationTheServingPathsCreateNothing(): void
    {
        $recorded = $this->manifestFingerprint();

        $this->exerciseServingPaths();

        self::assertFalse($this->connection->createSchemaManager()->tablesExist(['embeddings']));
        $this->assertManifestUnchangedAndNextTransitionSucceeds($recorded);
    }

    private function exerciseServingPaths(): void
    {
        $storage = new DatabaseEmbeddingStorage($this->database);
        $provider = new FakeEmbeddingProvider(dimensions: 8);

        $listener = new EntityEmbeddingListener(storage: $storage, embeddingProvider: $provider);
        $listener->onPostSave(new EntityEvent(new ServingPathProbeEntity(1, 'note', 'first note')));
        $listener->onPostSave(new EntityEvent(new ServingPathProbeEntity(2, 'note', 'second note')));
        // A node that isn't publicly served: the listener deletes its vector.
        $listener->onPostSave(new EntityEvent(new ServingPathProbeEntity(7, 'node', 'draft', ['status' => 0, 'workflow_state' => 'draft'])));
        new EntityEmbeddingCleanupListener($storage)->onPostDelete(new EntityEvent(new ServingPathProbeEntity(2, 'note', 'second note')));

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('findMany')->willReturn([]);
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $id): bool => $id === 'note');
        $manager->method('getRepository')->willReturn($repository);

        $document = new SearchController(
            entityTypeManager: $manager,
            serializer: new ResourceSerializer($manager),
            embeddingStorage: $storage,
            embeddingProvider: $provider,
        )->search('first note', 'note', 5)->toArray();
        self::assertSame('semantic', $document['meta']['mode'] ?? null, 'the search ran in semantic mode');
    }

    private function assertManifestUnchangedAndNextTransitionSucceeds(?string $recorded): void
    {
        self::assertNotNull($recorded);
        $repository = new MigrationRepository($this->connection);
        self::assertSame($recorded, $repository->currentLogicalSchemaFingerprint(), 'strict verification: no schema drift');

        new EntitySchemaSyncRunner($this->database)->run([self::entityType('probe_first'), self::entityType('probe_second')]);
        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['probe_second']), 'the next coordinated transition succeeded');
    }

    private function manifestFingerprint(): ?string
    {
        return new MigrationRepository($this->connection)->schemaAuthorityManifest()?->schemaFingerprint;
    }

    private static function entityType(string $id): EntityType
    {
        return new EntityType(
            id: $id,
            label: $id,
            class: ContentEntityBase::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
    }
}

final readonly class ServingPathProbeEntity implements EntityInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private int $id, private string $type, private string $title, private array $values = []) {}

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
        return $this->title;
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
        return ['id' => $this->id, 'title' => $this->title] + $this->values;
    }

    public function language(): string
    {
        return 'en';
    }
}
