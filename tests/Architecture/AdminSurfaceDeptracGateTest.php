<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Discriminating controls for the package-local Deptrac authority.
 *
 * These fixtures run the real Deptrac configuration. They prove a legal inward
 * dependency remains green, an upward dependency fails, and an unclassified
 * dependency cannot disappear from the report.
 */
#[CoversNothing]
final class AdminSurfaceDeptracGateTest extends TestCase
{
    private string $root;
    private string $deptrac;
    private string $config;
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->deptrac = $this->root . '/vendor/bin/deptrac';
        $this->config = $this->root . '/packages/admin-surface/deptrac.yaml';
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa-admin-surface-deptrac-' . uniqid('', true);

        self::assertFileExists($this->deptrac, 'Install the locked Deptrac development dependency.');
        self::assertFileExists($this->config, 'The package-local Deptrac configuration is required.');
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpRoot)) {
            new Filesystem()->remove($this->tmpRoot);
        }
    }

    #[Test]
    public function production_architecture_is_clean_and_has_no_uncovered_dependencies(): void
    {
        [$exit, $report, $output] = $this->runDeptrac($this->config);

        self::assertSame(0, $exit, $output);
        self::assertSame(0, $report['Violations'] ?? null, $output);
        self::assertSame(0, $report['Uncovered'] ?? null, $output);

        [$debugExit, $debugOutput] = $this->runDeptracCommand($this->config, ['debug:unassigned']);
        self::assertSame(0, $debugExit, $debugOutput);
        self::assertStringContainsString('There are no unassigned tokens.', $debugOutput);
    }

    #[Test]
    public function committed_mermaid_view_matches_deptrac_output(): void
    {
        [$exit, $output] = $this->runDeptracCommand($this->config, [
            '--no-progress',
            '--fail-on-uncovered',
            '--report-uncovered',
            '--formatter=mermaidjs',
        ]);

        self::assertSame(0, $exit, $output);
        self::assertSame(
            str_replace("\r\n", "\n", (string) file_get_contents(
                $this->root . '/packages/admin-surface/dependency-graph.mmd',
            )),
            str_replace("\r\n", "\n", $output),
            'Refresh the committed view with composer admin-surface-dependency-view.',
        );
    }

    #[Test]
    public function allows_application_adapter_to_depend_on_boundary_contract(): void
    {
        $config = $this->fixtureConfig([
            'Host/AdminSurfaceResultData.php' => <<<'PHP'
                <?php
                namespace Waaseyaa\AdminSurface\Host;
                final class AdminSurfaceResultData {}
                PHP,
            'Host/GenericAdminSurfaceHost.php' => <<<'PHP'
                <?php
                namespace Waaseyaa\AdminSurface\Host;
                final class GenericAdminSurfaceHost
                {
                    public function __construct(AdminSurfaceResultData $result) {}
                }
                PHP,
        ]);

        [$exit, $report, $output] = $this->runDeptrac($config);

        self::assertSame(0, $exit, $output);
        self::assertGreaterThan(0, $report['Allowed'] ?? 0, $output);
        self::assertSame(0, $report['Violations'] ?? null, $output);
        self::assertSame(0, $report['Uncovered'] ?? null, $output);
    }

    #[Test]
    public function rejects_boundary_contract_dependency_on_application_adapter(): void
    {
        $config = $this->fixtureConfig([
            'Host/AdminSurfaceResultData.php' => <<<'PHP'
                <?php
                namespace Waaseyaa\AdminSurface\Host;
                final class AdminSurfaceResultData
                {
                    public function __construct(GenericAdminSurfaceHost $host) {}
                }
                PHP,
            'Host/GenericAdminSurfaceHost.php' => <<<'PHP'
                <?php
                namespace Waaseyaa\AdminSurface\Host;
                final class GenericAdminSurfaceHost {}
                PHP,
        ]);

        [$exit, $report, $output] = $this->runDeptrac($config);

        self::assertSame(1, $exit, $output);
        self::assertGreaterThan(0, $report['Violations'] ?? 0, $output);
        self::assertStringContainsString('Boundary contract', $output);
        self::assertStringContainsString('Application adapters', $output);
    }

    #[Test]
    public function rejects_an_unclassified_admin_surface_dependency(): void
    {
        $config = $this->fixtureConfig([
            'Host/AdminSurfaceResultData.php' => <<<'PHP'
                <?php
                namespace Waaseyaa\AdminSurface\Host;
                use Waaseyaa\AdminSurface\Unclassified\UnknownDependency;
                final class AdminSurfaceResultData
                {
                    public function __construct(UnknownDependency $dependency) {}
                }
                PHP,
            'Unclassified/UnknownDependency.php' => <<<'PHP'
                <?php
                namespace Waaseyaa\AdminSurface\Unclassified;
                final class UnknownDependency {}
                PHP,
        ]);

        [$exit, $report, $output] = $this->runDeptrac($config);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('UnknownDependency', $output);
        self::assertGreaterThan(0, $report['Uncovered'] ?? 0, $output);
    }

    /**
     * @param array<string, string> $files
     */
    private function fixtureConfig(array $files): string
    {
        $sourceRoot = $this->tmpRoot . '/src';
        foreach ($files as $relative => $contents) {
            $path = $sourceRoot . '/' . $relative;
            new Filesystem()->mkdir(dirname($path));
            file_put_contents($path, $contents . "\n");
        }

        $config = (string) file_get_contents($this->config);
        $fixturePath = str_replace('\\', '/', $sourceRoot);
        $fixtureConfig = str_replace(
            '    - src',
            "    - '" . str_replace("'", "''", $fixturePath) . "'",
            $config,
            $replacements,
        );
        self::assertSame(1, $replacements, 'Fixture must replace the one authoritative production path.');

        $path = $this->tmpRoot . '/deptrac.yaml';
        file_put_contents($path, $fixtureConfig);

        return $path;
    }

    /** @return array{int, array<string, int>, string} */
    private function runDeptrac(string $config): array
    {
        [$exit, $output] = $this->runDeptracCommand($config, [
            '--no-progress',
            '--fail-on-uncovered',
            '--report-uncovered',
            '--formatter=json',
        ]);

        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            self::fail('Deptrac did not return its stable JSON report: ' . $exception->getMessage() . "\n" . $output);
        }
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['Report'] ?? null, 'Deptrac JSON must contain its result summary.');

        return [$exit, $decoded['Report'], $output];
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function runDeptracCommand(string $config, array $arguments): array
    {
        $process = new Process([
            PHP_BINARY,
            $this->deptrac,
            '--config-file=' . $config,
            '--no-cache',
            ...$arguments,
        ], $this->root);
        $process->setTimeout(30);
        $process->run();

        return [$process->getExitCode() ?? 255, $process->getOutput() . $process->getErrorOutput()];
    }
}
