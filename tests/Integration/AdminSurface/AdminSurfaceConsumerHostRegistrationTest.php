<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AdminSurface;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminSurfaceHostFactoryInterface;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * An application must be able to supply its own admin surface host through an
 * explicit service contract, rather than shadowing the framework's routes.
 *
 * Before #2422 `AdminSurfaceServiceProvider::routes()` always constructed a
 * `GenericAdminSurfaceHost`, so a consumer needing custom behaviour had to
 * re-register all five canonical paths under different names at a winning
 * priority — duplicating the registration and privately reimplementing the
 * refusal-status promotion. This test pins the supported replacement:
 *
 * - a bound `AdminSurfaceHostFactoryInterface` supplies the host;
 * - the canonical `admin_surface.*` names are registered exactly once against it;
 * - an install with no factory is unchanged and keeps the generic host;
 * - refusals from an application host run through the framework's own status
 *   promotion, so the transport status and the envelope agree;
 * - accidental duplicate registration is still refused by the router.
 *
 * Refs #2422.
 */
#[CoversNothing]
final class AdminSurfaceConsumerHostRegistrationTest extends TestCase
{
    public const string APPLICATION_ACCOUNT_NAME = 'Application Host Operator';

    #[Test]
    public function anApplicationSuppliedHostServesTheCanonicalSessionRoute(): void
    {
        $router = $this->routerWithApplicationHost();

        $response = $this->dispatch($router, 'admin_surface.session');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok']);
        self::assertSame(
            self::APPLICATION_ACCOUNT_NAME,
            $body['data']['account']['name'],
            'The canonical session route must be served by the application-supplied host.',
        );
    }

    #[Test]
    public function anApplicationSuppliedHostServesEveryCanonicalSurfaceRoute(): void
    {
        $router = $this->routerWithApplicationHost();

        $list = $this->decode($this->dispatch($router, 'admin_surface.list', ['type' => 'article']));
        self::assertSame('application-host', $list['data'][0]['source']);

        $get = $this->decode($this->dispatch($router, 'admin_surface.get', ['type' => 'article', 'id' => '7']));
        self::assertSame('application-host', $get['data']['source']);

        $action = $this->decode($this->dispatch(
            $this->routerWithApplicationHost('POST'),
            'admin_surface.action',
            ['type' => 'article', 'action' => 'publish'],
            'POST',
        ));
        self::assertSame('application-host', $action['data']['source']);
    }

    #[Test]
    public function theCanonicalRoutesAreRegisteredExactlyOnce(): void
    {
        $names = array_keys($this->routerWithApplicationHost()->getRouteCollection()->all());
        $surfaceNames = array_values(array_filter(
            $names,
            static fn(string $name): bool => str_starts_with($name, 'admin_surface.'),
        ));

        sort($surfaceNames);

        self::assertSame(
            [
                'admin_surface.action',
                'admin_surface.catalog',
                'admin_surface.get',
                'admin_surface.list',
                'admin_surface.session',
            ],
            $surfaceNames,
            'Supplying an application host must not add, rename, or duplicate a surface route.',
        );
    }

    #[Test]
    public function anInstallWithoutAFactoryKeepsTheGenericHost(): void
    {
        $router = $this->buildRouter(factory: null);

        $response = $this->dispatch($router, 'admin_surface.session');

        self::assertSame(
            401,
            $response->getStatusCode(),
            'With no application factory bound, the generic host must still serve the route unchanged.',
        );
        self::assertStringNotContainsString(
            self::APPLICATION_ACCOUNT_NAME,
            (string) $response->getContent(),
        );
    }

    #[Test]
    public function refusalsFromAnApplicationHostArePromotedByTheFramework(): void
    {
        $router = $this->routerWithApplicationHost();

        $response = $this->dispatch($router, 'admin_surface.get', ['type' => 'article', 'id' => 'forbidden']);

        self::assertSame(
            403,
            $response->getStatusCode(),
            'An application host must inherit the framework refusal-status promotion, not reimplement it.',
        );

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($body['ok']);
        self::assertSame(403, $body['error']['status']);
        self::assertArrayNotHasKey('statusCode', $body, 'The transport key must not leak into the envelope');
    }

