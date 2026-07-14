<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\Http\Router\McpAdminApiRouter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Mcp\McpServiceProvider;

#[CoversClass(ApiServiceProvider::class)]
final class ApiServiceProviderMcpRouterWiringTest extends TestCase
{
    #[Test]
    public function production_router_chain_contains_the_mcp_admin_router(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $database = DBALDatabase::createSqlite(':memory:');
        $kernel = new HttpKernel(sys_get_temp_dir());

        (new \ReflectionProperty(AbstractKernel::class, 'entityTypeManager'))->setValue($kernel, $manager);
        (new \ReflectionProperty(HttpKernel::class, 'discoveryHandler'))->setValue(
            $kernel,
            new DiscoveryApiHandler($manager, $database),
        );

        $toolImpl = $this->createMock(AgentToolInterface::class);
        $toolImpl->method('description')->willReturn('Reads a content item.');
        $toolRegistry = $this->createMock(ToolRegistryInterface::class);
        $toolRegistry->method('all')->willReturn([
            new AgentTool(
                name: 'content.read',
                capability: 'access content',
                destructive: false,
                dryRunSupported: false,
                category: 'content',
                inputSchema: ['type' => 'object'],
                impl: $toolImpl,
            ),
        ]);

        $mcpProvider = new McpServiceProvider();
        $mcpProvider->setKernelServices(new class($toolRegistry) implements KernelServicesInterface {
            public function __construct(private readonly ToolRegistryInterface $toolRegistry) {}

            public function get(string $abstract): ?object
            {
                return $abstract === ToolRegistryInterface::class ? $this->toolRegistry : null;
            }
        });
        $mcpProvider->register();

        $provider = new ApiServiceProvider();
        $provider->setKernelServices(new class($mcpProvider) implements KernelServicesInterface {
            public function __construct(private readonly McpServiceProvider $mcpProvider) {}

            public function get(string $abstract): ?object
            {
                try {
                    return $this->mcpProvider->resolve($abstract);
                } catch (\RuntimeException) {
                    return null;
                }
            }
        });
        $provider->register();

        $router = null;
        foreach ($provider->httpDomainRouters($kernel) as $candidate) {
            if ($candidate instanceof McpAdminApiRouter) {
                $router = $candidate;
                break;
            }
        }
        self::assertInstanceOf(McpAdminApiRouter::class, $router);

        $request = Request::create('/api/mcp/tools', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::tools');
        $response = $router->handle($request);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('content.read', $payload['data']['rows'][0]['name']);
        self::assertSame(['access content'], $payload['data']['rows'][0]['requiredCapabilities']);
    }
}
