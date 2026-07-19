<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\Controller\MediaVersionController;
use Waaseyaa\Api\Http\Router\MediaVersionApiRouter;
use Waaseyaa\Api\Media\MediaVersionReadModelInterface;
use Waaseyaa\Api\Media\MediaVersionResource;

#[CoversClass(MediaVersionApiRouter::class)]
final class MediaVersionApiRouterTest extends TestCase
{
    private MediaVersionController $controller;
    private MediaVersionApiRouter $router;

    protected function setUp(): void
    {
        $account = new AuthorizationPrincipal(1, true, ['administrator'], [], 'test');

        $resource = new MediaVersionResource(
            vid: 1,
            mediaUuid: 'test-uuid',
            blobUri: 'cas://aabbcc',
            mime: 'image/jpeg',
            sizeBytes: 2048,
            sha256: str_repeat('b', 64),
            createdAt: 1748000000,
            createdBy: 1,
        );

        $readModel = new class ($resource) implements MediaVersionReadModelInterface {
            public function __construct(private readonly MediaVersionResource $resource) {}

            public function findForMedia(string $mediaUuid, AccountInterface $account): iterable
            {
                yield $this->resource;
            }

            public function findByVid(string $mediaUuid, int $vid, AccountInterface $account): ?MediaVersionResource
            {
                return $vid === 1 ? $this->resource : null;
            }

            public function existsByVid(string $mediaUuid, int $vid): bool
            {
                return $vid === 1;
            }
        };

        $this->controller = new MediaVersionController($readModel);
        $this->router = new MediaVersionApiRouter($this->controller);

        // Bind the account to requests in the controller (controllers expect _account).
        $this->account = $account;
    }

    /** @var AccountInterface */
    private AccountInterface $account;

    private function makeRequest(string $controllerRef, string $uuid, ?int $vid = null): Request
    {
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

    #[Test]
    public function supports_true_for_media_version_controller_ref(): void
    {
        $request = $this->makeRequest('Waaseyaa\\Api\\Controller\\MediaVersionController::index', 'abc');

        self::assertTrue($this->router->supports($request));
    }

    #[Test]
    public function supports_false_for_unrelated_controller(): void
    {
        $request = Request::create('/api/something', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\NotificationController::index');

        self::assertFalse($this->router->supports($request));
    }

    #[Test]
    public function dispatches_index_action_and_returns_200(): void
    {
        $request = $this->makeRequest('Waaseyaa\\Api\\Controller\\MediaVersionController::index', 'test-uuid');

        $response = $this->router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/vnd.api+json', $response->headers->get('Content-Type') ?? '');
        $body = json_decode($response->getContent(), associative: true);
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertCount(1, $body['data']);
    }

    #[Test]
    public function dispatches_show_action_for_known_vid_returns_200(): void
    {
        $request = $this->makeRequest('Waaseyaa\\Api\\Controller\\MediaVersionController::show', 'test-uuid', 1);

        $response = $this->router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), associative: true);
        self::assertArrayHasKey('data', $body);
        self::assertSame(1, $body['data']['vid']);
    }

    #[Test]
    public function dispatches_show_action_for_unknown_vid_returns_404(): void
    {
        $request = $this->makeRequest('Waaseyaa\\Api\\Controller\\MediaVersionController::show', 'test-uuid', 99);

        $response = $this->router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getContent(), associative: true);
        self::assertArrayHasKey('errors', $body);
    }

    #[Test]
    public function returns_404_for_unknown_action(): void
    {
        $request = Request::create('/api/media/x/versions', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\MediaVersionController::delete');
        $request->attributes->set('uuid', 'x');
        $request->attributes->set('_account', $this->account);

        $response = $this->router->handle($request);

        self::assertSame(404, $response->getStatusCode());
    }
}