    #[Test]
    public function accidentalDuplicateRegistrationIsStillRefused(): void
    {
        $router = $this->routerWithApplicationHost();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate route name registered: admin_surface.session');

        $this->providerWith($this->applicationFactory())->routes($router, $this->entityTypeManager());
    }

    private function routerWithApplicationHost(string $method = 'GET'): WaaseyaaRouter
    {
        return $this->buildRouter($this->applicationFactory(), $method);
    }

    private function buildRouter(?AdminSurfaceHostFactoryInterface $factory, string $method = 'GET'): WaaseyaaRouter
    {
        $router = new WaaseyaaRouter(new RequestContext('', $method));
        $this->providerWith($factory)->routes($router, $this->entityTypeManager());

        return $router;
    }

    private function providerWith(?AdminSurfaceHostFactoryInterface $factory): AdminSurfaceServiceProvider
    {
        $provider = new AdminSurfaceServiceProvider();
        $provider->setKernelContext(projectRoot: sys_get_temp_dir(), config: [], manifestFormatters: []);
        $provider->setKernelServices(new class ($factory) implements KernelServicesInterface {
            public function __construct(private readonly ?AdminSurfaceHostFactoryInterface $factory) {}

            public function get(string $abstract): ?object
            {
                return $abstract === AdminSurfaceHostFactoryInterface::class ? $this->factory : null;
            }
        });

        return $provider;
    }

    private function entityTypeManager(): EntityTypeManager
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));

        return $manager;
    }

    private function applicationFactory(): AdminSurfaceHostFactoryInterface
    {
        return new class implements AdminSurfaceHostFactoryInterface {
            public function createAdminSurfaceHost(): AbstractAdminSurfaceHost
            {
                return new class extends AbstractAdminSurfaceHost {
                    public function resolveSession(Request $request): ?AdminSurfaceSessionData
                    {
                        return new AdminSurfaceSessionData(
                            accountId: '9',
                            accountName: AdminSurfaceConsumerHostRegistrationTest::APPLICATION_ACCOUNT_NAME,
                            roles: ['operator'],
                            policies: [],
                        );
                    }

                    public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder
                    {
                        $catalog = new CatalogBuilder();
                        $catalog->defineEntity('article', 'Article')->group('content');

                        return $catalog;
                    }

                    public function list(string $type, \Waaseyaa\AdminSurface\Query\SurfaceQuery|array $query = []): AdminSurfaceResultData
                    {
                        return AdminSurfaceResultData::success([['id' => '1', 'source' => 'application-host']]);
                    }

                    public function get(string $type, string $id): AdminSurfaceResultData
                    {
                        if ($id === 'forbidden') {
                            return AdminSurfaceResultData::error(403, 'forbidden', 'Not yours.');
                        }

                        return AdminSurfaceResultData::success(['id' => $id, 'source' => 'application-host']);
                    }

                    public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
                    {
                        return AdminSurfaceResultData::success(['source' => 'application-host']);
                    }
                };
            }
        };
    }

    /** @param array<string, string> $routeParams */
    private function dispatch(
        WaaseyaaRouter $router,
        string $routeName,
        array $routeParams = [],
        string $method = 'GET',
    ): Response {
        $route = $router->getRouteCollection()->get($routeName);
        self::assertNotNull($route, sprintf('Route %s must be registered', $routeName));

        $request = Request::create('/', $method);
        $request->attributes->set('_controller', $route->getDefault('_controller'));
        foreach ($routeParams as $name => $value) {
            $request->attributes->set($name, $value);
        }

        return new ControllerDispatcher([])->dispatch($request);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
