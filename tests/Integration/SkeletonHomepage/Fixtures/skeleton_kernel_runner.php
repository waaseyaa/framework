<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\DbInitHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Subprocess boundary for the skeleton-homepage fixture.
 *
 * Both actions run here rather than in the PHPUnit process for the same reason:
 * a generated application resolves its `App\` classes through the PSR-4 root
 * Composer writes for it, and registering that root in the framework's own test
 * process would append a temp-directory prefix to the process-global
 * `Composer\Autoload\ClassLoader` that teardown cannot remove. That is
 * order-dependent state in a suite that also runs under `ci/random-order-*`.
 * Registering it here confines it to a process that exits immediately after.
 *
 * Actions:
 *   db-init                  initialize the fixture database
 *   request <method> <uri>   boot the production HttpKernel and serve one request
 *
 * Both emit a single JSON object on stdout.
 */
if ($argc < 4) {
    fwrite(STDERR, "Usage: php skeleton_kernel_runner.php <repo_root> <project_root> <action> [<method> <uri>]\n");
    exit(1);
}

$repoRoot = (string) $argv[1];
$projectRoot = (string) $argv[2];
$action = (string) $argv[3];

$loader = require $projectRoot . '/vendor/autoload.php';
if (!$loader instanceof ClassLoader) {
    fwrite(STDERR, "Project autoloader did not return a Composer ClassLoader.\n");
    exit(1);
}
// Subprocess-local, exactly as a real `composer create-project` install writes it.
$loader->addPsr4('App\\', $projectRoot . '/src/');

if ($action === 'db-init') {
    $command = new HandlerCommand(
        name: 'db:init',
        description: 'Initialize a fresh database.',
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

    echo json_encode([
        'exit' => $tester->getExitCode(),
        'stdout' => $tester->getStdout(),
        'stderr' => $tester->getStderr(),
    ], JSON_THROW_ON_ERROR);

    exit(0);
}

if ($action !== 'request') {
    fwrite(STDERR, sprintf("Unknown action: %s\n", $action));
    exit(1);
}

if ($argc < 6) {
    fwrite(STDERR, "Usage: php skeleton_kernel_runner.php <repo_root> <project_root> request <method> <uri>\n");
    exit(1);
}

$method = strtoupper((string) $argv[4]);
$uri = (string) $argv[5];

$parts = parse_url($uri);
$path = is_string($parts['path'] ?? null) ? $parts['path'] : '/';
$query = is_string($parts['query'] ?? null) ? $parts['query'] : '';

$_GET = [];
if ($query !== '') {
    parse_str($query, $_GET);
}
$_POST = [];
$_COOKIE = [];
$_FILES = [];
$_REQUEST = $_GET;
$_SERVER = [
    'REQUEST_METHOD' => $method,
    'REQUEST_URI' => $path . ($query !== '' ? '?' . $query : ''),
    'QUERY_STRING' => $query,
    'HTTP_HOST' => 'localhost',
    'SERVER_NAME' => 'localhost',
    'SERVER_PORT' => '80',
    'HTTPS' => 'off',
];

ob_start();
register_shutdown_function(static function (): void {
    $body = (string) ob_get_clean();
    $payload = [
        'status' => http_response_code(),
        'headers' => headers_list(),
        'body' => $body,
    ];

    echo json_encode($payload, JSON_THROW_ON_ERROR);
});

$kernel = new HttpKernel($projectRoot);
$response = $kernel->handle();
$response->send();
