<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;

#[CoversClass(AbstractKernel::class)]
final class DebugModeTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_debug_test_' . uniqid();
        mkdir($this->projectRoot . '/config', 0755, true);
        mkdir($this->projectRoot . '/storage', 0755, true);

        putenv('APP_DEBUG');
        putenv('APP_ENV');
        putenv('LOG_LEVEL');
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
        putenv('APP_ENV');
        putenv('LOG_LEVEL');

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    private function writeConfig(array $overrides = []): void
    {
        $config = array_merge(['database' => ':memory:'], $overrides);
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            '<?php return ' . var_export($config, true) . ';',
        );
        file_put_contents(
            $this->projectRoot . '/config/entity-types.php',
            "<?php\nreturn [\n    new \\Waaseyaa\\Entity\\EntityType(\n        id: 'test',\n        label: 'Test',\n        class: \\stdClass::class,\n        keys: ['id' => 'id'],\n    ),\n];",
        );
    }

    #[Test]
    public function debug_mode_defaults_to_false(): void
    {
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function debugMode(): bool { return $this->isDebugMode(); }
        };
        $this->assertFalse($kernel->debugMode());
    }

    #[Test]
    public function debug_mode_reads_env_var(): void
    {
        putenv('APP_DEBUG=true');
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function debugMode(): bool { return $this->isDebugMode(); }
        };
        $this->assertTrue($kernel->debugMode());
    }

    #[Test]
    public function debug_mode_reads_config_key(): void
    {
        $this->writeConfig(['debug' => true]);
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void { $this->boot(); }
            public function debugMode(): bool { return $this->isDebugMode(); }
        };
        $kernel->publicBoot();
        $this->assertTrue($kernel->debugMode());
    }

    #[Test]
    public function debug_mode_env_var_trumps_config(): void
    {
        putenv('APP_DEBUG=false');
        $this->writeConfig(['debug' => true]);
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void { $this->boot(); }
            public function debugMode(): bool { return $this->isDebugMode(); }
        };
        $kernel->publicBoot();
        $this->assertFalse($kernel->debugMode());
    }

    #[Test]
    public function development_mode_detects_local_env(): void
    {
        putenv('APP_ENV=local');
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function devMode(): bool { return $this->isDevelopmentMode(); }
        };
        $this->assertTrue($kernel->devMode());
    }

    #[Test]
    public function development_mode_detects_dev_env(): void
    {
        putenv('APP_ENV=dev');
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function devMode(): bool { return $this->isDevelopmentMode(); }
        };
        $this->assertTrue($kernel->devMode());
    }

    #[Test]
    public function development_mode_defaults_to_false(): void
    {
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function devMode(): bool { return $this->isDevelopmentMode(); }
        };
        $this->assertFalse($kernel->devMode());
    }

    #[Test]
    public function boot_guard_refuses_debug_in_production(): void
    {
        putenv('APP_ENV=production');
        putenv('APP_DEBUG=true');
        $this->writeConfig();
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void { $this->boot(); }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG must not be enabled in production');
        $kernel->publicBoot();
    }

    #[Test]
    public function boot_allows_debug_in_local_env(): void
    {
        putenv('APP_ENV=local');
        putenv('APP_DEBUG=true');
        $this->writeConfig();
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void { $this->boot(); }
            public function debugMode(): bool { return $this->isDebugMode(); }
        };
        $kernel->publicBoot();
        $this->assertTrue($kernel->debugMode());
    }
}
