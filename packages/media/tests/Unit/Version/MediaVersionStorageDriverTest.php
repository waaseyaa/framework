<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit\Version;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Media\InMemoryFileRepository;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\Version\ContentAddressedFileRepositoryDecorator;
use Waaseyaa\Media\Version\MediaVersionRepository;
use Waaseyaa\Media\Version\MediaVersionStorageDriver;
use Waaseyaa\Media\Version\PendingUpload;

#[CoversClass(MediaVersionStorageDriver::class)]
final class MediaVersionStorageDriverTest extends TestCase
{
    private const string CREATE_TABLE_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS media_version (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(36) NOT NULL DEFAULT '',
            media_uuid VARCHAR(36) NOT NULL DEFAULT '',
            vid INTEGER NOT NULL DEFAULT 0,
            blob_uri VARCHAR(512) NOT NULL DEFAULT '',
            mime VARCHAR(255) NOT NULL DEFAULT '',
            size INTEGER NOT NULL DEFAULT 0,
            sha256 VARCHAR(64) NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL DEFAULT 0,
            _data TEXT NOT NULL DEFAULT '{}'
        )
        SQL;

    private function makeEntityRepo(): EntityRepositoryInterface
    {
        return new class implements EntityRepositoryInterface {
            /** @var EntityInterface[] */
            public array $saved = [];

            public function create(array $values = []): EntityInterface
            {
                throw new \LogicException('create() not implemented in this test double');
            }
            public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
            {
                return null;
            }
            public function loadWorkingCopy(string $id): ?EntityInterface
            {
                return $this->find($id);
            }
            public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
            {
                return [];
            }
            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
            {
                return [];
            }
            public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
            {
                throw new \LogicException('getQuery() not implemented in this test double');
            }
            public function save(EntityInterface $entity, bool $validate = true): int
            {
                $this->saved[] = $entity;
                return 1;
            }
            public function delete(EntityInterface $entity): void {}
            public function exists(string $id): bool
            {
                return false;
            }
            public function count(array $criteria = []): int
            {
                return 0;
            }
            public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
            {
                return null;
            }
            public function rollback(string $entityId, int $targetRevisionId): EntityInterface
            {
                throw new \RuntimeException('not implemented');
            }
            public function listRevisions(string $entityId): array
            {
                return [];
            }
            public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
            {
                throw new \RuntimeException('not implemented');
            }
            public function loadPublishedRevision(string $entityId): ?EntityInterface
            {
                return null;
            }
            public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
            {
                throw new \RuntimeException('not implemented');
            }
            public function saveMany(array $entities, bool $validate = true): array
            {
                return [];
            }
            public function deleteMany(array $entities): int
            {
                return 0;
            }
            public function findTranslations(EntityInterface $entity): array
            {
                return [];
            }
            public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
            {
                throw new \RuntimeException('not implemented');
            }
            public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
            {
                throw new \RuntimeException('not implemented');
            }
            public function listTranslationRevisions(string $entityId, string $langcode): array
            {
                throw new \RuntimeException('not implemented');
            }
        };
    }

