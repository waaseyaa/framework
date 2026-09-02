<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\GraphQL\GraphQlServiceProvider;
use Waaseyaa\GraphQL\Http\Router\GraphQlRouter;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(GraphQlServiceProvider::class)]
final class GraphQlServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_the_graphql_endpoint_through_the_package_service_provider(): void
    {
        $router = new WaaseyaaRouter();
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());

        (new GraphQlServiceProvider())->routes($router, $entityTypeManager);

        $route = $router->getRouteCollection()->get('graphql.endpoint');
        $this->assertNotNull($route);
        $this->assertSame('/graphql', $route->getPath());
    }

    #[Test]
    public function http_domain_routers_passes_the_boot_scoped_field_registry(): void
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_graphql_provider_' . uniqid();
        mkdir($projectRoot . '/config', 0o755, true);
        mkdir($projectRoot . '/storage', 0o755, true);
        mkdir($projectRoot . '/vendor/composer', 0o755, true);
        file_put_contents(
            $projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );
        file_put_contents(
            $projectRoot . '/config/entity-types.php',
            "<?php return [new \\Waaseyaa\\Entity\\EntityType(id: 'test', label: 'Test', class: \\stdClass::class, keys: ['id' => 'id'])];",
        );

        try {
            $kernel = new HttpKernel($projectRoot);
            $kernel->bootForCli();

            $routers = iterator_to_array((new GraphQlServiceProvider())->httpDomainRouters($kernel));

            self::assertCount(1, $routers);
            self::assertInstanceOf(GraphQlRouter::class, $routers[0]);
        } finally {
            new Filesystem()->remove($projectRoot);
        }
    }
}
