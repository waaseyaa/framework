<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\OptimizeClearHandler;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(OptimizeClearHandler::class)]
final class OptimizeClearHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_clear_handler_test_' . uniqid();
        mkdir($this->tempDir . '/framework', 0o755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    #[Test]
    public function clears_cached_php_files(): void
    {
        file_put_contents($this->tempDir . '/framework/packages.php', '<?php return [];');
        file_put_contents($this->tempDir . '/framework/config.php', '<?php return [];');

        $tester = $this->createTester($this->tempDir);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('2 cached artifact(s) cleared', $tester->getStdout());
        $this->assertFileDoesNotExist($this->tempDir . '/framework/packages.php');
        $this->assertFileDoesNotExist($this->tempDir . '/framework/config.php');
    }

    #[Test]
    public function reports_no_artifacts_when_empty(): void
    {
        $tester = $this->createTester($this->tempDir);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('No cached artifacts found', $tester->getStdout());
    }

    #[Test]
    public function reports_no_artifacts_when_no_framework_dir(): void
    {
        $noFramework = sys_get_temp_dir() . '/waaseyaa_clear_nofw_handler_' . uniqid();
        mkdir($noFramework, 0o755, true);

        $tester = $this->createTester($noFramework);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('No cached artifacts found', $tester->getStdout());

        rmdir($noFramework);
    }

    #[Test]
    public function artifact_bound_clear_preserves_legacy_and_other_artifact_manifests(): void
    {
        $base = $this->tempDir . '/release';
        $runtime = $this->tempDir . '/runtime';
        $digest = str_repeat('a', 64);
        mkdir($base . '/.waaseyaa', 0o700, true);
        mkdir($base . '/vendor/composer', 0o700, true);
        mkdir($runtime . '/framework/manifests/' . $digest, 0o700, true);
        mkdir($runtime . '/framework/manifests/' . str_repeat('b', 64), 0o700, true);
        symlink($runtime, $base . '/storage');
        file_put_contents($base . '/composer.json', '{"extra":{"waaseyaa":{"manifest-cache":"artifact-bound-v1"}}}');
        file_put_contents($base . '/.waaseyaa/manifest-runtime-binding.json', json_encode(['schema' => 'waaseyaa.manifest-runtime-binding.v1', 'artifact_sha256' => $digest], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $compiler = new \Waaseyaa\Foundation\Discovery\PackageManifestCompiler($base, $base . '/storage');
        $compiler->compileAndCache();
        file_put_contents($runtime . '/framework/packages.php', 'legacy sentinel');
        file_put_contents($runtime . '/framework/config.php', '<?php return [];');
        $other = $runtime . '/framework/manifests/' . str_repeat('b', 64) . '/packages.php';
        file_put_contents($other, 'other artifact sentinel');
        $tester = $this->createTester($runtime, $compiler);
        $tester->execute([]);
        self::assertSame(0, $tester->getExitCode());
        self::assertFileDoesNotExist($compiler->selectedManifestPath());
        self::assertFileDoesNotExist($runtime . '/framework/config.php');
        self::assertSame('legacy sentinel', file_get_contents($runtime . '/framework/packages.php'));
        self::assertSame('other artifact sentinel', file_get_contents($other));
    }
    private function createTester(string $storagePath, ?\Waaseyaa\Foundation\Discovery\PackageManifestCompiler $compiler = null): CliTester
    {
        $handler = new OptimizeClearHandler(storagePath: $storagePath, manifestCompiler: $compiler);
        $definition = new HandlerCommand(
            name: 'optimize:clear',
            description: 'Remove all cached optimization artifacts',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }

}