    /**
     * An EntityRepositoryInterface double whose save() actually INSERTs the
     * MediaVersion row, so nextVid() sees prior saves and the (media_uuid, vid)
     * unique index can reject a raced duplicate exactly like production.
     *
     * @param ?\Closure $beforeInsert Invoked before each insert — lets a test
     *        simulate a concurrent writer claiming the same vid.
     */
    private function makeInsertingEntityRepo(DBALDatabase $db, ?\Closure $beforeInsert = null): EntityRepositoryInterface
    {
        $base = $this->makeEntityRepo();

        return new class ($base, $db, $beforeInsert) implements EntityRepositoryInterface {
            /** @var EntityInterface[] */
            public array $saved = [];

            public function __construct(
                private readonly EntityRepositoryInterface $base,
                private readonly DBALDatabase $db,
                private readonly ?\Closure $beforeInsert,
            ) {}

            public function save(EntityInterface $entity, bool $validate = true): int
            {
                if ($this->beforeInsert !== null) {
                    ($this->beforeInsert)($entity);
                }

                $this->db->insert('media_version')
                    ->fields(['uuid', 'media_uuid', 'vid', 'blob_uri', 'mime', 'size', 'sha256', 'created_at', 'created_by'])
                    ->values([
                        (string) $entity->get('uuid'),
                        (string) $entity->get('media_uuid'),
                        (int) $entity->get('vid'),
                        (string) $entity->get('blob_uri'),
                        (string) $entity->get('mime'),
                        (int) $entity->get('size'),
                        (string) $entity->get('sha256'),
                        (int) $entity->get('created_at'),
                        (int) $entity->get('created_by'),
                    ])
                    ->execute();

                $this->saved[] = $entity;

                return 1;
            }

            public function create(array $values = []): EntityInterface
            {
                return $this->base->create($values);
            }
            public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
            {
                return $this->base->find($id, $langcode, $fallback);
            }
            public function loadWorkingCopy(string $id): ?EntityInterface
            {
                return $this->base->loadWorkingCopy($id);
            }
            public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
            {
                return $this->base->findMany($ids, $langcode, $fallback);
            }
            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
            {
                return $this->base->findBy($criteria, $orderBy, $limit);
            }
            public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
            {
                return $this->base->getQuery();
            }
            public function delete(EntityInterface $entity): void
            {
                $this->base->delete($entity);
            }
            public function exists(string $id): bool
            {
                return $this->base->exists($id);
            }
            public function count(array $criteria = []): int
            {
                return $this->base->count($criteria);
            }
            public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
            {
                return $this->base->loadRevision($entityId, $revisionId);
            }
            public function rollback(string $entityId, int $targetRevisionId): EntityInterface
            {
                return $this->base->rollback($entityId, $targetRevisionId);
            }
            public function listRevisions(string $entityId): array
            {
                return $this->base->listRevisions($entityId);
            }
            public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
            {
                return $this->base->setCurrentRevision($entityId, $revisionId);
            }
            public function loadPublishedRevision(string $entityId): ?EntityInterface
            {
                return $this->base->loadPublishedRevision($entityId);
            }
            public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
            {
                return $this->base->setPublishedRevision($entityId, $revisionId);
            }
            public function saveMany(array $entities, bool $validate = true): array
            {
                return $this->base->saveMany($entities, $validate);
            }
            public function deleteMany(array $entities): int
            {
                return $this->base->deleteMany($entities);
            }
            public function findTranslations(EntityInterface $entity): array
            {
                return $this->base->findTranslations($entity);
            }
            public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
            {
                return $this->base->saveTranslation($entityId, $langcode, $values, $log);
            }
            public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
            {
                return $this->base->loadTranslation($entityId, $langcode);
            }
            public function listTranslationRevisions(string $entityId, string $langcode): array
            {
                return $this->base->listTranslationRevisions($entityId, $langcode);
            }
        };
    }

    private function makeAuditWriter(): AuditWriterInterface
    {
        return new class implements AuditWriterInterface {
            /** @var AuditEventDescriptor[] */
            public array $recorded = [];

            public function record(AuditEventDescriptor $descriptor): void
            {
                $this->recorded[] = $descriptor;
            }
        };
    }

    private function makeSqliteWithVersionTable(): DBALDatabase
    {
        $db = DBALDatabase::createSqlite();
        $db->getConnection()->executeStatement(self::CREATE_TABLE_SQL);

        return $db;
    }

    #[Test]
    public function non_media_entity_is_ignored(): void
    {
        $entityRepo = $this->makeEntityRepo();
        $auditWriter = $this->makeAuditWriter();
        $db = $this->makeSqliteWithVersionTable();

        $cas = new ContentAddressedFileRepositoryDecorator(new InMemoryFileRepository());
        $versionRepo = new MediaVersionRepository($entityRepo, $db);
        $driver = new MediaVersionStorageDriver($versionRepo, $cas, $auditWriter);

        // Non-media entity — must be a no-op.
        $otherEntity = new class extends \Waaseyaa\Entity\EntityBase {
            public function __construct()
            {
                parent::__construct([], 'other', ['id' => 'id', 'uuid' => 'uuid']);
            }
        };
        $driver->onMediaPostSave(new EntityEvent($otherEntity, $otherEntity));

        self::assertEmpty($auditWriter->recorded);
        self::assertEmpty($entityRepo->saved);
    }

