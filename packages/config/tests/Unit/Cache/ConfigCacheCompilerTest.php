<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Cache;

use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Waaseyaa\Config\Storage\MemoryStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigCacheCompiler::class)]
final class ConfigCacheCompilerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function compile_reads_all_config_from_storage(): void
    {
        $storage = new MemoryStorage();
        $storage->write('system.site', ['name' => 'My Site']);
        $storage->write('user.settings', ['register' => 'admin']);

        $compiler = new ConfigCacheCompiler($storage, $this->tempDir . '/config.php');
        $data = $compiler->compile();

        $this->assertSame(['name' => 'My Site'], $data['system.site']);
        $this->assertSame(['register' => 'admin'], $data['user.settings']);
    }

    #[Test]
    public function compile_and_cache_writes_php_file(): void
    {
        $storage = new MemoryStorage();
        $storage->write('system.site', ['name' => 'Test']);

        $cachePath = $this->tempDir . '/framework/config.php';
        $compiler = new ConfigCacheCompiler($storage, $cachePath);
        $compiler->compileAndCache();

        $this->assertFileExists($cachePath);

        $loaded = require $cachePath;
        $this->assertSame(['name' => 'Test'], $loaded['system.site']);
    }

    #[Test]
    public function is_cached_returns_false_when_no_cache(): void
    {
        $storage = new MemoryStorage();
        $compiler = new ConfigCacheCompiler($storage, $this->tempDir . '/nope.php');
        $this->assertFalse($compiler->isCached());
    }

    #[Test]
    public function is_cached_returns_true_after_compilation(): void
    {
        $storage = new MemoryStorage();
        $cachePath = $this->tempDir . '/config.php';
        $compiler = new ConfigCacheCompiler($storage, $cachePath);
        $compiler->compileAndCache();

        $this->assertTrue($compiler->isCached());
    }

    #[Test]
    public function clear_deletes_cache_file(): void
    {
        $storage = new MemoryStorage();
        $cachePath = $this->tempDir . '/config.php';
        $compiler = new ConfigCacheCompiler($storage, $cachePath);
        $compiler->compileAndCache();

        $this->assertTrue($compiler->clear());
        $this->assertFalse($compiler->isCached());
    }

    #[Test]
    public function clear_returns_false_when_no_cache(): void
    {
        $storage = new MemoryStorage();
        $compiler = new ConfigCacheCompiler($storage, $this->tempDir . '/nope.php');
        $this->assertFalse($compiler->clear());
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
