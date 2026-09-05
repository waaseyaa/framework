<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Real-gate regression for the loadability half of surface parity.
 */
#[CoversNothing]
final class SurfaceParityLoadabilityTest extends TestCase
{
    private string $repoRoot;
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->fixtureRoot = sys_get_temp_dir() . '/waaseyaa_surface_loadability_' . uniqid('', true);

        $clone = new Process([$this->repoRoot . '/bin/git', 'clone', '--shared', $this->repoRoot, $this->fixtureRoot]);
        self::assertSame(0, $clone->run(), $clone->getErrorOutput());

        $fs = new Filesystem();
        $fs->copy(
            $this->repoRoot . '/tools/check-surface-parity.php',
            $this->fixtureRoot . '/tools/check-surface-parity.php',
            true,
        );
        $fs->mkdir($this->fixtureRoot . '/vendor');
        $autoload = <<<'PHP'
            <?php

            declare(strict_types=1);

            require %s;

            spl_autoload_register(static function (string $fqcn): void {
                if (!str_starts_with($fqcn, 'Waaseyaa\\')) {
                    return;
                }
                foreach (glob(__DIR__ . '/../packages/*/composer.json') ?: [] as $manifest) {
                    $package = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
                    foreach ($package['autoload']['psr-4'] ?? [] as $prefix => $directory) {
                        if (!str_starts_with($fqcn, $prefix)) {
                            continue;
                        }
                        $path = dirname($manifest) . '/' . $directory . str_replace('\\', '/', substr($fqcn, strlen($prefix))) . '.php';
                        if (is_file($path)) {
                            require $path;
                        }
                        return;
                    }
                }
            }, true, true);
            PHP;
        $fs->dumpFile(
            $this->fixtureRoot . '/vendor/autoload.php',
            sprintf($autoload, var_export($this->repoRoot . '/vendor/autoload.php', true)),
        );
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->fixtureRoot);
    }

    #[Test]
    public function real_gate_rejects_a_declared_shape_that_psr4_cannot_load(): void
    {
        $fs = new Filesystem();
        $fqcn = 'Waaseyaa\\Access\\SurfaceLoadabilityProbeInterface';
        $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Waaseyaa\\Access;\n\ninterface SurfaceLoadabilityProbeInterface\n{\n}\n";
        $wrongPath = $this->fixtureRoot . '/packages/access/src/WrongFilenameProbe.php';
        $correctPath = $this->fixtureRoot . '/packages/access/src/SurfaceLoadabilityProbeInterface.php';
        $fs->dumpFile($wrongPath, $source);

        $declarationPath = $this->fixtureRoot . '/packages/access/public-surface.php';
        $declaration = (string) file_get_contents($declarationPath);
        $declaration = str_replace(
            "    'entries' => [\n",
            "    'entries' => [\n        ['fqcn' => '{$fqcn}', 'disposition' => 'internal'],\n",
            $declaration,
        );
        $fs->dumpFile($declarationPath, $declaration);

        $this->generateViews();
        [$wrongExit, $wrongOutput] = $this->runGate();
        self::assertNotSame(0, $wrongExit, "A wrong-filename PSR-4 declaration must not satisfy the real gate.\n{$wrongOutput}");
        self::assertStringContainsString($fqcn, $wrongOutput);
        self::assertStringContainsString('do not load', $wrongOutput);

        $fs->rename($wrongPath, $correctPath);
        $this->generateViews();
        [$correctExit, $correctOutput] = $this->runGate();
        self::assertSame(0, $correctExit, "The correctly autoloadable declaration must pass.\n{$correctOutput}");
    }

    private function generateViews(): void
    {
        $process = new Process([PHP_BINARY, $this->fixtureRoot . '/bin/generate-surface-map', '--write'], $this->fixtureRoot, null, null, 120);
        self::assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
    }

    /** @return array{int, string} */
    private function runGate(): array
    {
        $process = new Process([PHP_BINARY, $this->fixtureRoot . '/tools/check-surface-parity.php', '--base=HEAD^'], $this->fixtureRoot, null, null, 120);
        $exit = $process->run();

        return [$exit, $process->getOutput() . $process->getErrorOutput()];
    }
}