    #[Test]
    public function media_save_without_pending_upload_is_no_op(): void
    {
        $entityRepo = $this->makeEntityRepo();
        $auditWriter = $this->makeAuditWriter();
        $driver = new MediaVersionStorageDriver(
            new MediaVersionRepository($entityRepo, $this->makeSqliteWithVersionTable()),
            new ContentAddressedFileRepositoryDecorator(new InMemoryFileRepository()),
            $auditWriter,
        );

        $media = new Media(['uuid' => 'test-media-uuid']);
        $driver->onMediaPostSave(new EntityEvent($media, $media));

        self::assertEmpty($auditWriter->recorded);
        self::assertEmpty($entityRepo->saved);
    }

    #[Test]
    public function media_save_with_pending_upload_creates_version_and_audit_event(): void
    {
        $entityRepo = $this->makeEntityRepo();
        $auditWriter = $this->makeAuditWriter();
        $driver = new MediaVersionStorageDriver(
            new MediaVersionRepository($entityRepo, $this->makeSqliteWithVersionTable()),
            new ContentAddressedFileRepositoryDecorator(new InMemoryFileRepository()),
            $auditWriter,
        );

        $mediaUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $media = new Media(['uuid' => $mediaUuid]);

        $driver->setPendingUpload($mediaUuid, new PendingUpload(
            bytes: 'hello world',
            mime: 'text/plain',
            accountUid: 42,
        ));
        $driver->onMediaPostSave(new EntityEvent($media, $media));

        self::assertCount(1, $entityRepo->saved, 'Expected one MediaVersion saved');

        $kinds = array_map(static fn(AuditEventDescriptor $d) => $d->kind, $auditWriter->recorded);
        self::assertContains(AuditEventKind::MediaVersionCreated, $kinds);
    }

    #[Test]
    public function dedup_hit_emits_media_version_dedup_hit_audit_event(): void
    {
        $entityRepo = $this->makeEntityRepo();
        $auditWriter = $this->makeAuditWriter();
        $cas = new ContentAddressedFileRepositoryDecorator(new InMemoryFileRepository());
        $driver = new MediaVersionStorageDriver(
            new MediaVersionRepository($entityRepo, $this->makeSqliteWithVersionTable()),
            $cas,
            $auditWriter,
        );

        $mediaUuid = 'dedup-test-media';
        $media = new Media(['uuid' => $mediaUuid]);
        $bytes = 'identical content';

        $driver->setPendingUpload($mediaUuid, new PendingUpload($bytes, 'text/plain', 1));
        $driver->onMediaPostSave(new EntityEvent($media, $media));

        $driver->setPendingUpload($mediaUuid, new PendingUpload($bytes, 'text/plain', 1));
        $driver->onMediaPostSave(new EntityEvent($media, $media));

        $kinds = array_map(static fn(AuditEventDescriptor $d) => $d->kind, $auditWriter->recorded);
        self::assertContains(AuditEventKind::MediaVersionDedupHit, $kinds);
    }

    #[Test]
    public function versions_get_incrementing_vids_across_successive_uploads(): void
    {
        $db = $this->makeSqliteWithVersionTable();
        $entityRepo = $this->makeInsertingEntityRepo($db);
        $auditWriter = $this->makeAuditWriter();
        $driver = new MediaVersionStorageDriver(
            new MediaVersionRepository($entityRepo, $db),
            new ContentAddressedFileRepositoryDecorator(new InMemoryFileRepository()),
            $auditWriter,
        );

        $mediaUuid = 'increment-test-media';
        $media = new Media(['uuid' => $mediaUuid]);

        $driver->setPendingUpload($mediaUuid, new PendingUpload('first bytes', 'text/plain', 1));
        $driver->onMediaPostSave(new EntityEvent($media, $media));
        $driver->setPendingUpload($mediaUuid, new PendingUpload('second bytes', 'text/plain', 1));
        $driver->onMediaPostSave(new EntityEvent($media, $media));

        $vids = array_map(static fn(EntityInterface $e): int => (int) $e->get('vid'), $entityRepo->saved);
        self::assertSame([1, 2], $vids);
    }

