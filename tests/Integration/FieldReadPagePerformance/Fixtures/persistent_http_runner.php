<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\DbInitHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Tests\Integration\FieldReadPagePerformance\Fixtures\FieldReadPageCorpus;

if ($argc < 6) {
    fwrite(STDERR, "Usage: persistent_http_runner.php <prepare|retarget|measure> <source-root> <fixture-root> <project-root> <block>\n");
    exit(2);
}

$mode = (string) $argv[1];
$sourceRoot = requireDirectory((string) $argv[2]);
$fixtureRoot = requireDirectory((string) $argv[3]);
$projectRoot = (string) $argv[4];
$block = (int) $argv[5];
require_once $fixtureRoot . '/FieldReadPageCorpus.php';

try {
    if ($mode === 'prepare') {
        prepareProject($sourceRoot, $fixtureRoot, $projectRoot);
        emit(['ok' => true, 'database_sha256' => hash_file('sha256', $projectRoot . '/storage/waaseyaa.sqlite')]);
        exit(0);
    }
    if ($mode === 'retarget') {
        installAutoload($sourceRoot, $fixtureRoot, $projectRoot);
        file_put_contents($projectRoot . '/config/waaseyaa.php', buildConfig($projectRoot));
        if (is_file($projectRoot . '/storage/framework/packages.php')) {
            unlink($projectRoot . '/storage/framework/packages.php');
        }
        emit(['ok' => true, 'database_sha256' => hash_file('sha256', $projectRoot . '/storage/waaseyaa.sqlite')]);
        exit(0);
    }
    if ($mode !== 'measure') {
        throw new InvalidArgumentException(sprintf('Unknown worker mode: %s', $mode));
    }

    require $projectRoot . '/vendor/autoload.php';
    emit(measureBlock($sourceRoot, $fixtureRoot, $projectRoot, $block));
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    emit(['ok' => false, 'error' => $e->getMessage()]);
    exit(1);
}

