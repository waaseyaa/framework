<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Cache;

use Waaseyaa\Config\Cache\CachedConfigFactory;
use Waaseyaa\Config\Config;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(CachedConfigFactory::class)]
final class CachedConfigFactoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_cached_factory_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function get_returns_cached_config_when_cache_exists(): void
    {
        $cachePath = $this->tempDir . '/config.php';
        $cached = ['system.site' => ['name' => 'Cached Site']];
        file_put_contents($cachePath, '<?php return ' . var_export($cached, true) . ';' . "\n");

        $inner = $this->createMock(ConfigFactoryInterface::class);
        $inner->expects($this->never())->method('get');

        $factory = new CachedConfigFactory($inner, $cachePath);
        $config = $factory->get('system.site');

        $this->assertSame('system.site', $config->getName());
        $this->assertSame(['name' => 'Cached Site'], $config->get());
    }

    #[Test]
    public function get_falls_through_to_inner_when_not_cached(): void
    {
        $cachePath = $this->tempDir . '/nonexistent.php';

        $storage = new MemoryStorage();
        $storage->write('user.settings', ['register' => 'admin']);
        $inner = new ConfigFactory($storage, new EventDispatcher());

        $factory = new CachedConfigFactory($inner, $cachePath);
        $config = $factory->get('user.settings');

        $this->assertSame('user.settings', $config->getName());
        $this->assertSame('admin', $config->get('register'));
    }

    #[Test]
    public function get_falls_through_for_uncached_config_name(): void
    {
        $cachePath = $this->tempDir . '/config.php';
        $cached = ['system.site' => ['name' => 'Cached']];
        file_put_contents($cachePath, '<?php return ' . var_export($cached, true) . ';' . "\n");

        $storage = new MemoryStorage();
        $storage->write('other.config', ['key' => 'value']);
        $inner = new ConfigFactory($storage, new EventDispatcher());

        $factory = new CachedConfigFactory($inner, $cachePath);
        $config = $factory->get('other.config');

        $this->assertSame('value', $config->get('key'));
    }

    #[Test]
    public function get_editable_always_goes_through_inner(): void
    {
        $cachePath = $this->tempDir . '/config.php';
        $cached = ['system.site' => ['name' => 'Cached']];
        file_put_contents($cachePath, '<?php return ' . var_export($cached, true) . ';' . "\n");

        $storage = new MemoryStorage();
        $storage->write('system.site', ['name' => 'Real']);
        $inner = new ConfigFactory($storage, new EventDispatcher());

        $factory = new CachedConfigFactory($inner, $cachePath);
        $config = $factory->getEditable('system.site');

        $this->assertFalse($config->isImmutable());
    }

    #[Test]
    public function load_multiple_uses_cache(): void
    {
        $cachePath = $this->tempDir . '/config.php';
        $cached = [
            'a' => ['val' => 1],
            'b' => ['val' => 2],
        ];
        file_put_contents($cachePath, '<?php return ' . var_export($cached, true) . ';' . "\n");

        $inner = $this->createMock(ConfigFactoryInterface::class);
        $inner->expects($this->never())->method('get');

        $factory = new CachedConfigFactory($inner, $cachePath);
        $configs = $factory->loadMultiple(['a', 'b']);

        $this->assertCount(2, $configs);
        $this->assertSame(1, $configs['a']->get('val'));
        $this->assertSame(2, $configs['b']->get('val'));
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
