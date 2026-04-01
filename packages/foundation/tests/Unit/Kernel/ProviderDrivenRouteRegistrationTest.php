<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ProviderDrivenRouteRegistrationTest extends TestCase
{
    #[Test]
    public function provider_declared_request_packages_register_routes_without_foundation_special_casing(): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $compiler = new PackageManifestCompiler($repoRoot, $repoRoot . '/storage');
        $compiledManifest = $compiler->compile();

        $providers = array_values(array_filter(
            $compiledManifest->providers,
            static fn(string $provider): bool => in_array($provider, [
                'Waaseyaa\\Api\\ApiServiceProvider',
                'Waaseyaa\\GraphQL\\GraphQlServiceProvider',
            ], true),
        ));

        $manifest = new PackageManifest(
            providers: $providers,
            commands: [],
            routes: [],
            migrations: [],
            fieldTypes: [],
            formatters: [],
            listeners: [],
            middleware: [],
            permissions: [],
            policies: [],
        );

        $dispatcher = new EventDispatcher();
        $entityTypeManager = new EntityTypeManager($dispatcher);
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));

        $database = DBALDatabase::createSqlite();
        $registry = new ProviderRegistry(new NullLogger());
        $providerInstances = $registry->discoverAndRegister(
            manifest: $manifest,
            projectRoot: $repoRoot,
            config: [],
            entityTypeManager: $entityTypeManager,
            database: $database,
            dispatcher: $dispatcher,
        );

        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $registrar = new BuiltinRouteRegistrar($entityTypeManager, $providerInstances);
        $registrar->register($router);

        $routes = $router->getRouteCollection();
        $this->assertNotNull($routes->get('api.discovery'));
        $this->assertNotNull($routes->get('graphql.endpoint'));
    }
}
