<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\Content\MediaAssetStore;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Publishing\Tests\Fixtures\PublisherAccount;
use Waaseyaa\Publishing\Tests\Fixtures\TestArticleEntity;

/**
 * #2517: the store's read must be gated by the catalog row it writes, and the
 * row it writes must be one the framework's own authorized download route can
 * serve.
 */
#[CoversClass(MediaAssetStore::class)]
final class MediaAssetStoreTest extends TestCase
{
    /** 1x1 transparent PNG. */
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private DBALDatabase $database;
    private EntityRepository $mediaRepository;
    private string $filesRoot;
    private string $uploadsDir;
    private PublisherAccount $actor;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $mediaType = new EntityType(
            id: 'test_media',
            label: 'Test media',
            class: TestArticleEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $schema = new SqlSchemaHandler($mediaType, $this->database);
        $schema->ensureTable();
        $schema->ensureRevisionTable();
        $resolver = new SingleConnectionResolver($this->database);
        $this->mediaRepository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $mediaType,
            new SqlStorageDriver($resolver),
            new EventDispatcher(),
            new RevisionableStorageDriver($resolver, $mediaType),
            $this->database,
        );

        $this->filesRoot = sys_get_temp_dir() . '/waaseyaa_files_' . uniqid();
        $this->uploadsDir = $this->filesRoot . '/uploads/agent';
        $this->actor = new PublisherAccount(permissions: ['publish test articles']);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->filesRoot);
    }

    #[Test]
    public function upload_writes_a_scheme_qualified_source_uri_the_download_route_can_resolve(): void
    {
        $store = $this->store($this->allowingHandler());

        $store->upload('pixel.png', $this->pngBytes(), $this->actor);

        self::assertCount(1, $this->mediaRepository->findBy(['bundle' => 'image']));
        $sourceUri = $this->storedSourceUri();

        self::assertStringStartsWith('public://', $sourceUri);
        // The relative half must resolve, under the media files root, to the
        // bytes that were actually stored — that is exactly what
        // MediaDownloadRouter::resolvePublicPath() does with this value.
        $relative = substr($sourceUri, strlen('public://'));
        self::assertFileExists($this->filesRoot . '/' . $relative);
        self::assertSame('uploads/agent', dirname($relative));
    }

    /** The smallest failing test named in #2517. */
    #[Test]
    public function get_returns_null_when_the_access_handler_denies_view(): void
    {
        $handler = $this->createStub(EntityAccessHandler::class);
        $handler->method('checkCreateAccess')->willReturn(AccessResult::allowed());
        $handler->method('check')->willReturn(AccessResult::forbidden('denied'));
        $store = $this->store($handler);

        // Upload through a store whose create access is allowed, then read back
        // through one whose view access is denied: same bytes, same row.
        $uploaded = $this->store($this->allowingHandler())
            ->upload('pixel.png', $this->pngBytes(), $this->actor);

        self::assertNull($store->get($uploaded['asset_id'], $this->actor));
    }

    #[Test]
    public function get_returns_the_asset_when_view_access_is_allowed(): void
    {
        $store = $this->store($this->allowingHandler());
        $uploaded = $store->upload('pixel.png', $this->pngBytes(), $this->actor);

        $asset = $store->get($uploaded['asset_id'], $this->actor);

        self::assertNotNull($asset);
        self::assertSame($uploaded['asset_id'], $asset['asset_id']);
        self::assertSame($uploaded['media_id'], $asset['media_id']);
        self::assertSame('image/png', $asset['mime']);
        self::assertSame(1, $asset['width']);
    }

    /**
     * The store deduplicates bytes and still writes a new catalog row. Taking
     * only the first matching row would hide a later viewable row when an
     * earlier duplicate is unpublished.
     */
    #[Test]
    public function get_returns_a_later_viewable_row_when_an_earlier_duplicate_is_denied(): void
    {
        $store = $this->store($this->allowingHandler());
        $first = $store->upload('pixel.png', $this->pngBytes(), $this->actor);
        $second = $store->upload('pixel.png', $this->pngBytes(), $this->actor);
        self::assertSame($first['asset_id'], $second['asset_id']);
        self::assertNotSame($first['media_id'], $second['media_id']);

        $deniedId = $first['media_id'];
        $handler = $this->createStub(EntityAccessHandler::class);
        $handler->method('checkCreateAccess')->willReturn(AccessResult::allowed());
        $handler->method('check')->willReturnCallback(
            static function (object $entity) use ($deniedId): AccessResult {
                return (string) $entity->id() === $deniedId
                    ? AccessResult::forbidden('unpublished')
                    : AccessResult::allowed();
            },
        );

        $asset = $this->store($handler)->get($first['asset_id'], $this->actor);

        self::assertNotNull($asset);
        self::assertSame($second['media_id'], $asset['media_id']);
    }

    /**
     * Retraction: the catalog row is the authority. Deleting it withdraws the
     * asset even though the content-addressed bytes remain on disk.
     */
    #[Test]
    public function get_refuses_an_asset_whose_catalog_row_has_been_deleted(): void
    {
        $store = $this->store($this->allowingHandler());
        $uploaded = $store->upload('pixel.png', $this->pngBytes(), $this->actor);
        $rows = $this->mediaRepository->findBy(['bundle' => 'image']);
        $this->mediaRepository->delete($rows[0]);

        self::assertNull($store->get($uploaded['asset_id'], $this->actor));
    }

    /**
     * Bytes on disk with no catalog row at all are not an asset. A store that
     * answered from the filesystem alone would serve anything dropped into its
     * uploads directory.
     */
    #[Test]
    public function get_refuses_orphan_bytes_with_no_catalog_row(): void
    {
        $store = $this->store($this->allowingHandler());
        $bytes = $this->pngBytes();
        $sha = hash('sha256', $bytes);
        mkdir($this->uploadsDir, 0o755, true);
        file_put_contents($this->uploadsDir . '/' . $sha . '.png', $bytes);

        self::assertNull($store->get($sha, $this->actor));
    }

    #[Test]
    public function an_uploads_directory_outside_the_media_files_root_is_refused_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MediaAssetStore(
            $this->mediaRepository,
            sys_get_temp_dir() . '/waaseyaa_elsewhere_' . uniqid(),
            '/media/uploads',
            $this->allowingHandler(),
            $this->filesRoot,
        );
    }

    #[Test]
    public function a_traversing_uploads_directory_cannot_escape_the_media_files_root(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MediaAssetStore(
            $this->mediaRepository,
            $this->filesRoot . '/uploads/../../escaped',
            '/media/uploads',
            $this->allowingHandler(),
            $this->filesRoot,
        );
    }

    #[Test]
    public function an_uploads_directory_that_is_the_files_root_yields_a_root_relative_uri(): void
    {
        $store = new MediaAssetStore(
            $this->mediaRepository,
            $this->filesRoot,
            '/media/uploads',
            $this->allowingHandler(),
            $this->filesRoot,
        );

        $store->upload('pixel.png', $this->pngBytes(), $this->actor);

        self::assertSame(
            'public://' . hash('sha256', $this->pngBytes()) . '.png',
            $this->storedSourceUri(),
        );
    }

    /**
     * `source_uri` is a protected field read, so it is asserted from storage
     * rather than through the entity — the store itself never reads it back
     * off the entity either, it queries by it.
     */
    private function storedSourceUri(): string
    {
        $rows = iterator_to_array($this->database->query(
            "SELECT json_extract(_data, '$.source_uri') AS source_uri FROM test_media LIMIT 1",
        ));
        self::assertNotSame([], $rows);
        self::assertIsString($rows[0]['source_uri']);

        return $rows[0]['source_uri'];
    }

    private function store(EntityAccessHandler $handler): MediaAssetStore
    {
        return new MediaAssetStore(
            $this->mediaRepository,
            $this->uploadsDir,
            '/media/uploads',
            $handler,
            $this->filesRoot,
        );
    }

    private function allowingHandler(): EntityAccessHandler
    {
        $handler = $this->createStub(EntityAccessHandler::class);
        $handler->method('checkCreateAccess')->willReturn(AccessResult::allowed());
        $handler->method('check')->willReturn(AccessResult::allowed());

        return $handler;
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(self::PNG_BASE64, true);
        self::assertIsString($bytes);

        return $bytes;
    }
}
