<?php

declare(strict_types=1);

/**
 * Audit probe for AIV-PERSIST-001 (docs/audits/packages/ai-vector.md).
 *
 * Standalone script, not part of any test suite. Each case uses a throwaway
 * SQLite file from the packages/testing TemporarySqliteDatabase utility; no
 * real application database is touched. Schema transitions go through the
 * framework's own coordinated path (EntitySchemaSyncRunner), the same path
 * #3110 describes. That runner performs coordinated entity-table DDL; the
 * only uncoordinated DDL is ai-vector's own.
 *
 * Run from the repository root:
 *
 *   php tests/Fixtures/Audits/AiVector/embeddings-schema-drift-probe.php
 *
 * Prints one JSON line per case and exits 1 if any case doesn't behave as
 * the audit records.
 */

use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

/**
 * Minimal entity for the listeners; they only read id, type, label and values.
 */
final readonly class AiVectorProbeEntity implements EntityInterface
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
        throw new LogicException('Readonly');
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

function aiVectorProbeType(string $id): EntityType
{
    return new EntityType(
        id: $id,
        label: $id,
        // The sql-blob runner never instantiates the class.
        class: ContentEntityBase::class,
        keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
    );
}

/**
 * @param callable(DBALDatabase): void $activity what ai-vector does between the two transitions
 * @return array{case: string, embeddings_created: bool, fingerprint_drifted: bool, next_transition: string}
 */
function aiVectorProbeCase(string $case, callable $activity): array
{
    $temporary = new TemporarySqliteDatabase();
    try {
        $database = $temporary->database();
        assert($database instanceof DBALDatabase);
        $connection = $database->getConnection();
        $repository = new MigrationRepository($connection);

        // First coordinated transition: provisions an entity table and records the manifest.
        new EntitySchemaSyncRunner($database)->run([aiVectorProbeType('probe_first')]);
        $recorded = $repository->schemaAuthorityManifest()?->schemaFingerprint;

        $activity($database);

        $created = $connection->createSchemaManager()->tablesExist(['embeddings']);
        $drifted = $recorded !== $repository->currentLogicalSchemaFingerprint();

        // Second coordinated transition: a new entity type, as in #3110.
        try {
            new EntitySchemaSyncRunner($database)->run([aiVectorProbeType('probe_first'), aiVectorProbeType('probe_second')]);
            $next = 'succeeded';
        } catch (Throwable $exception) {
            $next = str_contains($exception->getMessage(), '[S1-DB109]') ? 'refused [S1-DB109]' : 'failed: ' . $exception->getMessage();
        }

        return ['case' => $case, 'embeddings_created' => $created, 'fingerprint_drifted' => $drifted, 'next_transition' => $next];
    } finally {
        $temporary->remove();
    }
}

$storage = static fn(DBALDatabase $database): SqliteEmbeddingStorage => new SqliteEmbeddingStorage($database->getConnection()->getNativeConnection());

$results = [
    aiVectorProbeCase('control: no ai-vector activity', static function (): void {}),
    aiVectorProbeCase('cleanup listener on entity delete, no provider', static function (DBALDatabase $database) use ($storage): void {
        new EntityEmbeddingCleanupListener($storage($database))->onPostDelete(new EntityEvent(new AiVectorProbeEntity(1, 'note')));
    }),
    aiVectorProbeCase('save of a node that is not publicly served, no provider', static function (DBALDatabase $database) use ($storage): void {
        new EntityEmbeddingListener(storage: $storage($database))->onPostSave(new EntityEvent(new AiVectorProbeEntity(7, 'node', ['status' => 0, 'workflow_state' => 'draft'])));
    }),
    aiVectorProbeCase('save of a non-node entity, no provider', static function (DBALDatabase $database) use ($storage): void {
        new EntityEmbeddingListener(storage: $storage($database))->onPostSave(new EntityEvent(new AiVectorProbeEntity(3, 'note')));
    }),
];

$expected = [
    [false, false, 'succeeded'],
    [true, true, 'refused [S1-DB109]'],
    [true, true, 'refused [S1-DB109]'],
    [false, false, 'succeeded'],
];

$ok = true;
foreach ($results as $index => $result) {
    $matches = [$result['embeddings_created'], $result['fingerprint_drifted'], $result['next_transition']] === $expected[$index];
    $ok = $ok && $matches;
    echo json_encode($result + ['as_recorded' => $matches], JSON_UNESCAPED_SLASHES), "\n";
}

exit($ok ? 0 : 1);
