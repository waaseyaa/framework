<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\Http\Router\DiscoveryRouter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

#[CoversClass(DiscoveryRouter::class)]
final class DiscoveryRouterTest extends TestCase
{
    private function createRouter(?EntityTypeManager $etm = null): DiscoveryRouter
    {
        $etm ??= new EntityTypeManager(new EventDispatcher());
        $db = DBALDatabase::createSqlite();
        $handler = new DiscoveryApiHandler($etm, $db);

        return new DiscoveryRouter($handler, $etm);
    }

    #[Test]
    public function supports_discovery_topic_hub(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/discovery/topic-hub/node/1');
        $request->attributes->set('_controller', 'discovery.topic_hub');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_discovery_cluster(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/discovery/cluster/node/1');
        $request->attributes->set('_controller', 'discovery.cluster');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_api_discovery_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/discovery');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\ApiDiscoveryController');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function handle_passes_authenticated_account_from_request_to_discovery_controller(): void
    {
        $router = $this->createRouter($this->createManagerWithArticle());
        $request = $this->createDiscoveryRequest($this->createAccount(authenticated: true));

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('article', $payload['links']);
        self::assertSame('/api/article', $payload['links']['article']['href']);
    }

    #[Test]
    public function handle_passes_anonymous_account_from_request_to_discovery_controller(): void
    {
        $router = $this->createRouter($this->createManagerWithArticle());
        $request = $this->createDiscoveryRequest($this->createAccount(authenticated: false));

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['self' => '/api'], $payload['links']);
        self::assertStringNotContainsString('article', (string) $response->getContent());
    }

    // --- Helpers ---

    private function createManagerWithArticle(): EntityTypeManager
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $etm->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));

        return $etm;
    }

    private function createDiscoveryRequest(AccountInterface $account): Request
    {
        $request = Request::create('/api');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\ApiDiscoveryController');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', new BroadcastStorage(DBALDatabase::createSqlite()));

        return $request;
    }

    private function createAccount(bool $authenticated): AccountInterface
    {
        return new class($authenticated) implements AccountInterface {
            public function __construct(private readonly bool $authenticated) {}

            public function id(): int|string
            {
                return $this->authenticated ? 1 : 0;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return [$this->authenticated ? 'authenticated' : 'anonymous'];
            }

            public function isAuthenticated(): bool
            {
                return $this->authenticated;
            }
        };
    }
}
