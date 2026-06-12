<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase7;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\ApiDiscoveryController;
use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * End-to-end exercise of the public `api.discovery` route.
 *
 * Walks the full surface contract documented in `docs/specs/api-layer.md`:
 * register entity types via `EntityTypeManager`, register routes via
 * `JsonApiRouteProvider`, match the discovery path through `WaaseyaaRouter`,
 * and assert the `ApiDiscoveryController::discover()` response shape against
 * the documented invariants. WP06 / closes #841.
 */
#[CoversNothing]
final class ApiDiscoveryIntegrationTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private WaaseyaaRouter $router;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'tag',
            label: 'Tag',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));

        $this->router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        new JsonApiRouteProvider($this->entityTypeManager)->registerRoutes($this->router);
    }

    #[Test]
    public function discoveryRouteIsRegisteredAndPublic(): void
    {
        $route = $this->router->getRouteCollection()->get('api.discovery');

        self::assertNotNull($route, 'JsonApiRouteProvider must register api.discovery');
        self::assertSame('/api', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
        self::assertSame(
            'Waaseyaa\\Api\\ApiDiscoveryController::discover',
            $route->getDefault('_controller'),
        );
        self::assertTrue($route->getOption('_public'), 'api.discovery must be public');
    }

    #[Test]
    public function routerMatchesBasePathToDiscoveryRoute(): void
    {
        $match = $this->router->match('/api');

        self::assertSame('api.discovery', $match['_route']);
        self::assertSame(
            'Waaseyaa\\Api\\ApiDiscoveryController::discover',
            $match['_controller'],
        );
    }

    #[Test]
    public function discoverReturnsContractEnvelopeAndSelfLink(): void
    {
        $controller = new ApiDiscoveryController($this->entityTypeManager);

        $document = $controller->discover();

        self::assertArrayHasKey('meta', $document);
        self::assertSame('waaseyaa', $document['meta']['api']);
        self::assertSame('1.0', $document['meta']['version']);

        self::assertArrayHasKey('links', $document);
        self::assertArrayHasKey('self', $document['links']);
        self::assertSame('/api', $document['links']['self']);
    }

    #[Test]
    public function discoverEnumeratesEveryRegisteredEntityTypeForAuthenticatedAccounts(): void
    {
        $controller = new ApiDiscoveryController(
            $this->entityTypeManager,
            account: $this->authenticatedAccount(),
        );

        $document = $controller->discover();
        $links = $document['links'];

        $entityLinkKeys = array_values(array_diff(array_keys($links), ['self']));
        sort($entityLinkKeys);
        self::assertSame(['article', 'tag'], $entityLinkKeys);

        foreach (['article', 'tag'] as $typeId) {
            self::assertIsArray($links[$typeId]);
            self::assertSame('/api/' . $typeId, $links[$typeId]['href']);
            self::assertSame(['type' => $typeId], $links[$typeId]['meta']);
        }
    }

    #[Test]
    public function discoverListsZeroEntityTypesForAnonymousCallers(): void
    {
        // FR-001 (#1649): the route stays public, but anonymous callers get the
        // envelope only — no registered type id appears anywhere in the body.
        $anonymous = new ApiDiscoveryController($this->entityTypeManager, account: $this->anonymousAccount());
        $accountless = new ApiDiscoveryController($this->entityTypeManager);

        foreach ([$anonymous, $accountless] as $controller) {
            $document = $controller->discover();

            self::assertSame(['self' => '/api'], $document['links']);
            self::assertSame('waaseyaa', $document['meta']['api']);

            $encoded = json_encode($document, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('article', $encoded);
            self::assertStringNotContainsString('tag', $encoded);
        }
    }

    #[Test]
    public function discoveryHrefAlignsWithJsonApiIndexRoute(): void
    {
        $controller = new ApiDiscoveryController(
            $this->entityTypeManager,
            account: $this->authenticatedAccount(),
        );

        $document = $controller->discover();

        foreach (['article', 'tag'] as $typeId) {
            $href = $document['links'][$typeId]['href'];
            $match = $this->router->match($href);

            self::assertSame(
                "api.{$typeId}.index",
                $match['_route'],
                'Discovery href must resolve to the entity type index route',
            );
            self::assertSame($typeId, $match['_entity_type']);
        }
    }

    #[Test]
    public function discoverWithEmptyManagerReturnsOnlySelfLink(): void
    {
        $emptyManager = new EntityTypeManager(new EventDispatcher());
        $controller = new ApiDiscoveryController($emptyManager, account: $this->authenticatedAccount());

        $document = $controller->discover();

        self::assertSame(['self' => '/api'], $document['links']);
    }

    #[Test]
    public function discoverHonoursCustomBasePath(): void
    {
        $controller = new ApiDiscoveryController(
            $this->entityTypeManager,
            '/jsonapi',
            $this->authenticatedAccount(),
        );

        $document = $controller->discover();

        self::assertSame('/jsonapi', $document['links']['self']);
        self::assertSame('/jsonapi/article', $document['links']['article']['href']);
        self::assertSame('/jsonapi/tag', $document['links']['tag']['href']);
    }

    // --- Helpers ---

    private function authenticatedAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 1;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function anonymousAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 0;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return ['anonymous'];
            }

            public function isAuthenticated(): bool
            {
                return false;
            }
        };
    }
}
