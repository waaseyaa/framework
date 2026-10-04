<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\SSR\SsrServiceProvider;
use Waaseyaa\SSR\ThemeServiceProvider;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\User\AnonymousUser;

final class SsrKernelRouteMetadataTest extends TestCase
{
    public function testRealKernelInspectsMatchesAndExecutesAllThreePublicRoutes(): void
    {
        $project = sys_get_temp_dir() . '/waaseyaa_ssr_metadata_' . bin2hex(random_bytes(8));
        mkdir($project . '/config', 0o755, true);
        mkdir($project . '/storage', 0o755, true);
        mkdir($project . '/vendor/composer', 0o755, true);
        $databasePath = $project . '/runtime.sqlite';
        file_put_contents($project . '/config/waaseyaa.php', "<?php return ['database' => " . var_export($databasePath, true) . ", 'environment' => 'testing', 'routing' => ['mode' => 'canonical'], 'api_catalog' => ['base_url' => 'https://trusted.example']];");
        file_put_contents($project . '/config/entity-types.php', "<?php return [new \\Waaseyaa\\Entity\\EntityType(id: 'test', label: 'Test', class: \\stdClass::class, keys: ['id' => 'id'])];");
        // Match the published SSR roster: Theme initializes this kernel's Twig
        // environment before SSR adds extensions, even after an earlier render.
        file_put_contents($project . '/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'waaseyaa/audit', 'extra' => ['waaseyaa' => ['providers' => [\Waaseyaa\Audit\AuditServiceProvider::class]]]], ['name' => 'waaseyaa/ssr', 'extra' => ['waaseyaa' => ['providers' => [ThemeServiceProvider::class, SsrServiceProvider::class]]]]]], JSON_THROW_ON_ERROR));
        $schemaDatabase = \Waaseyaa\Database\DBALDatabase::createSqlite($databasePath, 'testing');
        RuntimeSchemaMigrations::audit($schemaDatabase);
        RuntimeSchemaMigrations::broadcast($schemaDatabase);
        $schemaDatabase->getConnection()->close();
        unset($schemaDatabase);
        RuntimeSchemaMigrations::entitiesForProject($project);
        $previousTheme = ThemeServiceProvider::getTwigEnvironment();
        try {
            $kernel = new HttpKernel($project);
            $kernel->bootForCli();
            self::assertNotSame($previousTheme, ThemeServiceProvider::getTwigEnvironment());
            self::assertSame(ThemeServiceProvider::getTwigEnvironment(), SsrServiceProvider::getTwigEnvironment());
            $snapshot = $kernel->getRouteSnapshot();
            self::assertCount(19, $snapshot->routes);
            $kinds = array_column($kernel->getRouteParticipation()->records, 'kind', 'provider');
            self::assertSame('declarative', $kinds[SsrServiceProvider::class]);
            $database = $kernel->getDatabase();
            $storage = new BroadcastStorage($database);
            $wrongMethod = Request::create('https://evil.example/robots.txt', 'POST');
            $refusal = new \ReflectionMethod(HttpKernel::class, 'matchRoute')->invoke($kernel, '/robots.txt', 'POST', $wrongMethod);
            self::assertInstanceOf(Response::class, $refusal);
            self::assertSame(405, $refusal->getStatusCode());
            foreach (['/robots.txt' => 'Sitemap: https://trusted.example/sitemap.xml', '/sitemap.xml' => '<urlset', '/llms.txt' => '# Waaseyaa'] as $path => $expectedBody) {
                $request = Request::create('https://evil.example' . $path);
                $match = new \ReflectionMethod(HttpKernel::class, 'matchRoute')->invoke($kernel, $path, 'GET', $request);
                self::assertSame($request, $match);
                self::assertSame('canonical', $request->attributes->get('_waaseyaa_route_mode'));
                self::assertStringStartsWith('class:Waaseyaa\\SSR\\Http\\SeoPublicController::', $request->attributes->get('_controller'));
                self::assertTrue($request->attributes->get('_route_object')->getOption('_public'));
                self::assertSame(10, $request->attributes->get('_route_object')->getOption('_waaseyaa_priority'));
                $request->attributes->set('_account', new AnonymousUser());
                $response = new \ReflectionMethod(HttpKernel::class, 'dispatchMatchedRequest')->invoke($kernel, $request, $storage);
                self::assertInstanceOf(Response::class, $response);
                self::assertSame(200, $response->getStatusCode());
                self::assertStringContainsString($expectedBody, $response->getContent());
                self::assertStringNotContainsString('evil.example', $response->getContent());
                self::assertSame($snapshot, $kernel->getRouteSnapshot());
            }
        } finally {
            ProcessFieldReadRuntime::reset();
            SsrServiceProvider::setTwigEnvironment(null);
            new \ReflectionProperty(ThemeServiceProvider::class, 'twigEnvironment')->setValue(null, $previousTheme);
            if (isset($database)) {
                $database->getConnection()->close();
            }
            unset($kernel, $database, $storage, $request, $match, $response, $wrongMethod, $refusal);
            gc_collect_cycles();
            new Filesystem()->remove($project);
        }
    }
}
