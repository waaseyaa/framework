<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FreshInstall;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\Tests\Integration\FreshInstall\Fixtures\CutoverContentModelProvider;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

/** Blocking production-cutover smoke for anchor issue #1985. */
#[CoversNothing]
#[Group('fresh-install-cutover')]
final class CutoverFreshInstallSmokeTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;
    private ?Process $server = null;
    private int $serverPort = 0;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_cutover_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/public', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        mkdir($this->projectRoot . '/templates', 0o755, true);
        symlink($this->repoRoot . '/packages', $this->projectRoot . '/packages');

        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);
        $this->writeAutoloadWrapper();

        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/fresh-install-cutover-smoke',
            'autoload' => ['psr-4' => ['Waaseyaa\\Tests\\' => $this->repoRoot . '/tests/']],
            'extra' => ['waaseyaa' => ['providers' => [CutoverContentModelProvider::class]]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->configFile());
        file_put_contents(
            $this->projectRoot . '/templates/node.full.html.twig',
            <<<'TWIG'
                <article>
                  <h1>{{ fields.title.formatted|raw }}</h1>
                  <div class="body">{{ fields.body.formatted|raw }}</div>
                  <div class="relationships">{{ relationship_navigation.entity.counts.total|default(0) }}</div>
                </article>
                TWIG,
        );
    }

    protected function tearDown(): void
    {
        $this->server?->stop(1);

        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function clean_install_schema_import_http_hydration_and_ssr_traversal_stay_compatible(): void
    {
        self::assertFileDoesNotExist($this->projectRoot . '/storage/waaseyaa.sqlite');
        $install = $this->runPhase('db-init');
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput() . $install->getOutput());
        self::assertStringContainsString('Database ready', $install->getOutput());

        $import = $this->runPhase('import');
        self::assertSame(0, $import->getExitCode(), $import->getErrorOutput() . $import->getOutput());
        $ids = json_decode(trim($import->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($ids);

        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
        self::assertSame(
            '<p>Fresh-install bundle content.</p>',
            $connection->fetchOne('SELECT body FROM node__cutover_page WHERE nid = ?', [$ids['source_id']]),
            'Precondition: the import persisted the derived bundle field in its typed subtable.',
        );
        $connection->close();

        $schema = $this->runPhase('schema-check');
        $http = $this->runPhase('http', (string) $ids['source_id']);
        $httpPayload = json_decode(trim($http->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($httpPayload);

        $actual = [
            'schema_exit' => $schema->getExitCode(),
            'http_exit' => $http->getExitCode(),
            'http_status' => (int) ($httpPayload['status'] ?? 0),
            'bundle_content_rendered' => str_contains((string) ($httpPayload['body'] ?? ''), '<p>Fresh-install bundle content.</p>'),
            'relationship_count_rendered' => str_contains((string) ($httpPayload['body'] ?? ''), '<div class="relationships">1</div>'),
        ];

        self::assertSame(
            [
                'schema_exit' => 0,
                'http_exit' => 0,
                'http_status' => 200,
                'bundle_content_rendered' => true,
                'relationship_count_rendered' => true,
            ],
            $actual,
            "Fresh-install cutover smoke failed.\nInstall output:\n"
                . $install->getOutput() . $install->getErrorOutput()
                . "\nSchema output:\n"
                . $schema->getOutput() . $schema->getErrorOutput()
                . "\nHTTP output:\n" . $http->getOutput() . $http->getErrorOutput(),
        );
    }

    #[Test]
    public function fresh_install_admin_authoring_creates_and_edits_page_and_bundled_content(): void
    {
        $install = $this->runPhase('db-init');
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput() . $install->getOutput());
        $import = $this->runPhase('import');
        self::assertSame(0, $import->getExitCode(), $import->getErrorOutput() . $import->getOutput());

        $this->startServer();

        $createdIds = [];
        $updatedTokens = [];
        foreach ([
            'page' => ['body' => '<p>Page body.</p>'],
            'post' => ['body' => '<p>News body.</p>'],
            'tribe_events' => [
                'body' => '<p>Event body.</p>',
                'event_start' => '2026-08-01T10:00:00',
                'event_end' => '2026-08-01T12:00:00',
            ],
        ] as $bundle => $bundleFields) {
            $create = $this->adminAction('create', [
                'attributes' => [
                    'title' => "Synthetic {$bundle}",
                    'slug' => "synthetic-{$bundle}",
                    'type' => $bundle,
                    ...$bundleFields,
                ],
            ]);

            self::assertSame(200, $create['status'], "{$bundle} create returned HTTP {$create['status']}: {$create['body']}");
            self::assertTrue($create['json']['ok'] ?? false, "{$bundle} create failed: {$create['body']}");

            $id = (string) ($create['json']['data']['id'] ?? '');
            self::assertNotSame('', $id, "{$bundle} create returned no id: {$create['body']}");
            $createdIds[$bundle] = $id;

            $update = $this->adminAction('update', [
                'id' => $id,
                'mutation_token' => $create['json']['data']['mutation_token'] ?? null,
                'attributes' => ['title' => "Edited {$bundle}"],
            ]);
            self::assertSame(200, $update['status'], "{$bundle} update returned HTTP {$update['status']}: {$update['body']}");
            self::assertTrue($update['json']['ok'] ?? false, "{$bundle} update failed: {$update['body']}");
            self::assertSame("Edited {$bundle}", $update['json']['data']['attributes']['title'] ?? null);
            $updatedTokens[$bundle] = $update['json']['data']['mutation_token'] ?? null;
        }

        $invalidCreate = $this->adminAction('create', [
            'attributes' => [
                'title' => 12345,
                'slug' => 'invalid-page',
                'type' => 'page',
                'body' => '<p>Must not persist.</p>',
            ],
        ]);
        self::assertNotSame(500, $invalidCreate['status'], $invalidCreate['body']);
        self::assertFalse($invalidCreate['json']['ok'] ?? true, $invalidCreate['body']);
        self::assertSame(422, $invalidCreate['json']['error']['status'] ?? null, $invalidCreate['body']);

        $invalidUpdate = $this->adminAction('update', [
            'id' => $createdIds['page'],
            'mutation_token' => $updatedTokens['page'] ?? null,
            'attributes' => ['title' => 12345],
        ]);
        self::assertNotSame(500, $invalidUpdate['status'], $invalidUpdate['body']);
        self::assertFalse($invalidUpdate['json']['ok'] ?? true, $invalidUpdate['body']);
        self::assertSame(422, $invalidUpdate['json']['error']['status'] ?? null, $invalidUpdate['body']);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->projectRoot . '/storage/waaseyaa.sqlite',
        ]);
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM node WHERE json_extract(_data, '$.slug') = 'invalid-page'"));
        self::assertSame('Edited page', $connection->fetchOne("SELECT title FROM node WHERE json_extract(_data, '$.slug') = 'synthetic-page'"));
        $connection->close();
    }

    private function runPhase(string $phase, string $value = ''): Process
    {
        $command = [
            PHP_BINARY,
            __DIR__ . '/Fixtures/cutover_smoke_runner.php',
            $this->projectRoot,
            $phase,
        ];
        if ($value !== '') {
            $command[] = $value;
        }
        $process = new Process($command, $this->projectRoot);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    private function configFile(): string
    {
        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';

        return <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'database' => '{$databasePath}',
                'environment' => 'testing',
                'app' => ['url' => 'http://localhost', 'name' => 'Fresh-install cutover smoke'],
                'auth' => ['dev_fallback_account' => true],
                'ssr' => ['theme' => '', 'cache_max_age' => 0],
                'view_modes' => [
                    'node' => [
                        'full' => [
                            'title' => ['formatter' => 'string', 'weight' => 0],
                            'body' => ['formatter' => 'text_long', 'weight' => 1],
                        ],
                    ],
                ],
            ];
            PHP;
    }

    private function startServer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, "Unable to reserve HTTP port: {$errorCode} {$errorMessage}");
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($address);
        $this->serverPort = (int) substr(strrchr($address, ':'), 1);

        $this->server = new Process([
            PHP_BINARY,
            '-S',
            "127.0.0.1:{$this->serverPort}",
            __DIR__ . '/Fixtures/authoring_http_router.php',
        ], $this->projectRoot, [
            'APP_ENV' => 'testing',
            'WAASEYAA_TEST_PROJECT_ROOT' => $this->projectRoot,
        ]);
        $this->server->setTimeout(null);
        $this->server->start();

        $deadline = microtime(true) + 10;
        do {
            $connection = @fsockopen('127.0.0.1', $this->serverPort, $errorCode, $errorMessage, 0.1);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline && $this->server->isRunning());

        self::fail('Fresh-install HTTP server did not start: ' . $this->server->getErrorOutput());
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string, json: array<string, mixed>}
     */
    private function adminAction(string $action, array $payload): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 20,
        ]]);
        $body = file_get_contents(
            "http://127.0.0.1:{$this->serverPort}/admin/_surface/node/action/{$action}",
            false,
            $context,
        );
        self::assertIsString($body);
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
        $statusLine = $headers[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $json = json_decode($body, true);

        return [
            'status' => isset($matches[1]) ? (int) $matches[1] : 0,
            'body' => $body,
            'json' => is_array($json) ? $json : [],
        ];
    }

    private function writeAutoloadWrapper(): void
    {
        $map = ['Waaseyaa\\Tests\\' => $this->repoRoot . '/tests/'];
        foreach (glob($this->repoRoot . '/packages/*/composer.json') ?: [] as $composerFile) {
            $package = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            foreach (($package['autoload']['psr-4'] ?? []) as $prefix => $path) {
                $map[$prefix] = dirname($composerFile) . '/' . rtrim((string) $path, '/');
            }
        }
        uksort($map, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $autoload = <<<'PHP'
            <?php
            $loader = require __VENDOR_AUTOLOAD__;
            $map = __REPO_MAP__;
            spl_autoload_register(static function (string $class) use ($map): void {
                foreach ($map as $prefix => $baseDir) {
                    if (!str_starts_with($class, $prefix)) {
                        continue;
                    }
                    $candidate = $baseDir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($candidate)) {
                        require_once $candidate;
                    }
                    return;
                }
            }, true, true);
            return $loader;
            PHP;

        file_put_contents(
            $this->projectRoot . '/vendor/autoload.php',
            str_replace(
                ['__REPO_MAP__', '__VENDOR_AUTOLOAD__'],
                [var_export($map, true), var_export((string) realpath($this->repoRoot . '/vendor/autoload.php'), true)],
                $autoload,
            ),
        );
    }
}
