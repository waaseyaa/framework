<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Listener;

use Waaseyaa\Config\Event\ConfigEvent;
use Waaseyaa\Config\Listener\ConfigCacheInvalidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigCacheInvalidator::class)]
final class ConfigCacheInvalidatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_invalidator_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function deletes_cache_file_when_exists(): void
    {
        $cachePath = $this->tempDir . '/config.php';
        file_put_contents($cachePath, '<?php return [];');

        $invalidator = new ConfigCacheInvalidator($cachePath);
        $invalidator(new ConfigEvent('system.site'));

        $this->assertFileDoesNotExist($cachePath);
    }

    #[Test]
    public function does_nothing_when_no_cache_file(): void
    {
        $cachePath = $this->tempDir . '/nonexistent.php';

        $invalidator = new ConfigCacheInvalidator($cachePath);
        // Should not throw
        $invalidator(new ConfigEvent('system.site'));

        $this->assertFileDoesNotExist($cachePath);
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
