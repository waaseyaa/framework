<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase19;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Note\Ingestion\IngestionEnvelope;
use Waaseyaa\Note\Ingestion\IngestionEnvelopeValidator;
use Waaseyaa\Note\Ingestion\NoteIngester;
use Waaseyaa\Note\Ingestion\ValidationError;
use Waaseyaa\Note\Note;

/**
 * End-to-end ingestion pipeline for core.note (#204).
 *
 * Validates that the validator + ingester compose correctly:
 * - Valid envelopes produce a persisted Note with correct field values.
 * - Invalid envelopes are rejected with structured errors before any write occurs.
 */
#[CoversNothing]
final class NoteIngestionIntegrationTest extends TestCase
{
    private InMemoryNoteStorage $storage;
    private IngestionEnvelopeValidator $validator;
    private NoteIngester $ingester;

    protected function setUp(): void
    {
        $this->storage   = new InMemoryNoteStorage();
        $this->validator = new IngestionEnvelopeValidator();
        $this->ingester  = new NoteIngester($this->storage);
    }

    // -----------------------------------------------------------------------
    // Happy paths
    // -----------------------------------------------------------------------

    #[Test]
    public function validEnvelopeIngestsNoteWithCorrectFields(): void
    {
        $raw = [
            'envelope_version' => '1',
            'source'           => 'api:import-script',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => [
                'title'     => 'Imported Note',
                'body'      => 'Content here.',
            ],
        ];

        $errors = $this->validator->validate($raw);
        $this->assertSame([], $errors, 'Valid envelope must produce no errors.');

        $envelope = IngestionEnvelope::fromValidated($raw);
        $note     = $this->ingester->ingest($envelope);

        $this->assertInstanceOf(Note::class, $note);
        $this->assertSame('Imported Note', $note->getTitle());
        $this->assertSame('Content here.', $note->getBody());
        $this->assertCount(1, $this->storage->saved);
    }

    #[Test]
    public function provenanceFieldsAreStoredOnNote(): void
    {
        $raw = [
            'envelope_version' => '1',
            'source'           => 'cli:batch-import',
            'ingested_at'      => '2026-03-07T09:30:00Z',
            'payload'          => ['title' => 'CLI Import'],
        ];

        $note = $this->ingester->ingest(IngestionEnvelope::fromValidated($raw));

        $this->assertSame('cli:batch-import', $note->get('ingestion_source'));
        $this->assertSame('2026-03-07T09:30:00Z', $note->get('ingested_at'));
    }

    #[Test]
    public function missingBodyDefaultsToEmptyString(): void
    {
        $raw = [
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => ['title' => 'No Body'],
        ];

        $note = $this->ingester->ingest(IngestionEnvelope::fromValidated($raw));

        $this->assertSame('', $note->getBody());
    }

    #[Test]
    public function multipleEnvelopesAreEachPersisted(): void
    {
        $base = [
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
        ];

        $this->ingester->ingest(IngestionEnvelope::fromValidated(
            $base + ['payload' => ['title' => 'Note One']],
        ));
        $this->ingester->ingest(IngestionEnvelope::fromValidated(
            $base + ['payload' => ['title' => 'Note Two']],
        ));

        $this->assertCount(2, $this->storage->saved);
    }

    // -----------------------------------------------------------------------
    // Rejection paths — no storage writes occur
    // -----------------------------------------------------------------------

    #[Test]
    public function invalidEnvelopeIsRejectedWithInvalidEnvelopeCode(): void
    {
        $errors = $this->validator->validate([
            'source'      => 'api:test',
            'ingested_at' => '2026-03-07T12:00:00Z',
            'payload'     => ['title' => 'T'],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertContainsOnlyInstancesOf(ValidationError::class, $errors);
        $this->assertSame('INVALID_ENVELOPE', $errors[0]->code);
        $this->assertCount(0, $this->storage->saved);
    }

    #[Test]
    public function missingProvenanceIsRejectedWithMissingProvenanceCode(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'payload'          => ['title' => 'T'],
        ]);

        $codes = array_map(static fn(ValidationError $e): string => $e->code, $errors);
        $this->assertContains('MISSING_PROVENANCE', $codes);
        $this->assertCount(0, $this->storage->saved);
    }

    #[Test]
    public function schemaViolationIsRejectedWithSchemaViolationCode(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => [],
        ]);

        $codes = array_map(static fn(ValidationError $e): string => $e->code, $errors);
        $this->assertContains('SCHEMA_VIOLATION', $codes);
        $this->assertCount(0, $this->storage->saved);
    }

    #[Test]
    public function errorResponseIncludesPathCodeAndMessage(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'source'           => '',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => ['title' => 'T'],
        ]);

        $this->assertNotEmpty($errors);
        $serialized = $errors[0]->toArray();
        $this->assertArrayHasKey('path', $serialized);
        $this->assertArrayHasKey('code', $serialized);
        $this->assertArrayHasKey('message', $serialized);
        $this->assertNotEmpty($serialized['path']);
        $this->assertNotEmpty($serialized['message']);
    }
}

// ---------------------------------------------------------------------------
// In-memory storage double for integration tests
// ---------------------------------------------------------------------------

final class InMemoryNoteStorage implements EntityStorageInterface
{
    /** @var Note[] */
    public array $saved = [];

    private int $nextId = 1;

    public function create(array $values = []): EntityInterface
    {
        $note = new Note($values);
        $note->enforceIsNew();
        return $note;
    }

    public function save(EntityInterface $entity): int
    {
        $id = $this->nextId++;
        $entity->set('id', $id);
        $this->saved[] = $entity;
        return $id;
    }

    public function load(int|string $id): ?EntityInterface
    {
        return null;
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        return null;
    }

    public function loadMultiple(array $ids = []): array
    {
        return [];
    }

    public function delete(array $entities): void {}

    public function getQuery(): EntityQueryInterface
    {
        throw new \LogicException('Not implemented in test double.');
    }

    public function getEntityTypeId(): string
    {
        return 'note';
    }
}
