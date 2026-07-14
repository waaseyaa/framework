<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FreshInstall;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_cutover_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        mkdir($this->projectRoot . '/templates', 0o755, true);
        symlink($this->repoRoot . '/packages', $this->projectRoot . '/packages');

        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);
        symlink($this->repoRoot . '/packages', $this->projectRoot . '/vendor/waaseyaa');
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
        if (!is_dir($this->projectRoot)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isFile() || $item->isLink() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }
        rmdir($this->projectRoot);
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
                'environment' => 'local',
                'app' => ['url' => 'http://localhost', 'name' => 'Fresh-install cutover smoke'],
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
