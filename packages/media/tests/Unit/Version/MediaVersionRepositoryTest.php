<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit\Version;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Media\Version\MediaVersionRepository;

#[CoversClass(MediaVersionRepository::class)]
final class MediaVersionRepositoryTest extends TestCase
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

    #[Test]
    public function next_vid_throws_when_the_version_table_is_unusable(): void
    {
        // No media_version table at all. Historically the catch(\Throwable)
        // swallowed this and returned 1 — silently colliding with / rewriting
        // version history on every broken-schema install. It must FAIL LOUDLY;
        // the driver's best-effort boundary decides what to do with it.
        $db = DBALDatabase::createSqlite();
        $repo = new MediaVersionRepository($this->makeNullEntityRepo(), $db);

        $this->expectException(\Throwable::class);
        $repo->nextVid('some-media-uuid');
    }

    #[Test]
    public function next_vid_returns_one_for_a_media_without_versions(): void
    {
        $db = DBALDatabase::createSqlite();
        $db->getConnection()->executeStatement(self::CREATE_TABLE_SQL);
        $repo = new MediaVersionRepository($this->makeNullEntityRepo(), $db);

        $this->assertSame(1, $repo->nextVid('fresh-media'));
    }

    #[Test]
    public function next_vid_returns_max_plus_one_scoped_to_the_media_uuid(): void
    {
        $db = DBALDatabase::createSqlite();
        $db->getConnection()->executeStatement(self::CREATE_TABLE_SQL);
        $db->query(
            "INSERT INTO media_version (uuid, media_uuid, vid, sha256) VALUES ('v1', 'media-a', 1, 'x'), ('v2', 'media-a', 4, 'y'), ('v3', 'media-b', 9, 'z')",
            [],
        );
        $repo = new MediaVersionRepository($this->makeNullEntityRepo(), $db);

        $this->assertSame(5, $repo->nextVid('media-a'));
        $this->assertSame(10, $repo->nextVid('media-b'));
        $this->assertSame(1, $repo->nextVid('media-c'));
    }

    private function makeNullEntityRepo(): EntityRepositoryInterface
    {
        return new class implements EntityRepositoryInterface {
            public function create(array $values = []): EntityInterface
            {
                throw new \LogicException('not implemented in this test double');
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
                throw new \LogicException('not implemented in this test double');
            }
            public function save(EntityInterface $entity, bool $validate = true): int
            {
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
}
