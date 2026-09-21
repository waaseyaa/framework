<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Bimaaji\Introspection\Routing\RoutingIntrospectionProvider;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\DbInitHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Tests\Regression\RouteMetadata\Fixtures\InspectableApplicationServiceProvider;

if (count($argv) !== 4) {
    fwrite(STDERR, "Usage: php route_metadata_runner.php <init|http|inspect> <repo-root> <project-root>\n");
    exit(2);
}

[, $action, $repoRoot, $projectRoot] = $argv;
require $repoRoot . '/vendor/autoload.php';
require_once __DIR__ . '/InspectableApplicationServiceProvider.php';

if ($action === 'init') {
    $command = new HandlerCommand(
        name: 'db:init',
        description: 'Initialize the acceptance fixture.',
        options: [
            new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None, description: 'Dry run.'),
            new HandlerOption(name: 'no-sync-schema', mode: HandlerOptionMode::None, description: 'Skip schema sync.'),
        ],
        handler: \Closure::fromCallable([new DbInitHandler($projectRoot), 'execute']),
    );
    $container = new class implements ContainerInterface {
        public function get(string $id): mixed
        {
            throw new \RuntimeException("Not found: {$id}");
        }
        public function has(string $id): bool
        {
            return false;
        }
    };
    $tester = CliTester::for($command, $container);
    $tester->execute([]);
    echo json_encode(['exit' => $tester->getExitCode(), 'stdout' => $tester->getStdout(), 'stderr' => $tester->getStderr()], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action === 'http') {
    $_GET = $_POST = $_COOKIE = $_FILES = [];
    $_SERVER = [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/application/inspectable',
        'QUERY_STRING' => '',
        'HTTP_HOST' => 'localhost',
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '80',
        'HTTPS' => 'off',
        'REMOTE_ADDR' => '127.0.0.1',
        'REQUEST_TIME_FLOAT' => microtime(true),
    ];

    $response = new HttpKernel($projectRoot)->handle();
    echo json_encode([
        'status' => $response->getStatusCode(),
        'body' => (string) $response->getContent(),
        'counters' => InspectableApplicationServiceProvider::counters(),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action === 'inspect') {
    // bootForCli() is intentional application bootstrap. Its activity is kept
    // separate from route contribution so the regression assertion targets
    // only work caused by inspecting the completed route table.
    $kernel = new HttpKernel($projectRoot);
    $kernel->bootForCli();
    $afterBoot = InspectableApplicationServiceProvider::counters();

    $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
    new BuiltinRouteRegistrar($kernel->getEntityTypeManager(), $kernel->getProviders())->register($router);
    $section = new RoutingIntrospectionProvider($router->getRouteCollection())->provide();

    echo json_encode([
        'after_boot' => $afterBoot,
        'after_inspection' => InspectableApplicationServiceProvider::counters(),
        'route' => $section->data['application.inspectable'] ?? null,
        'route_count' => count($section->data),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

fwrite(STDERR, "Unknown action: {$action}\n");
exit(2);
