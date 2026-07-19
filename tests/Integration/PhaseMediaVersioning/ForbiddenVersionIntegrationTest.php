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
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Api\Controller\MediaVersionController;
use Waaseyaa\Api\Http\Router\MediaVersionApiRouter;
use Waaseyaa\Api\Media\ApiMediaVersionAdapter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Media\Version\MediaVersion;
use Waaseyaa\Media\Version\MediaVersionRepository;

/**
 * Integration test: per-version access filtering (FR-014).
 *
 * Verifies that:
 * - Authenticated admin sees all 3 versions.
 * - Non-admin account with a gate that forbids vid=1 sees only v2 and v3.
 * - Non-admin GET /api/media/{uuid}/versions/1 → 403.
 *
 * Uses a real in-memory SQLite database. The GateInterface is implemented
 * as an anonymous class that rejects vid=1 for the non-admin account.
 *
 * Refs DIR-005 (versioned-blob-media-abstraction-01KSEFTJ).
 */
#[CoversNothing]
final class ForbiddenVersionIntegrationTest extends TestCase
{
    private DBALDatabase $db;
    private MediaVersionRepository $repo;
    private AccountInterface $adminAccount;
    private AccountInterface $nonAdminAccount;
    private string $mediaUuid = 'media-forbidden-test';

    protected function setUp(): void
    {
        $this->db = new DBALDatabase(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
        );

        $this->createSchema();

        $entityRepo = $this->createStub(EntityRepositoryInterface::class);
        $this->repo = new MediaVersionRepository($entityRepo, $this->db);

        // Seed 3 versions
        $this->seedVersion($this->mediaUuid, 1, str_repeat('a', 64));
        $this->seedVersion($this->mediaUuid, 2, str_repeat('b', 64));
        $this->seedVersion($this->mediaUuid, 3, str_repeat('c', 64));

        $this->adminAccount = new AuthorizationPrincipal(1, true, ['administrator'], [], 'test-admin');
        $this->nonAdminAccount = new AuthorizationPrincipal(2, true, ['authenticated'], [], 'test-member');
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

    private function seedVersion(string $mediaUuid, int $vid, string $sha256): void
    {
        $this->db->insert('media_version')
            ->fields(['uuid', 'media_uuid', 'vid', 'blob_uri', 'mime', 'size', 'sha256', 'created_at', 'created_by', '_data'])
            ->values([
                sprintf('%s-v%d', $mediaUuid, $vid),
                $mediaUuid,
                $vid,
                "cas://{$sha256}",
                'image/png',
                1024,
                $sha256,
                1748000000 + $vid,
                1,
                '{}',
            ])
            ->execute();
    }

    /**
     * Gate that forbids vid=1 for non-admin accounts.
     */
    private function makeRestrictiveGate(AccountInterface $nonAdminAccount): GateInterface
    {
        return new class ($nonAdminAccount) implements GateInterface {
            public function __construct(private readonly AccountInterface $restrictedAccount) {}

            public function allows(string $ability, mixed $subject, ?object $user = null): bool
            {
                if ($ability !== 'view' || !$subject instanceof MediaVersion) {
                    return true;
                }

                // Forbid vid=1 for the restricted non-admin account only.
                if ($user instanceof AccountInterface && $user->id() === $this->restrictedAccount->id()) {
                    return $subject->vid() !== 1;
                }

                return true;
            }

            public function denies(string $ability, mixed $subject, ?object $user = null): bool
            {
                return !$this->allows($ability, $subject, $user);
            }

            public function authorize(string $ability, mixed $subject, ?object $user = null): void
            {
                if ($this->denies($ability, $subject, $user)) {
                    throw new \RuntimeException('Access denied');
                }
            }
        };
    }

    private function makeRequest(string $action, string $uuid, ?int $vid, AccountInterface $account): Request
    {
        $controllerRef = "Waaseyaa\\Api\\Controller\\MediaVersionController::{$action}";
        $uri = $vid !== null ? "/api/media/{$uuid}/versions/{$vid}" : "/api/media/{$uuid}/versions";
        $request = Request::create($uri, 'GET');
        $request->attributes->set('_controller', $controllerRef);
        $request->attributes->set('uuid', $uuid);
        $request->attributes->set('_account', $account);
        if ($vid !== null) {
            $request->attributes->set('vid', $vid);
        }

        return $request;
    }

    #[Test]
    public function admin_sees_all_three_versions(): void
    {
        $gate = $this->makeRestrictiveGate($this->nonAdminAccount);
        $adapter = new ApiMediaVersionAdapter($this->repo, $gate);
        $router = new MediaVersionApiRouter(new MediaVersionController($adapter));

        $request = $this->makeRequest('index', $this->mediaUuid, null, $this->adminAccount);
        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(3, $body['data']);
        self::assertSame(3, $body['meta']['total']);
    }

    #[Test]
    public function non_admin_sees_only_v2_and_v3(): void
    {
        $gate = $this->makeRestrictiveGate($this->nonAdminAccount);
        $adapter = new ApiMediaVersionAdapter($this->repo, $gate);
        $router = new MediaVersionApiRouter(new MediaVersionController($adapter));

        $request = $this->makeRequest('index', $this->mediaUuid, null, $this->nonAdminAccount);
        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data'], 'Non-admin should see v2 and v3 only (v1 filtered)');
        self::assertSame(2, $body['meta']['total']);

        $vids = array_column($body['data'], 'vid');
        self::assertContains(3, $vids);
        self::assertContains(2, $vids);
        self::assertNotContains(1, $vids);
    }

    #[Test]
    public function non_admin_gets_403_on_show_for_forbidden_vid(): void
    {
        $gate = $this->makeRestrictiveGate($this->nonAdminAccount);
        $adapter = new ApiMediaVersionAdapter($this->repo, $gate);
        $router = new MediaVersionApiRouter(new MediaVersionController($adapter));

        $request = $this->makeRequest('show', $this->mediaUuid, 1, $this->nonAdminAccount);
        $response = $router->handle($request);

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('errors', $body);
        self::assertSame('403', $body['errors'][0]['status']);
    }

    #[Test]
    public function non_admin_can_show_allowed_versions(): void
    {
        $gate = $this->makeRestrictiveGate($this->nonAdminAccount);
        $adapter = new ApiMediaVersionAdapter($this->repo, $gate);
        $router = new MediaVersionApiRouter(new MediaVersionController($adapter));

        $request = $this->makeRequest('show', $this->mediaUuid, 2, $this->nonAdminAccount);
        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $body['data']['vid']);
    }
}
