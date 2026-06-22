<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\OptimizeManifestHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;

#[CoversClass(OptimizeManifestHandler::class)]
final class OptimizeManifestHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_manifest_handler_' . uniqid();
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

        $tester = $this->createTester($compiler);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('1 providers', $tester->getStdout());
        $this->assertStringContainsString('0 attribute entity types', $tester->getStdout());
        $this->assertStringContainsString('0 field types', $tester->getStdout());
        $this->assertStringContainsString('0 middleware stacks', $tester->getStdout());
        $this->assertFileExists($storagePath . '/framework/packages.php');
    }

    private function createTester(PackageManifestCompiler $compiler): CliTester
    {
        $handler = new OptimizeManifestHandler($compiler);
        $definition = new HandlerCommand(
            name: 'optimize:manifest',
            description: 'Compile the package discovery manifest',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
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
