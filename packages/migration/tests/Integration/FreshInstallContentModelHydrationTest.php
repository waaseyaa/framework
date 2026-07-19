<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Waaseyaa\Migration\Tests\Fixtures\FreshInstallContentModelProvider;

/** Fresh-install, cross-process regression for #1982. */
#[CoversNothing]
final class FreshInstallContentModelHydrationTest extends TestCase
{
    private string $projectRoot;
    private string $repoRoot;
    private string $vendorRoot;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../../..');
        $processClass = (new \ReflectionClass(Process::class))->getFileName();
        self::assertIsString($processClass);
        $this->vendorRoot = dirname($processClass, 3);
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_1982_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0755, true);
        mkdir($this->projectRoot . '/storage', 0755, true);
        mkdir($this->projectRoot . '/vendor/composer', 0755, true);

        symlink($this->repoRoot . '/packages', $this->projectRoot . '/packages');
        mkdir($this->projectRoot . '/vendor/waaseyaa', 0755, true);
        foreach (glob($this->repoRoot . '/packages/*', GLOB_ONLYDIR) ?: [] as $packageRoot) {
            symlink($packageRoot, $this->projectRoot . '/vendor/waaseyaa/' . basename($packageRoot));
        }
        $this->writeAutoloadWrapper();

        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/fresh-install-1982-test',
            'extra' => ['waaseyaa' => ['providers' => [FreshInstallContentModelProvider::class]]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', sprintf(
            "<?php\nreturn ['environment' => 'local', 'database' => %s, 'entity_schema_validation' => 'off'];\n",
            var_export($this->projectRoot . '/storage/fresh.sqlite', true),
        ));
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
            $item->isLink() || $item->isFile() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function fresh_install_import_is_hydrated_in_a_later_http_process(): void
    {
        $this->runPhase('db-init');
        $entityId = trim($this->runPhase('import'));
        self::assertNotSame('', $entityId);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->projectRoot . '/storage/fresh.sqlite']);
        self::assertSame(
            '<p>Persisted bundle-field content.</p>',
            $connection->fetchOne('SELECT body FROM fresh_install_page__page WHERE id = ?', [$entityId]),
            'Precondition: the fresh import stored the field in the bundle subtable.',
        );

        self::assertSame(
            '<p>Persisted bundle-field content.</p>',
            trim($this->runPhase('http-read')),
            'A fresh HTTP process must restore derived field definitions before repository hydration.',
        );
    }

    #[Test]
    public function boot_rehydration_is_idempotent_for_an_existing_install(): void
    {
        $this->runPhase('db-init');
        $entityId = trim($this->runPhase('import'));
        $before = $this->schemaAndRowSnapshot($entityId);

        self::assertSame('<p>Persisted bundle-field content.</p>', trim($this->runPhase('http-read')));
        self::assertSame($before, $this->schemaAndRowSnapshot($entityId));
    }

    private function runPhase(string $phase): string
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../Fixtures/fresh_install_content_model_runner.php',
            $this->projectRoot,
            $phase,
        ], $this->projectRoot);
        $process->setTimeout(60);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @return array{columns: list<string>, row: array<string, mixed>} */
    private function schemaAndRowSnapshot(string $entityId): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->projectRoot . '/storage/fresh.sqlite']);
        $columns = array_column($connection->fetchAllAssociative('PRAGMA table_info(fresh_install_page__page)'), 'name');
        $row = $connection->fetchAssociative('SELECT * FROM fresh_install_page__page WHERE id = ?', [$entityId]);
        self::assertIsArray($row);

        return ['columns' => $columns, 'row' => $row];
    }

    private function writeAutoloadWrapper(): void
    {
        foreach (['installed.json', 'installed.php', 'autoload_psr4.php', 'autoload_classmap.php', 'autoload_files.php', 'autoload_namespaces.php'] as $file) {
            copy($this->vendorRoot . '/composer/' . $file, $this->projectRoot . '/vendor/composer/' . $file);
        }

        $autoload = <<<'PHP'
<?php
require_once __VENDOR_AUTOLOAD__;

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
PHP;
        $map = ['Waaseyaa\\Migration\\Tests\\' => $this->repoRoot . '/packages/migration/tests/'];
        foreach (glob($this->repoRoot . '/packages/*/composer.json') as $composerFile) {
            $data = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            foreach (($data['autoload']['psr-4'] ?? []) as $prefix => $path) {
                $map[$prefix] = dirname($composerFile) . '/' . rtrim((string) $path, '/');
            }
        }
        uksort($map, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        file_put_contents(
            $this->projectRoot . '/vendor/autoload.php',
            str_replace(
                ['__REPO_MAP__', '__VENDOR_AUTOLOAD__'],
                [var_export($map, true), var_export($this->vendorRoot . '/autoload.php', true)],
                $autoload,
            ),
        );
    }
}