    #[Test]
    public function vid_allocation_retries_past_a_stolen_vid_and_never_duplicates(): void
    {
        $db = $this->makeSqliteWithVersionTable();
        // The unique constraint the production migration adds — the collision
        // detector the retry relies on (mirrors #1706 revision allocation).
        $db->getConnection()->executeStatement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_media_version_media_uuid_vid ON media_version (media_uuid, vid)',
        );

        $mediaUuid = 'race-test-media';
        // Seed vid 1 so the driver computes nextVid = 2.
        $db->query(
            "INSERT INTO media_version (uuid, media_uuid, vid, sha256) VALUES ('seed', ?, 1, 's')",
            [$mediaUuid],
        );

        $stolen = false;
        $entityRepo = $this->makeInsertingEntityRepo($db, beforeInsert: function () use ($db, $mediaUuid, &$stolen): void {
            if ($stolen) {
                return;
            }
            $stolen = true;
            // A "concurrent writer" claims vid 2 between the MAX read and our
            // insert — the unique index must reject our duplicate, and the
            // driver must retry with a freshly re-read MAX.
            $db->query(
                "INSERT INTO media_version (uuid, media_uuid, vid, sha256) VALUES ('thief', ?, 2, 't')",
                [$mediaUuid],
            );
        });
        $auditWriter = $this->makeAuditWriter();
        $driver = new MediaVersionStorageDriver(
            new MediaVersionRepository($entityRepo, $db),
            new ContentAddressedFileRepositoryDecorator(new InMemoryFileRepository()),
            $auditWriter,
        );

        $media = new Media(['uuid' => $mediaUuid]);
        $driver->setPendingUpload($mediaUuid, new PendingUpload('raced bytes', 'text/plain', 7));
        $driver->onMediaPostSave(new EntityEvent($media, $media));

        // The allocation must advance past the stolen vid, never duplicate it.
        self::assertCount(1, $entityRepo->saved, 'exactly one version row saved for the upload');
        self::assertSame(3, (int) $entityRepo->saved[0]->get('vid'), 'allocation must advance past the stolen vid');

        $rows = iterator_to_array(
            $db->query('SELECT vid FROM media_version WHERE media_uuid = ? ORDER BY vid ASC', [$mediaUuid]),
            false,
        );
        self::assertSame([1, 2, 3], array_map(static fn(array $row): int => (int) $row['vid'], $rows));

        // The audit trail records the FINAL vid, not the raced one.
        $created = array_values(array_filter(
            $auditWriter->recorded,
            static fn(AuditEventDescriptor $d): bool => $d->kind === AuditEventKind::MediaVersionCreated,
        ));
        self::assertCount(1, $created);
        self::assertStringEndsWith('/versions/3', $created[0]->subjectUri);
    }

    #[Test]
    public function pending_upload_is_consumed_single_shot(): void
    {
        $entityRepo = $this->makeEntityRepo();
        $auditWriter = $this->makeAuditWriter();
        $driver = new MediaVersionStorageDriver(
            new MediaVersionRepository($entityRepo, $this->makeSqliteWithVersionTable()),
            new ContentAddressedFileRepositoryDecorator(new InMemoryFileRepository()),
            $auditWriter,
        );

        $mediaUuid = 'single-shot-test';
        $media = new Media(['uuid' => $mediaUuid]);

        $driver->setPendingUpload($mediaUuid, new PendingUpload('data', 'text/plain', 1));
        $driver->onMediaPostSave(new EntityEvent($media, $media));
        $savedAfterFirst = count($entityRepo->saved);

        // Second save without re-registering a pending upload — must be no-op.
        $driver->onMediaPostSave(new EntityEvent($media, $media));
        self::assertCount($savedAfterFirst, $entityRepo->saved);
    }
}
