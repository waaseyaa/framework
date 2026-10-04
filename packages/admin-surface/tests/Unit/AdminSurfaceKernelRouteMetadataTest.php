<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Audit\AuditServiceProvider;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\User\AnonymousUser;

final class AdminSurfaceKernelRouteMetadataTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testRealKernelAdmitsAdminWithoutLoadingExecutionServicesDuringInspection(): void
    {
        $project = sys_get_temp_dir() . '/waaseyaa_admin_metadata_' . bin2hex(random_bytes(8));
        mkdir($project . '/config', 0o755, true);
        mkdir($project . '/storage', 0o755, true);
        mkdir($project . '/vendor/composer', 0o755, true);
        $databasePath = $project . '/runtime.sqlite';
        file_put_contents($project . '/config/waaseyaa.php', "<?php return ['database' => " . var_export($databasePath, true) . ", 'environment' => 'testing', 'routing' => ['mode' => 'canonical'], 'api_catalog' => ['base_url' => 'https://trusted.example']];");
        file_put_contents($project . '/config/entity-types.php', "<?php return [new \\Waaseyaa\\Entity\\EntityType(id: 'test', label: 'Test', class: \\stdClass::class, keys: ['id' => 'id'])];");
        $packages = [];
        foreach (['waaseyaa/audit' => AuditServiceProvider::class, 'waaseyaa/api' => ApiServiceProvider::class, 'waaseyaa/admin-surface' => AdminSurfaceServiceProvider::class] as $name => $provider) {
            $packages[] = ['name' => $name, 'extra' => ['waaseyaa' => ['providers' => [$provider]]]];
        }
        file_put_contents($project . '/vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
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
            self::assertCount(50, $snapshot->routes);
            $kinds = array_column($kernel->getRouteParticipation()->records, 'kind', 'provider');
            self::assertSame('declarative', $kinds[AdminSurfaceServiceProvider::class]);
            self::assertSame('declarative', $kinds[ApiServiceProvider::class]);
            $request = Request::create('/admin/_surface/session');
            self::assertSame($request, new \ReflectionMethod(HttpKernel::class, 'matchRoute')->invoke($kernel, '/admin/_surface/session', 'GET', $request));
            self::assertSame('canonical', $request->attributes->get('_waaseyaa_route_mode'));
            $request->attributes->set('_account', new AnonymousUser());
            $database = $kernel->getDatabase();
            $storage = new BroadcastStorage($database);
            $response = new \ReflectionMethod(HttpKernel::class, 'dispatchMatchedRequest')->invoke($kernel, $request, $storage);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('application/json', $response->headers->get('Content-Type'));
            self::assertSame(false, json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['ok']);
            self::assertSame($snapshot, $kernel->getRouteSnapshot());
        } finally {
            ProcessFieldReadRuntime::reset();
            if (isset($database)) {
                $database->getConnection()->close();
            }
            unset($kernel, $database, $storage, $request, $response);
            gc_collect_cycles();
            new Filesystem()->remove($project);
        }
    }
}
