<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseMediaVersioning;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\Controller\MediaVersionController;
use Waaseyaa\Api\Http\Router\MediaVersionApiRouter;
use Waaseyaa\Api\Media\ApiMediaVersionAdapter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Media\Version\MediaVersionRepository;

/**
 * Integration test: versioned-blob media abstraction API surface.
 *
 * FR-013 — end-to-end: seed 4 MediaVersion rows (3 distinct blobs + 1 dedup),
 * verify ordering, dedup sha256 sharing, and API endpoint responses.
 *
 * Does NOT boot the full kernel — uses real SQLite via DBALDatabase::createSqlite()
 * and the actual repository/adapter/controller/router classes. This is the
 * correct pattern for API surface integration tests (see Phase29 examples).
 *
 * Refs DIR-005 (versioned-blob-media-abstraction-01KSEFTJ).
 */
#[CoversNothing]
final class MediaVersioningIntegrationTest extends TestCase
{
    private DBALDatabase $db;
    private MediaVersionRepository $repo;
    private ApiMediaVersionAdapter $adapter;
    private MediaVersionController $controller;
    private MediaVersionApiRouter $router;
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->db = new DBALDatabase(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
        );

        $this->createSchema();

        // Minimal entity repo stub — MediaVersionRepository uses it only for save/delete.
        // For listing tests, the repo reads directly via DatabaseInterface.
        $entityRepo = $this->makeEntityRepo();

        $this->repo = new MediaVersionRepository($entityRepo, $this->db);
        $this->adapter = new ApiMediaVersionAdapter($this->repo, gate: null);
        $this->controller = new MediaVersionController($this->adapter);
        $this->router = new MediaVersionApiRouter($this->controller);

        $this->account = new AuthorizationPrincipal(1, true, ['administrator'], [], 'test');
    }

    private function createSchema(): void
    {
        $conn = $this->db->getConnection();
        $conn->executeStatement(<<<'SQL'
            CREATE TABLE media_version (
                id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                uuid VARCHAR(36) NOT NULL,
                media_uuid VARCHAR(36) NOT NULL,
                vid INTEGER NOT NULL,
                blob_uri VARCHAR(512) NOT NULL,
                mime VARCHAR(255) NOT NULL DEFAULT '',
                size INTEGER NOT NULL DEFAULT 0,
                sha256 VARCHAR(64) NOT NULL,
                created_at INTEGER NOT NULL DEFAULT 0,
                created_by INTEGER NOT NULL DEFAULT 0,
                _data TEXT NOT NULL DEFAULT '{}'
            )
            SQL);
    }

    /**
     * Insert a MediaVersion row directly (bypasses entity lifecycle for test speed).
     */
    private function seedVersion(
        string $mediaUuid,
        int $vid,
        string $sha256,
        string $blobUri,
        string $mime = 'image/png',
    ): void {
        $this->db->insert('media_version')
            ->fields(['uuid', 'media_uuid', 'vid', 'blob_uri', 'mime', 'size', 'sha256', 'created_at', 'created_by', '_data'])
            ->values([
                sprintf('%s-v%d', $mediaUuid, $vid),
                $mediaUuid,
                $vid,
                $blobUri,
                $mime,
                1024,
                $sha256,
                1748000000 + $vid,
                1,
                '{}',
            ])
            ->execute();
    }

    private function makeRequest(string $action, string $uuid, ?int $vid = null): Request
    {
        $controllerRef = "Waaseyaa\\Api\\Controller\\MediaVersionController::{$action}";
        $uri = $vid !== null ? "/api/media/{$uuid}/versions/{$vid}" : "/api/media/{$uuid}/versions";
        $request = Request::create($uri, 'GET');
        $request->attributes->set('_controller', $controllerRef);
        $request->attributes->set('uuid', $uuid);
        $request->attributes->set('_account', $this->account);
        if ($vid !== null) {
            $request->attributes->set('vid', $vid);
        }

        return $request;
    }

    /**
     * @return EntityRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeEntityRepo(): EntityRepositoryInterface
    {
        return $this->createStub(EntityRepositoryInterface::class);
    }

    #[Test]
    public function four_versions_ordered_newest_first(): void
    {
        $mediaUuid = 'media-abc-123';
        $sha256A = str_repeat('a', 64);
        $sha256B = str_repeat('b', 64);
        $sha256C = str_repeat('c', 64);

        // 3 distinct payloads + 1 dedup (v4 shares sha256 with v1)
        $this->seedVersion($mediaUuid, 1, $sha256A, "cas://{$sha256A}");
        $this->seedVersion($mediaUuid, 2, $sha256B, "cas://{$sha256B}");
        $this->seedVersion($mediaUuid, 3, $sha256C, "cas://{$sha256C}");
        $this->seedVersion($mediaUuid, 4, $sha256A, "cas://{$sha256A}"); // dedup

        $request = $this->makeRequest('index', $mediaUuid);
        $response = $this->router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(4, $body['data']);
        self::assertSame(4, $body['meta']['total']);

        // Ordered newest-first (vid DESC)
        self::assertSame(4, $body['data'][0]['vid']);
        self::assertSame(3, $body['data'][1]['vid']);
        self::assertSame(2, $body['data'][2]['vid']);
        self::assertSame(1, $body['data'][3]['vid']);
    }

    #[Test]
    public function dedup_versions_share_blob_uri_and_sha256(): void
    {
        $mediaUuid = 'media-dedup-test';
        $sha256A = str_repeat('a', 64);

        $this->seedVersion($mediaUuid, 1, $sha256A, "cas://{$sha256A}");
        $this->seedVersion($mediaUuid, 2, str_repeat('b', 64), 'cas://' . str_repeat('b', 64));
        $this->seedVersion($mediaUuid, 3, $sha256A, "cas://{$sha256A}"); // dedup

        $v1 = $this->repo->findByVid($mediaUuid, 1);
        $v3 = $this->repo->findByVid($mediaUuid, 3);

        self::assertNotNull($v1);
        self::assertNotNull($v3);
        self::assertSame($v1->blobUri(), $v3->blobUri(), 'Dedup versions share blob_uri');
        self::assertSame($v1->sha256(), $v3->sha256(), 'Dedup versions share sha256');
    }

    #[Test]
    public function show_returns_correct_version(): void
    {
        $mediaUuid = 'media-show-test';
        $this->seedVersion($mediaUuid, 1, str_repeat('a', 64), 'cas://' . str_repeat('a', 64));
        $this->seedVersion($mediaUuid, 2, str_repeat('b', 64), 'cas://' . str_repeat('b', 64));

        $request = $this->makeRequest('show', $mediaUuid, 2);
        $response = $this->router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $body['data']['vid']);
    }

    #[Test]
    public function show_returns_404_for_unknown_vid(): void
    {
        $mediaUuid = 'media-show-test';
        $this->seedVersion($mediaUuid, 1, str_repeat('a', 64), 'cas://' . str_repeat('a', 64));

        $request = $this->makeRequest('show', $mediaUuid, 99);
        $response = $this->router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('errors', $body);
    }

    #[Test]
    public function index_returns_empty_for_unknown_media(): void
    {
        $request = $this->makeRequest('index', 'no-such-media');
        $response = $this->router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }
}
