<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Note\Ingestion\IngestionEnvelope;
use Waaseyaa\Note\Ingestion\NoteIngester;
use Waaseyaa\Note\Note;

#[CoversClass(NoteIngester::class)]
#[CoversClass(IngestionEnvelope::class)]
final class NoteIngesterTest extends TestCase
{
    private CapturingStorage $storage;
    private NoteIngester $ingester;

    protected function setUp(): void
    {
        $this->storage = new CapturingStorage();
        $this->ingester = new NoteIngester($this->storage);
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

        $this->assertTrue($this->storage->saveCalled);
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

        $this->assertSame('api:import-script', $note->get('ingestion_source'));
        $this->assertSame('2026-03-07T12:00:00Z', $note->get('ingested_at'));
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

final class CapturingStorage implements EntityStorageInterface
{
    public bool $saveCalled = false;

    public function create(array $values = []): EntityInterface
    {
        $note = new Note($values);
        $note->enforceIsNew();
        return $note;
    }

    public function save(EntityInterface $entity): int
    {
        $this->saveCalled = true;
        return 1;
    }

    public function load(int|string $id): ?EntityInterface { return null; }

    public function loadByKey(string $key, mixed $value): ?EntityInterface { return null; }

    public function loadMultiple(array $ids = []): array { return []; }

    public function delete(array $entities): void {}

    public function getQuery(): EntityQueryInterface
    {
        throw new \LogicException('Not implemented in test double.');
    }

    public function getEntityTypeId(): string { return 'note'; }
}
