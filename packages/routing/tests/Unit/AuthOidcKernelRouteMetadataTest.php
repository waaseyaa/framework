<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Routing\AuthOidcRouteServiceProvider;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\User\AnonymousUser;

final class AuthOidcKernelRouteMetadataTest extends TestCase
{
    public static function oidcPresenceCases(): iterable
    {
        yield 'declared OIDC bindings' => [true];
        yield 'absent OIDC bindings' => [false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('oidcPresenceCases')]
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testRealKernelUsesFrozenBindingsAndExecutesSelectedControllers(bool $oidcPresent): void
    {
        AuthMetadataKernelFixtureProvider::$oidcPresent = $oidcPresent;
        $project = sys_get_temp_dir() . '/waaseyaa_auth_metadata_' . bin2hex(random_bytes(8));
        mkdir($project . '/config', 0o755, true);
        mkdir($project . '/storage', 0o755, true);
        mkdir($project . '/vendor/composer', 0o755, true);
        $databasePath = $project . '/runtime.sqlite';
        file_put_contents($project . '/config/waaseyaa.php', "<?php return ['database' => " . var_export($databasePath, true) . ", 'environment' => 'testing', 'routing' => ['mode' => 'canonical'], 'api_catalog' => ['base_url' => 'https://trusted.example']];");
        file_put_contents($project . '/config/entity-types.php', "<?php return [new \\Waaseyaa\\Entity\\EntityType(id: 'test', label: 'Test', class: \\stdClass::class, keys: ['id' => 'id'])];");
        file_put_contents($project . '/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'waaseyaa/audit', 'extra' => ['waaseyaa' => ['providers' => [\Waaseyaa\Audit\AuditServiceProvider::class]]]], ['name' => 'waaseyaa/routing', 'extra' => ['waaseyaa' => ['providers' => [AuthOidcRouteServiceProvider::class, AuthMetadataKernelFixtureProvider::class]]]]]], JSON_THROW_ON_ERROR));
        $schemaDatabase = \Waaseyaa\Database\DBALDatabase::createSqlite($databasePath, 'testing');
        RuntimeSchemaMigrations::audit($schemaDatabase);
        RuntimeSchemaMigrations::broadcast($schemaDatabase);
        $schemaDatabase->getConnection()->close();
        unset($schemaDatabase);
        RuntimeSchemaMigrations::entitiesForProject($project);
        try {
            $kernel = new HttpKernel($project);
            $kernel->bootForCli();
            $autoloads = [];
            $trap = static function (string $class) use (&$autoloads): void {
                $autoloads[] = $class;
                throw new \LogicException('Snapshot autoloaded ' . $class);
            };
            spl_autoload_register($trap, true, true);
            try {
                $snapshot = $kernel->getRouteSnapshot();
                $repeated = $kernel->getRouteSnapshot();
            } finally {
                spl_autoload_unregister($trap);
            }
            self::assertSame($snapshot, $repeated);
            self::assertSame([], $autoloads);
            self::assertCount($oidcPresent ? 30 : 28, $snapshot->routes);
            self::assertSame(0, AuthMetadataKernelFixtureProvider::$calls);
            self::assertSame($oidcPresent, $kernel->getRouteInputs()->capabilities['service:Waaseyaa\\Oidc\\Token\\TokenController'] ?? false);
            $kinds = array_column($kernel->getRouteParticipation()->records, 'kind', 'provider');
            self::assertSame('declarative', $kinds[AuthOidcRouteServiceProvider::class]);
            $database = $kernel->getDatabase();
            $storage = new BroadcastStorage($database);
            $wrongMethod = Request::create('https://evil.example/api/auth/logout', 'GET');
            $refusal = new \ReflectionMethod(HttpKernel::class, 'matchRoute')->invoke($kernel, '/api/auth/logout', 'GET', $wrongMethod);
            self::assertInstanceOf(Response::class, $refusal);
            self::assertSame(405, $refusal->getStatusCode());
            $cases = ['/api/auth/logout' => ['POST', 'Logged out.']];
            if ($oidcPresent) {
                $cases['/.well-known/openid-configuration'] = ['GET', 'https://issuer.example'];
            }
            foreach ($cases as $path => [$method, $expectedBody]) {
                $request = Request::create('https://evil.example' . $path, $method);
                $match = new \ReflectionMethod(HttpKernel::class, 'matchRoute')->invoke($kernel, $path, $method, $request);
                self::assertSame($request, $match);
                self::assertSame('canonical', $request->attributes->get('_waaseyaa_route_mode'));
                self::assertStringStartsWith('class:', $request->attributes->get('_controller'));
                self::assertTrue($request->attributes->get('_route_object')->getOption('_public'));
                $request->attributes->set('_account', new AnonymousUser());
                $response = new \ReflectionMethod(HttpKernel::class, 'dispatchMatchedRequest')->invoke($kernel, $request, $storage);
                self::assertInstanceOf(Response::class, $response);
                self::assertSame(200, $response->getStatusCode());
                self::assertStringContainsString($expectedBody, json_encode(json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                self::assertStringNotContainsString('evil.example', $response->getContent());
                self::assertSame($snapshot, $kernel->getRouteSnapshot());
            }
            if ($oidcPresent) {
                $request = Request::create('/oidc/token', 'POST');
                new \ReflectionMethod(HttpKernel::class, 'matchRoute')->invoke($kernel, '/oidc/token', 'POST', $request);
                $request->attributes->set('_account', new AnonymousUser());
                $response = new \ReflectionMethod(HttpKernel::class, 'dispatchMatchedRequest')->invoke($kernel, $request, $storage);
                self::assertSame(500, $response->getStatusCode());
                self::assertStringNotContainsString('private fixture failure', $response->getContent());
                self::assertSame(2, AuthMetadataKernelFixtureProvider::$calls);
                self::assertSame($snapshot, $kernel->getRouteSnapshot());
            } else {
                self::assertNotContains('oidc.token', array_column($snapshot->routes, 'name'));
                self::assertSame(0, AuthMetadataKernelFixtureProvider::$calls);
            }
        } finally {
            ProcessFieldReadRuntime::reset();
            if (isset($database)) {
                $database->getConnection()->close();
            }
            unset($kernel, $database, $storage, $request, $match, $response, $wrongMethod, $refusal);
            gc_collect_cycles();
            new Filesystem()->remove($project);
        }
    }
}

final class AuthMetadataKernelFixtureProvider extends \Waaseyaa\Foundation\ServiceProvider\ServiceProvider
{
    public static int $calls = 0;
    public static bool $oidcPresent = true;
    public function register(): void
    {
        self::$calls = 0;
        $this->singleton(\Waaseyaa\Auth\Extension\AuthExtensionRegistry::class, static fn() => \Waaseyaa\Auth\Extension\AuthExtensionRegistry::defaults());
        if (!self::$oidcPresent) {
            return;
        }
        $this->bind(\Waaseyaa\Oidc\Discovery\DiscoveryController::class, static function () {
            self::$calls++;
            return new \Waaseyaa\Oidc\Discovery\DiscoveryController('https://issuer.example', new \Waaseyaa\Oidc\Discovery\DiscoveryDocumentBuilder());
        });
        $this->bind(\Waaseyaa\Oidc\Token\TokenController::class, static function (): object {
            self::$calls++;
            throw new \RuntimeException('private fixture failure');
        });
    }
}
