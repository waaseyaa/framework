<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Note\Ingestion\IngestionEnvelope;
use Waaseyaa\Note\Ingestion\NoteIngester;
use Waaseyaa\Note\Ingestion\NoteIngestionMetadataReader;
use Waaseyaa\Note\Note;

#[CoversClass(NoteIngester::class)]
#[CoversClass(IngestionEnvelope::class)]
final class NoteIngesterTest extends TestCase
{
    private CapturingRepository $repository;
    private NoteIngester $ingester;

    protected function setUp(): void
    {
        $this->repository = new CapturingRepository();
        $this->ingester = new NoteIngester($this->repository);
    }

    #[Test]
    public function ingestCreatesNoteWithPayloadValues(): void
    {
        $envelope = IngestionEnvelope::fromValidated([
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => [
                'title'     => 'Test Note',
                'body'      => 'Hello world.',
            ],
        ]);

        $note = $this->ingester->ingest($envelope);

        $this->assertInstanceOf(Note::class, $note);
        $this->assertSame('Test Note', $note->getTitle());
        $this->assertSame('Hello world.', $note->getBody());
    }

    #[Test]
    public function ingestPersistsNoteViaStorage(): void
    {
        $envelope = IngestionEnvelope::fromValidated([
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => ['title' => 'Saved Note'],
        ]);

        $this->ingester->ingest($envelope);

        $this->assertTrue($this->repository->saveCalled);
    }

    #[Test]
    public function ingestStoresProvenanceOnNote(): void
    {
        $envelope = IngestionEnvelope::fromValidated([
            'envelope_version' => '1',
            'source'           => 'api:import-script',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => ['title' => 'Provenance Note'],
        ]);

        $note = $this->ingester->ingest($envelope);

        $metadata = new NoteIngestionMetadataReader()->read($note);
        $this->assertSame('api:import-script', $metadata->source);
        $this->assertSame('2026-03-07T12:00:00Z', $metadata->ingestedAt);
    }

    #[Test]
    public function ingestWithoutBodyDefaultsToEmptyString(): void
    {
        $envelope = IngestionEnvelope::fromValidated([
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => ['title' => 'No Body Note'],
        ]);

        $note = $this->ingester->ingest($envelope);

        $this->assertSame('', $note->getBody());
    }
}

// ---------------------------------------------------------------------------
// Test double
// ---------------------------------------------------------------------------

final class CapturingRepository implements EntityRepositoryInterface
{
    public bool $saveCalled = false;

    public function create(array $values = []): EntityInterface
    {
        $note = new Note($values);
        $note->enforceIsNew();
        return $note;
    }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        $this->saveCalled = true;
        return 1;
    }

    public function find(int|string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return null; }

    public function loadWorkingCopy(int|string $id): ?EntityInterface { return $this->find($id); }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }

    public function delete(EntityInterface $entity): void {}

    public function deleteMany(array $entities): int { return 0; }

    public function saveMany(array $entities, bool $validate = true): array { return []; }

    public function exists(int|string $id): bool { return false; }

    public function count(array $criteria = []): int { return 0; }

    public function getQuery(): EntityQueryInterface
    {
        throw new \LogicException('Not implemented in test double.');
    }

    public function loadRevision(int|string $entityId, int $revisionId): ?EntityInterface
    {
        throw new \LogicException('Not implemented in test double.');
    }

    public function rollback(int|string $entityId, int $targetRevisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface
    {
        throw new \LogicException('Not implemented in test double.');
    }

    public function listRevisions(int|string $entityId): array { return []; }

    public function setCurrentRevision(int|string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface
    {
        throw new \LogicException('Not implemented in test double.');
    }

    public function loadPublishedRevision(int|string $entityId): ?EntityInterface { return null; }

    public function setPublishedRevision(int|string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface
    {
        throw new \LogicException('Not implemented in test double.');
    }

    public function findTranslations(EntityInterface $entity): array { return []; }

    public function saveTranslation(int|string $entityId, string $langcode, array $values, ?string $log = null, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): int
    {
        throw new \LogicException('Not implemented in test double.');
    }

    public function loadTranslation(int|string $entityId, string $langcode): ?EntityInterface { return null; }

    public function listTranslationRevisions(int|string $entityId, string $langcode): array { return []; }
}
