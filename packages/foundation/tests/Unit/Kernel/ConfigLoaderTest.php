<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\ConfigLoader;
use Waaseyaa\Foundation\Log\NullLogger;

#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function returns_empty_array_for_missing_file(): void
    {
        $result = ConfigLoader::load($this->tempDir . '/nonexistent.php');

        $this->assertSame([], $result);
    }

    #[Test]
    public function loads_array_from_php_file(): void
    {
        $path = $this->tempDir . '/config.php';
        file_put_contents($path, '<?php return ["database" => "/tmp/test.sqlite"];');

        $result = ConfigLoader::load($path);

        $this->assertSame(['database' => '/tmp/test.sqlite'], $result);
    }

    #[Test]
    public function returns_empty_array_for_non_array_return(): void
    {
        ConfigLoader::setLogger(new NullLogger());
        $path = $this->tempDir . '/config.php';
        file_put_contents($path, '<?php return "not an array";');

        $result = ConfigLoader::load($path);

        $this->assertSame([], $result);
    }
}
