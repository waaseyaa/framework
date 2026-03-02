<?php
declare(strict_types=1);
namespace Waaseyaa\CLI\Tests\Unit\Command\Optimize;

use Waaseyaa\CLI\Command\Optimize\OptimizeManifestCommand;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(OptimizeManifestCommand::class)]
final class OptimizeManifestCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_cmd_test_' . uniqid();
        mkdir($this->tempDir . '/vendor/composer', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function compiles_manifest_and_reports_counts(): void
    {
        $installed = [
            'packages' => [
                [
                    'name' => 'waaseyaa/node',
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => ['App\\Provider'],
                            'commands' => ['App\\Cmd', 'App\\Cmd2'],
                        ],
                    ],
                ],
            ],
        ];

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode($installed, JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);

        $command = new OptimizeManifestCommand($compiler);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('1 providers', $output);
        $this->assertStringContainsString('2 commands', $output);
        $this->assertStringContainsString('0 field types', $output);
        $this->assertStringContainsString('0 middleware stacks', $output);

        // Verify cache file was written
        $this->assertFileExists($storagePath . '/framework/packages.php');
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
