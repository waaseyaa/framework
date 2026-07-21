<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Oidc\Entity\OidcClient;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Verifies that ApiServiceProvider::routes() registers all routes that were
 * previously hard-coded in BuiltinRouteRegistrar with Waaseyaa\Api\* FQCN
 * string literals (WP5 route-table inversion, foundation wave-2).
 *
 * End-to-end route equivalence contract: after the inversion, calling
 * ApiServiceProvider::routes() must yield the same named routes + access
 * options that BuiltinRouteRegistrar previously registered directly.
 */
#[CoversClass(ApiServiceProvider::class)]
final class ApiServiceProviderAdminRoutesTest extends TestCase
{
    private WaaseyaaRouter $router;

    protected function setUp(): void
    {
        $this->router = new WaaseyaaRouter();
        (new ApiServiceProvider())->routes($this->router, new EntityTypeManager(new EventDispatcher()));
    }

    #[Test]
    public function registers_schema_route_with_authentication(): void
    {
        $route = $this->router->getRouteCollection()->get('api.schema.show');
        $this->assertNotNull($route, 'api.schema.show must be registered by ApiServiceProvider::routes().');
        $this->assertTrue((bool) $route->getOption('_authenticated'), 'api.schema.show must require authentication.');
        $this->assertSame('/api/schema/{entity_type}', $route->getPath());
    }

    #[Test]
    public function registers_workflow_definition_routes(): void
    {
        $routes = $this->router->getRouteCollection();
        $this->assertNotNull($routes->get('api.workflow_definitions.list'));
    }

    #[Test]
    public function registers_queue_admin_routes(): void
    {
        $routes = $this->router->getRouteCollection();
        $this->assertNotNull($routes->get('api.queue.jobs.index'));
        $this->assertNotNull($routes->get('api.queue.jobs.retry'));
        $this->assertNotNull($routes->get('api.queue.jobs.discard'));
    }

    #[Test]
    public function registers_scheduler_admin_routes(): void
    {
        $routes = $this->router->getRouteCollection();
        $this->assertNotNull($routes->get('api.scheduler.tasks.index'));
        $this->assertNotNull($routes->get('api.scheduler.tasks.trigger'));
    }

    #[Test]
    public function registers_notification_admin_routes(): void
    {
        $routes = $this->router->getRouteCollection();
        $this->assertNotNull($routes->get('api.notification.channels.index'));
        $this->assertNotNull($routes->get('api.notification.channels.test'));
    }

    #[Test]
    public function doesNotRegisterProducerlessTelescopeRoutes(): void
    {
        $routes = $this->router->getRouteCollection();
        $this->assertNull($routes->get('api.telescope.agent_context.sessions'));
        $this->assertNull($routes->get('api.telescope.codified_context.sessions'));
    }

    #[Test]
    public function registers_mercure_monitor_routes(): void
    {
        $routes = $this->router->getRouteCollection();
        $this->assertNotNull($routes->get('api.mercure.monitor.channels'));
        $this->assertNotNull($routes->get('api.mercure.monitor.events'));
        $this->assertNotNull($routes->get('api.mercure.monitor.subscribers'));
    }

    #[Test]
    public function registers_media_version_routes_with_authentication(): void
    {
        $routes = $this->router->getRouteCollection();
        $index = $routes->get('api.media.versions.index');
        $show = $routes->get('api.media.versions.show');
        $this->assertNotNull($index);
        $this->assertNotNull($show);
        $this->assertTrue((bool) $index->getOption('_authenticated'), 'api.media.versions.index must require authentication.');
        $this->assertTrue((bool) $show->getOption('_authenticated'), 'api.media.versions.show must require authentication.');
    }

    #[Test]
    public function registers_audit_events_route(): void
    {
        $this->assertNotNull($this->router->getRouteCollection()->get('api.audit.events.index'));
    }

    #[Test]
    public function registers_mcp_admin_routes(): void
    {
        $routes = $this->router->getRouteCollection();
        $this->assertNotNull($routes->get('api.mcp.admin.tools.index'));
        $this->assertNotNull($routes->get('api.mcp.admin.tools.show'));
        $this->assertNotNull($routes->get('api.mcp.admin.server-config'));
    }

    #[Test]
    public function registers_oidc_client_crud_routes(): void
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerEntityType(EntityType::fromClass(OidcClient::class));
        $router = new WaaseyaaRouter();
        (new ApiServiceProvider())->routes($router, $entityTypeManager);
        $routes = $router->getRouteCollection();
        $this->assertNotNull($routes->get('api.oidc-clients.index'));
        $this->assertNotNull($routes->get('api.oidc-clients.create'));
        $this->assertNotNull($routes->get('api.oidc-clients.show'));
        $this->assertNotNull($routes->get('api.oidc-clients.update'));
        $this->assertNotNull($routes->get('api.oidc-clients.delete'));
        $this->assertNotNull($routes->get('api.oidc-clients.regenerate-secret'));
    }

    #[Test]
    public function omits_oidc_client_routes_when_the_domain_is_not_registered(): void
    {
        $router = new WaaseyaaRouter();
        (new ApiServiceProvider())->routes($router, new EntityTypeManager(new EventDispatcher()));

        $routes = $router->getRouteCollection();
        $this->assertNull($routes->get('api.oidc-clients.index'));
        $this->assertNull($routes->get('api.oidc-clients.create'));
        $this->assertNull($routes->get('api.oidc-clients.show'));
        $this->assertNull($routes->get('api.oidc-clients.update'));
        $this->assertNull($routes->get('api.oidc-clients.delete'));
        $this->assertNull($routes->get('api.oidc-clients.regenerate-secret'));
    }

    #[Test]
    public function registers_classification_policy_routes(): void
    {
        $routes = $this->router->getRouteCollection();
        $this->assertNotNull($routes->get('api.classification.policies.index'));
        $this->assertNotNull($routes->get('api.classification.policies.show'));
        $this->assertNotNull($routes->get('api.classification.policies.store'));
        $this->assertNotNull($routes->get('api.classification.policies.update'));
        $this->assertNotNull($routes->get('api.classification.policies.destroy'));
    }
}
