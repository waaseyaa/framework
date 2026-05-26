<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Controller\MediaVersionController;
use Waaseyaa\Api\Media\MediaVersionReadModelInterface;
use Waaseyaa\Api\Media\MediaVersionResource;

#[CoversClass(MediaVersionController::class)]
final class MediaVersionControllerTest extends TestCase
{
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->account = new class implements AccountInterface {
            public function id(): int|string { return 1; }
            public function isAuthenticated(): bool { return true; }
            public function getRoles(): array { return ['authenticated']; }
            public function hasPermission(string $permission): bool { return true; }
        };
    }

    private function makeRequest(string $uuid, ?int $vid = null): Request
    {
        $uriPart = $vid !== null ? "/api/media/{$uuid}/versions/{$vid}" : "/api/media/{$uuid}/versions";
        $request = Request::create($uriPart, 'GET');
        $request->attributes->set('uuid', $uuid);
        if ($vid !== null) {
            $request->attributes->set('vid', $vid);
        }
        $request->attributes->set('_account', $this->account);

        return $request;
    }

    private function makeResource(int $vid): MediaVersionResource
    {
        return new MediaVersionResource(
            vid: $vid,
            mediaUuid: 'test-uuid',
            blobUri: "cas://abc{$vid}",
            mime: 'image/png',
            sizeBytes: 1024,
            sha256: str_repeat('a', 64),
            createdAt: 1748000000,
            createdBy: 1,
        );
    }

    #[Test]
    public function index_returns_empty_data_when_no_read_model(): void
    {
        $controller = new MediaVersionController(null);
        $result = $controller->index('test-uuid', $this->makeRequest('test-uuid'));

        self::assertSame(['data' => [], 'meta' => ['total' => 0]], $result);
    }

    #[Test]
    public function index_returns_all_versions_from_read_model(): void
    {
        $r1 = $this->makeResource(2);
        $r2 = $this->makeResource(1);

        $readModel = new class ($r1, $r2) implements MediaVersionReadModelInterface {
            public function __construct(
                private readonly MediaVersionResource $r1,
                private readonly MediaVersionResource $r2,
            ) {}

            public function findForMedia(string $mediaUuid, AccountInterface $account): iterable
            {
                yield $this->r1;
                yield $this->r2;
            }

            public function findByVid(string $mediaUuid, int $vid, AccountInterface $account): ?MediaVersionResource
            {
                return null;
            }

            public function existsByVid(string $mediaUuid, int $vid): bool
            {
                return false;
            }
        };

        $controller = new MediaVersionController($readModel);
        $result = $controller->index('test-uuid', $this->makeRequest('test-uuid'));

        self::assertArrayHasKey('data', $result);
        self::assertCount(2, $result['data']);
        self::assertSame(2, $result['data'][0]['vid']);
        self::assertSame(1, $result['data'][1]['vid']);
        self::assertSame(['total' => 2], $result['meta']);
    }

    #[Test]
    public function show_returns_404_when_no_read_model(): void
    {
        $controller = new MediaVersionController(null);
        $result = $controller->show('test-uuid', 99, $this->makeRequest('test-uuid', 99));

        self::assertSame(404, $result['status']);
        self::assertArrayHasKey('errors', $result);
    }

    #[Test]
    public function show_returns_resource_when_found(): void
    {
        $resource = $this->makeResource(2);

        $readModel = new class ($resource) implements MediaVersionReadModelInterface {
            public function __construct(private readonly MediaVersionResource $resource) {}

            public function findForMedia(string $mediaUuid, AccountInterface $account): iterable
            {
                return [];
            }

            public function findByVid(string $mediaUuid, int $vid, AccountInterface $account): ?MediaVersionResource
            {
                return $vid === 2 ? $this->resource : null;
            }

            public function existsByVid(string $mediaUuid, int $vid): bool
            {
                return $vid === 2;
            }
        };

        $controller = new MediaVersionController($readModel);
        $result = $controller->show('test-uuid', 2, $this->makeRequest('test-uuid', 2));

        self::assertArrayHasKey('data', $result);
        self::assertSame(2, $result['data']['vid']);
    }

    #[Test]
    public function show_returns_404_when_version_not_found(): void
    {
        $readModel = new class implements MediaVersionReadModelInterface {
            public function findForMedia(string $mediaUuid, AccountInterface $account): iterable { return []; }
            public function findByVid(string $mediaUuid, int $vid, AccountInterface $account): ?MediaVersionResource { return null; }
            public function existsByVid(string $mediaUuid, int $vid): bool { return false; }
        };

        $controller = new MediaVersionController($readModel);
        $result = $controller->show('test-uuid', 99, $this->makeRequest('test-uuid', 99));

        self::assertSame(404, $result['status']);
    }

    #[Test]
    public function show_returns_403_when_version_exists_but_is_forbidden(): void
    {
        $readModel = new class implements MediaVersionReadModelInterface {
            public function findForMedia(string $mediaUuid, AccountInterface $account): iterable { return []; }
            // findByVid returns null (access denied) but version exists
            public function findByVid(string $mediaUuid, int $vid, AccountInterface $account): ?MediaVersionResource { return null; }
            public function existsByVid(string $mediaUuid, int $vid): bool { return $vid === 1; }
        };

        $controller = new MediaVersionController($readModel);
        $result = $controller->show('test-uuid', 1, $this->makeRequest('test-uuid', 1));

        self::assertSame(403, $result['status']);
        self::assertArrayHasKey('errors', $result);
    }
}
