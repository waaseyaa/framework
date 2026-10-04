<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\Bimaaji;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Audit\AuditServiceProvider;
use Waaseyaa\Bimaaji\BimaajiServiceProvider;
use Waaseyaa\CLI\Provider\MiscBServiceProvider;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\User\AnonymousUser;

#[CoversClass(ConsoleKernel::class)]
#[CoversClass(BimaajiServiceProvider::class)]
final class CanonicalKernelGraphTest extends TestCase
{
    public static function profiles(): iterable
    {
        yield 'complete authority' => [false];
        yield 'installed legacy refusal' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('profiles')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRealCommandsAndHttpShareNonemptyApplicationDeclarations(bool $legacyPresent): void
    {
        $project = sys_get_temp_dir() . '/waaseyaa_graph_metadata_' . bin2hex(random_bytes(8));
        mkdir($project . '/config', 0o755, true);
        mkdir($project . '/storage', 0o755, true);
        mkdir($project . '/vendor/composer', 0o755, true);
        $databasePath = $project . '/runtime.sqlite';
        file_put_contents($project . '/config/waaseyaa.php', "<?php return ['database' => " . var_export($databasePath, true) . ", 'environment' => 'testing', 'routing' => ['mode' => 'canonical']];");
        file_put_contents($project . '/config/entity-types.php', "<?php return [new \\Waaseyaa\\Entity\\EntityType(id: 'test', label: 'Test', class: \\stdClass::class, keys: ['id' => 'id'])];");
        $packages = [];
        foreach (['waaseyaa/audit' => AuditServiceProvider::class, 'waaseyaa/bimaaji' => BimaajiServiceProvider::class, 'waaseyaa/cli' => MiscBServiceProvider::class, 'application/fixture' => CanonicalGraphFixtureProvider::class] as $name => $provider) {
            $packages[] = ['name' => $name, 'extra' => ['waaseyaa' => ['providers' => [$provider]]]];
        }
        if ($legacyPresent) {
            $packages[] = ['name' => 'application/legacy', 'extra' => ['waaseyaa' => ['providers' => [LegacyGraphFixtureProvider::class]]]];
        }
        file_put_contents($project . '/vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
        $schemaDatabase = \Waaseyaa\Database\DBALDatabase::createSqlite($databasePath, 'testing');
        RuntimeSchemaMigrations::audit($schemaDatabase);
        RuntimeSchemaMigrations::broadcast($schemaDatabase);
        $schemaDatabase->getConnection()->close();
        unset($schemaDatabase);
        RuntimeSchemaMigrations::entitiesForProject($project);
        try {
            $console = new ConsoleKernel($project);
            $console->bootForCli();
            [$exit, $raw] = self::command($console, ['graph:dump', '--strict']);
            if ($legacyPresent) {
                self::assertSame(1, $exit, $raw);
                self::assertStringNotContainsString('"sections"', $raw);
                [$exit, $listing] = self::command($console, ['route:list']);
                self::assertSame(1, $exit, $listing);
                self::assertSame(0, LegacyGraphFixtureProvider::$calls);
                self::assertSame(0, CanonicalGraphFixtureController::$calls);
                return;
            }
            self::assertSame(0, $exit, $raw);
            $graph = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(6, $graph['sections']);
            self::assertSame('/native/<info>', $graph['sections']['routing']['data']['application.health']['path']);
            self::assertSame('public', $graph['sections']['public_surface']['data']['application.health']['auth']);
            self::assertSame(0, CanonicalGraphFixtureController::$calls);
            [$exit, $repeated] = self::command($console, ['graph:dump', '--strict']);
            self::assertSame(0, $exit, $repeated);
            self::assertSame($raw, $repeated);
            [$exit, $listing] = self::command($console, ['route:list']);
            self::assertSame(0, $exit, $listing);
            self::assertStringContainsString('application.health', $listing);
            $http = new HttpKernel($project);
            $http->bootForCli();
            self::assertSame($console->getRouteSnapshot()->toArray()['routes'], $http->getRouteSnapshot()->toArray()['routes']);
            $request = Request::create('/native/<info>');
            self::assertSame($request, new \ReflectionMethod(HttpKernel::class, 'matchRoute')->invoke($http, '/native/<info>', 'GET', $request));
            $request->attributes->set('_account', new AnonymousUser());
            $database = $http->getDatabase();
            $storage = new BroadcastStorage($database);
            $response = new \ReflectionMethod(HttpKernel::class, 'dispatchMatchedRequest')->invoke($http, $request, $storage);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('native health', $response->getContent());
            self::assertSame(1, CanonicalGraphFixtureController::$calls);
        } finally {
            ProcessFieldReadRuntime::reset();
            if (isset($console)) {
                $console->getDatabase()->getConnection()->close();
            }
            if (isset($database)) {
                $database->getConnection()->close();
            }
            unset($console, $http, $database, $storage, $request, $response);
            gc_collect_cycles();
            new Filesystem()->remove($project);
        }
    }
    /** @return array{int, string} */
    private static function command(ConsoleKernel $kernel, array $arguments): array
    {
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $application = new \Waaseyaa\CLI\ConsoleApplicationFactory($kernel, $kernel->buildHandlerContainer(), $kernel->getProviders())->create();
        $exit = $application->run(new \Symfony\Component\Console\Input\ArgvInput(['waaseyaa', ...$arguments]), $output);
        return [$exit, $output->fetch()];
    }
}

final class CanonicalGraphFixtureProvider extends ServiceProvider implements ContributesRouteMetadataInterface
{
    public function register(): void
    {
        $this->bind(CanonicalGraphFixtureController::class, static fn() => new CanonicalGraphFixtureController());
    }

    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        yield new RouteDefinition('application.health', '/native/<info>', HandlerReference::fromString('class:' . CanonicalGraphFixtureController::class . '::health'), methods: ['GET'], options: ['_public' => true], sourceId: $context->sourceId);
    }
}

final class CanonicalGraphFixtureController
{
    public static int $calls = 0;

    public function health(Request $request): Response
    {
        self::$calls++;
        return new Response('native health');
    }
}

final class LegacyGraphFixtureProvider extends ServiceProvider
{
    public function register(): void {}
    public static int $calls = 0;
    public function routes(\Waaseyaa\Routing\WaaseyaaRouter $router, \Waaseyaa\Entity\EntityTypeManager $entityTypeManager): void
    {
        self::$calls++;
        throw new \LogicException('Introspection must not replay legacy execution hooks.');
    }
}