function prepareProject(string $sourceRoot, string $fixtureRoot, string $projectRoot): void
{
    foreach (['config', 'storage', 'templates/layouts', 'vendor/composer'] as $directory) {
        $path = $projectRoot . '/' . $directory;
        if (!is_dir($path) && !mkdir($path, 0o755, true)) {
            throw new RuntimeException(sprintf('Could not create project directory: %s', $path));
        }
    }
    installAutoload($sourceRoot, $fixtureRoot, $projectRoot);

    $provider = 'Waaseyaa\\Tests\\Integration\\FieldReadPagePerformance\\Fixtures\\FieldReadPagePerformanceProvider';
    $userPolicy = 'Waaseyaa\\User\\UserAccessPolicy';
    file_put_contents($projectRoot . '/composer.json', json_encode([
        'name' => 'waaseyaa/field-read-page-performance-fixture',
        'extra' => ['waaseyaa' => ['providers' => [$provider], 'policies' => [$userPolicy]]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($projectRoot . '/config/entity-types.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");
    file_put_contents($projectRoot . '/config/waaseyaa.php', buildConfig($projectRoot));
    foreach (['node.article.full.html.twig', 'members.html.twig', 'layouts/base.html.twig'] as $template) {
        if (!copy($fixtureRoot . '/templates/' . $template, $projectRoot . '/templates/' . $template)) {
            throw new RuntimeException(sprintf('Could not copy fixture template: %s', $template));
        }
    }

    require $projectRoot . '/vendor/autoload.php';
    initializeDatabase($projectRoot);
    seedDatabase($projectRoot);
}

function installAutoload(string $sourceRoot, string $fixtureRoot, string $projectRoot): void
{
    foreach (['installed.json', 'installed.php', 'autoload_files.php', 'autoload_namespaces.php'] as $file) {
        $source = $sourceRoot . '/vendor/composer/' . $file;
        if (is_file($source) && !copy($source, $projectRoot . '/vendor/composer/' . $file)) {
            throw new RuntimeException(sprintf('Could not copy Composer metadata: %s', $file));
        }
    }
    foreach (['autoload_psr4.php', 'autoload_classmap.php'] as $file) {
        $source = $sourceRoot . '/vendor/composer/' . $file;
        if (is_file($source)) {
            file_put_contents(
                $projectRoot . '/vendor/composer/' . $file,
                "<?php\n\ndeclare(strict_types=1);\n\nreturn require " . var_export($source, true) . ";\n",
            );
        }
    }
    $autoload = <<<'PHP'
        <?php

        declare(strict_types=1);

        $loader = require %SOURCE_AUTOLOAD%;
        require_once %CORPUS%;
        require_once %CONTROLLER%;
        require_once %PROVIDER%;

        return $loader;
        PHP;
    $autoload = str_replace(
        ['%SOURCE_AUTOLOAD%', '%CORPUS%', '%CONTROLLER%', '%PROVIDER%'],
        [
            var_export($sourceRoot . '/vendor/autoload.php', true),
            var_export($fixtureRoot . '/FieldReadPageCorpus.php', true),
            var_export($fixtureRoot . '/MembersDirectoryController.php', true),
            var_export($fixtureRoot . '/FieldReadPagePerformanceProvider.php', true),
        ],
        $autoload,
    );
    file_put_contents($projectRoot . '/vendor/autoload.php', $autoload . "\n");
}

function buildConfig(string $projectRoot): string
{
    $database = var_export($projectRoot . '/storage/waaseyaa.sqlite', true);
    $display = FieldReadPageCorpus::contentDisplay();
    $displayExport = var_export($display, true);

    return <<<PHP
        <?php

        declare(strict_types=1);

        return [
            'database' => {$database},
            'environment' => 'local',
            'app' => ['url' => 'http://localhost', 'name' => 'Frozen Field Read Performance'],
            'ssr' => ['theme' => '', 'cache_max_age' => 300],
            'view_modes' => ['node' => ['full' => {$displayExport}]],
        ];
        PHP;
}

function initializeDatabase(string $projectRoot): void
{
    $handler = new DbInitHandler($projectRoot);
    $command = new HandlerCommand(
        name: 'db:init',
        description: 'Initialize page-performance database.',
        options: [
            new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None, description: 'Dry run.'),
            new HandlerOption(name: 'no-sync-schema', mode: HandlerOptionMode::None, description: 'Skip schema sync.'),
        ],
        handler: Closure::fromCallable([$handler, 'execute']),
    );
    $container = new class implements ContainerInterface {
        public function get(string $id): mixed
        {
            throw new RuntimeException('Not found: ' . $id);
        }
        public function has(string $id): bool
        {
            return false;
        }
    };
    $tester = CliTester::for($command, $container);
    $tester->execute([]);
    if ($tester->getExitCode() !== 0) {
        throw new RuntimeException("db:init failed:\n" . $tester->getStderr());
    }
}

function seedDatabase(string $projectRoot): void
{
    $kernel = new HttpKernel($projectRoot);
    $boot = new ReflectionMethod(AbstractKernel::class, 'boot');
    $boot->invoke($kernel);

    $node = $kernel->getEntityTypeManager()->getRepository('node')->create(FieldReadPageCorpus::nodeValues());
    $kernel->getEntityTypeManager()->getRepository('node')->save($node, validate: false);

    $users = $kernel->getEntityTypeManager()->getRepository('user');
    foreach (FieldReadPageCorpus::users() as $values) {
        $users->save($users->create($values), validate: false);
    }
    closeDatabase($kernel);
    unset($kernel, $users, $node);
    gc_collect_cycles();
}

/** @return array<string, mixed> */
function measureBlock(string $sourceRoot, string $fixtureRoot, string $projectRoot, int $block): array
{
    $initialDatabaseHash = hash_file('sha256', $projectRoot . '/storage/waaseyaa.sqlite');
    $kernel = new HttpKernel($projectRoot);
    $boot = new ReflectionMethod(AbstractKernel::class, 'boot');
    $boot->invoke($kernel);
    assertFrozenPolicies($kernel);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_id('field-read-page-performance-' . $block);
        session_start();
    }
    $pdo = new PDO('sqlite:' . $projectRoot . '/storage/waaseyaa.sqlite');
    $pages = ['content_cold', 'members_cold', 'content_hit_diagnostic'];
    $seed = 20_640 + $block;
    mt_srand($seed);
    shuffle($pages);
    $results = [];
    foreach ($pages as $page) {
        for ($i = 0; $i < 30; ++$i) {
            requestPage($kernel, $pdo, $page, false);
        }
        $samples = [];
        $last = null;
        for ($i = 0; $i < 200; ++$i) {
            [$elapsed, $response] = requestPage($kernel, $pdo, $page, true);
            $samples[] = $elapsed;
            $last = $response;
        }
        if (!is_array($last)) {
            throw new RuntimeException('Page produced no response.');
        }
        $results[$page] = buildPageResult($page, $samples, $last, $initialDatabaseHash);
    }
    closeDatabase($kernel);

    return [
        'ok' => true,
        'block' => $block,
        'seed' => $seed,
        'page_order' => $pages,
        'pages' => $results,
        'environment' => environment($sourceRoot, $fixtureRoot),
    ];
}

/** @return array{int, array{status:int,body:string,cache_before:int,cache_after:int}} */
function requestPage(HttpKernel $kernel, PDO $pdo, string $page, bool $timed): array
{
    $content = str_starts_with($page, 'content_');
    $cold = $page === 'content_cold';
    if ($cold) {
        $pdo->exec('DELETE FROM cache_render');
    }
    if ($content) {
        $_SESSION = [];
        $uri = '/node/1';
    } else {
        $_SESSION = ['waaseyaa_uid' => 1];
        $uri = '/members';
    }
    setGlobals($uri);
    $cacheBefore = cacheRows($pdo);
    $started = hrtime(true);
    $response = $kernel->handle();
    $elapsed = hrtime(true) - $started;
    $body = (string) $response->getContent();
    $payload = [
        'status' => $response->getStatusCode(),
        'body' => $body,
        'cache_before' => $cacheBefore,
        'cache_after' => cacheRows($pdo),
    ];
    if (!$timed) {
        assert($elapsed > 0);
    }

    return [$elapsed, $payload];
}

/** @param list<int> $samples @param array{status:int,body:string,cache_before:int,cache_after:int} $last @return array<string,mixed> */
function buildPageResult(string $page, array $samples, array $last, string $initialDatabaseHash): array
{
    $body = $last['body'];
    if ($last['status'] !== 200) {
        throw new RuntimeException(sprintf('%s returned HTTP %d: %s', $page, $last['status'], substr($body, 0, 500)));
    }
    if ($page === 'members_cold') {
        preg_match_all('/data-member-id="(\d+)"/', $body, $matches);
        $memberIds = array_map('intval', $matches[1] ?? []);
        $expectedIds = range(2, FieldReadPageCorpus::MEMBER_COUNT + 1);
        $rows = count($memberIds);
        $namesAppearOnce = true;
        for ($member = 1; $member <= FieldReadPageCorpus::MEMBER_COUNT; ++$member) {
            if (substr_count($body, sprintf('member-%03d-frozen-display-name', $member)) !== 1) {
                $namesAppearOnce = false;
                break;
            }
        }
        if ($rows !== FieldReadPageCorpus::MEMBER_COUNT
            || $memberIds !== $expectedIds
            || !$namesAppearOnce) {
            throw new RuntimeException(sprintf(
                'Members page repository/Twig workload drifted (rows=%d, marker_count=%d, first=%s, last=%s, names_once=%s, bytes=%d, prefix=%s).',
                $rows,
                substr_count($body, 'data-member-id='),
                $memberIds === [] ? 'none' : (string) $memberIds[0],
                $memberIds === [] ? 'none' : (string) $memberIds[array_key_last($memberIds)],
                $namesAppearOnce ? 'yes' : 'no',
                strlen($body),
                json_encode(substr($body, 0, 240), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ));
        }
        // The frozen workload hydrates the authenticated session User once,
        // plus the repository entities rendered by the directory.
        $trace = [
            'route' => 'performance.members',
            'controller' => 'Waaseyaa\\Tests\\Integration\\FieldReadPagePerformance\\Fixtures\\MembersDirectoryController::index',
            'rendered_rows' => $rows,
            'rendered_fields' => $rows * 2,
            'hydrated_entity_count' => $rows + 1,
            'ordered_member_ids_sha256' => hash('sha256', implode(',', $memberIds)),
            'cache_before' => $last['cache_before'],
            'cache_after' => $last['cache_after'],
        ];
    } else {
        $sentinels = 0;
        foreach (FieldReadPageCorpus::contentFieldNames() as $index => $_field) {
            $count = substr_count($body, sprintf('SECTION-%02d:', $index + 1));
            if ($count !== 1) {
                throw new RuntimeException('Content page repository/Twig workload drifted.');
            }
            $sentinels += $count;
        }
        if ($sentinels !== FieldReadPageCorpus::DYNAMIC_CONTENT_FIELDS) {
            throw new RuntimeException('Content page repository/Twig workload drifted.');
        }
        if ($page === 'content_cold' && !($last['cache_before'] === 0 && $last['cache_after'] === 1)) {
            throw new RuntimeException('Cache-cold page did not perform the frozen cache miss/write transition.');
        }
        if ($page === 'content_hit_diagnostic' && !($last['cache_before'] === 1 && $last['cache_after'] === 1)) {
            throw new RuntimeException('Cache-hit diagnostic did not perform the frozen cache-hit transition.');
        }
        $trace = [
            'route' => 'public.page',
            'controller' => 'render.page',
            'handler' => 'Waaseyaa\\SSR\\SsrPageHandler',
            'rendered_rows' => 1,
            'rendered_fields' => FieldReadPageCorpus::CONTENT_RENDERED_FIELDS,
            'hydrated_entity_count' => 1,
            'unique_sentinels' => $sentinels,
            'cache_before' => $last['cache_before'],
            'cache_after' => $last['cache_after'],
        ];
    }
    $workload = [
        'page' => $page,
        'warmups' => 30,
        'samples' => 200,
        'database_sha256' => $initialDatabaseHash,
        'trace' => $trace,
    ];

    return [
        'page' => $page,
        'samples_ns' => $samples,
        'response' => ['sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'status' => $last['status']],
        'trace' => $trace,
        'workload_sha256' => hash('sha256', stableJson($workload)),
    ];
}

function setGlobals(string $uri): void
{
    $_GET = [];
    $_POST = [];
    $_COOKIE = [];
    $_FILES = [];
    $_REQUEST = [];
    $_SERVER = [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => $uri,
        'QUERY_STRING' => '',
        'HTTP_HOST' => 'localhost',
        'HTTP_ACCEPT' => 'text/html',
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '80',
        'HTTPS' => 'off',
    ];
}

function cacheRows(PDO $pdo): int
{
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='cache_render'")->fetchColumn();
    return $exists === 1 ? (int) $pdo->query('SELECT COUNT(*) FROM cache_render')->fetchColumn() : 0;
}

/** @return array<string,string> */
function environment(string $sourceRoot, string $fixtureRoot): array
{
    $iniFiles = array_filter(array_merge([php_ini_loaded_file()], explode(',', (string) php_ini_scanned_files())), 'is_string');
    $ini = '';
    foreach ($iniFiles as $file) {
        $file = trim((string) $file);
        if ($file !== '' && is_file($file)) {
            $ini .= hash_file('sha256', $file);
        }
    }
    $extensions = get_loaded_extensions();
    sort($extensions);

    return [
        'php' => PHP_VERSION,
        'php_binary_sha256' => hash_file('sha256', PHP_BINARY),
        'ini_sha256' => hash('sha256', $ini),
        'extensions_sha256' => hash('sha256', implode("\n", $extensions)),
        'fixture_sha256' => fixtureHash($fixtureRoot),
        'framework_sha256' => frameworkHash($sourceRoot),
        'vendor_sha256' => is_file($sourceRoot . '/vendor/composer/installed.json')
            ? hash_file('sha256', $sourceRoot . '/vendor/composer/installed.json')
            : 'missing',
    ];
}

function fixtureHash(string $fixtureRoot): string
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relative = substr($file->getPathname(), strlen($fixtureRoot) + 1);
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files);

    return hash('sha256', stableJson($files));
}

function frameworkHash(string $sourceRoot): string
{
    $files = [];
    foreach (['packages', 'src'] as $directory) {
        if (!is_dir($sourceRoot . '/' . $directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot . '/' . $directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($sourceRoot) + 1);
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    foreach (['composer.json', 'composer.lock'] as $relative) {
        if (is_file($sourceRoot . '/' . $relative)) {
            $files[$relative] = hash_file('sha256', $sourceRoot . '/' . $relative);
        }
    }
    ksort($files);

    return hash('sha256', stableJson($files));
}

function stableJson(array $value): string
{
    ksort($value);
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function closeDatabase(HttpKernel $kernel): void
{
    $database = $kernel->getDatabase();
    if ($database instanceof DBALDatabase) {
        $database->getConnection()->close();
    }
}

function assertFrozenPolicies(HttpKernel $kernel): void
{
    $expected = 'Waaseyaa\\User\\UserAccessPolicy';
    $policiesProperty = new ReflectionProperty($kernel->getAccessHandler(), 'policies');
    $policies = $policiesProperty->getValue($kernel->getAccessHandler());
    foreach ($policies as $policy) {
        if ($policy instanceof $expected) {
            return;
        }
    }

    throw new RuntimeException(sprintf('Frozen access policy missing from runtime handler: %s', $expected));
}

function requireDirectory(string $path): string
{
    $real = realpath($path);
    if ($real === false || !is_dir($real)) {
        throw new InvalidArgumentException(sprintf('Directory does not exist: %s', $path));
    }
    return $real;
}

function emit(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
