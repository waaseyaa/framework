<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge;
use Waaseyaa\Mcp\Bridge\ToolExecutorInterface;
use Waaseyaa\Mcp\Bridge\ToolRegistryInterface;
use Waaseyaa\Mcp\McpServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(McpServiceProvider::class)]
final class McpServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_mcp_routes_through_the_package_service_provider(): void
    {
        $router = new WaaseyaaRouter();
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());

        new McpServiceProvider()->routes($router, $entityTypeManager);

        $routes = $router->getRouteCollection();
        self::assertNotNull($routes->get('mcp.endpoint'));
        self::assertNotNull($routes->get('mcp.server_card'));
    }

    #[Test]
    public function registers_default_mcp_auth_binding(): void
    {
        $provider = $this->bootedProvider();

        $auth = $provider->resolve(McpAuthInterface::class);
        self::assertInstanceOf(BearerTokenAuth::class, $auth);
        // Default token map is empty: any Authorization header authenticates
        // to no account (production overrides).
        self::assertNull($auth->authenticate('Bearer anything'));
    }

    #[Test]
    public function binds_bridge_to_both_mcp_interfaces_via_a_single_instance(): void
    {
        $provider = $this->bootedProvider();

        $registry = $provider->resolve(ToolRegistryInterface::class);
        $executor = $provider->resolve(ToolExecutorInterface::class);

        self::assertInstanceOf(AgentToolRegistryBridge::class, $registry);
        self::assertSame($registry, $executor, 'Bridge must be the same instance for both abstracts.');
    }

    #[Test]
    public function throws_when_agent_tool_registry_is_not_on_the_kernel_services_bus(): void
    {
        $provider = new McpServiceProvider();
        $provider->setKernelContext(projectRoot: '', config: [], manifestFormatters: []);
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                return null;
            }
        });
        $provider->register();

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessageMatches('/no Waaseyaa\\\\AI\\\\Tools\\\\ToolRegistryInterface bound/');
        $provider->resolve(ToolRegistryInterface::class);
    }

    private function bootedProvider(): McpServiceProvider
    {
        $provider = new McpServiceProvider();
        $provider->setKernelContext(projectRoot: '', config: [], manifestFormatters: []);
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                if ($abstract === AgentToolRegistryInterface::class) {
                    return new StubAgentToolRegistry();
                }

                return null;
            }
        });
        $provider->register();

        return $provider;
    }
}

final class StubAgentToolRegistry implements AgentToolRegistryInterface
{
    public function register(AgentTool $tool): void
    {
        // no-op
    }

    public function get(string $name): AgentTool
    {
        throw ToolNotFoundException::forName($name);
    }

    public function has(string $name): bool
    {
        return false;
    }

    public function all(): iterable
    {
        return [];
    }
}
