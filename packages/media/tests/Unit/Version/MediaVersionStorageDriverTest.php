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
