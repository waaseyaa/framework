<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;

#[CoversClass(ConsoleKernel::class)]
final class ConsoleKernelTest extends TestCase
{
    private string $projectRoot;

    /** @var list<string> */
    private array $originalArgv;

    protected function setUp(): void
    {
        $this->originalArgv = $_SERVER['argv'] ?? [];

        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_console_test_' . uniqid();
        mkdir($this->projectRoot . '/config', 0755, true);
        mkdir($this->projectRoot . '/storage', 0755, true);

        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:'];",
        );
        file_put_contents(
            $this->projectRoot . '/config/entity-types.php',
            "<?php\nreturn [\n    new \\Waaseyaa\\Entity\\EntityType(\n        id: 'test',\n        label: 'Test',\n        class: \\stdClass::class,\n        keys: ['id' => 'id'],\n    ),\n];",
        );
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->originalArgv;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function handle_returns_zero_for_list_command(): void
    {
        $_SERVER['argv'] = ['waaseyaa', 'list', '--no-ansi'];

        $kernel = new ConsoleKernel($this->projectRoot);
        $exitCode = $kernel->handle();

        $this->assertSame(0, $exitCode);
    }

    #[Test]
    public function handle_returns_one_when_boot_fails(): void
    {
        // No config, no vendor dir, and an unwritable SQLite path will cause
        // DBALDatabase::createSqlite() to throw when given a non-existent directory path.
        $badRoot = '/nonexistent/path/that/cannot/be/created';
        $kernel = new ConsoleKernel($badRoot);

        ob_start();
        $exitCode = $kernel->handle();
        ob_get_clean();

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function handle_returns_zero_for_about_command(): void
    {
        $_SERVER['argv'] = ['waaseyaa', 'about', '--no-ansi'];

        $kernel = new ConsoleKernel($this->projectRoot);
        $exitCode = $kernel->handle();

        $this->assertSame(0, $exitCode);
    }

    #[Test]
    public function handle_returns_non_zero_for_unknown_command(): void
    {
        $_SERVER['argv'] = ['waaseyaa', 'not-a-real-command', '--no-ansi'];

        $kernel = new ConsoleKernel($this->projectRoot);
        $exitCode = $kernel->handle();

        $this->assertNotSame(0, $exitCode);
    }

    #[Test]
    public function handle_uses_configured_sync_directory(): void
    {
        $customSyncDir = $this->projectRoot . '/custom-sync';
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'config_dir' => '" . addslashes($customSyncDir) . "'];",
        );

        $_SERVER['argv'] = ['waaseyaa', 'list', '--no-ansi'];

        $kernel = new ConsoleKernel($this->projectRoot);
        $exitCode = $kernel->handle();

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryExists($customSyncDir);
        $this->assertDirectoryExists($this->projectRoot . '/config/active');
    }

    #[Test]
    public function handle_allows_optimize_manifest_when_cached_manifest_is_stale(): void
    {
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
        $this->writeStaleManifestCache();

        $_SERVER['argv'] = ['waaseyaa', 'optimize:manifest', '--no-ansi'];

        $kernel = new ConsoleKernel($this->projectRoot);
        $exitCode = $kernel->handle();

        $this->assertSame(0, $exitCode);
    }

    #[Test]
    public function handle_fails_fast_for_non_recovery_commands_when_cached_manifest_is_stale(): void
    {
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
        $this->writeStaleManifestCache();

        $_SERVER['argv'] = ['waaseyaa', 'route:list', '--no-ansi'];

        $kernel = new ConsoleKernel($this->projectRoot);
        $exitCode = $kernel->handle();

        $this->assertSame(1, $exitCode);
    }

    private function writeStaleManifestCache(): void
    {
        $data = [
            'providers' => ['App\\Provider\\MissingProvider'],
            'commands' => [],
            'routes' => [],
            'migrations' => [],
            'field_types' => [],
            'listeners' => [],
            'middleware' => [],
            'permissions' => [],
            'policies' => [],
        ];

        file_put_contents(
            $this->projectRoot . '/storage/framework/packages.php',
            '<?php return ' . var_export($data, true) . ';' . "\n",
        );
    }
}
