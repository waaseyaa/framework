<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * FW-SITE-BLUEPRINT-01D, 01D-1: the blueprint root compiler is unreachable
 * from the CLI in this slice. 01D-2 flips both compiler-token assertions
 * below when it wires the compiler into `site:init`/`site:doctor` under the
 * ADR-025 D-13 gate.
 */
#[CoversNothing]
final class BlueprintCompilerActivationBoundaryTest extends TestCase
{
    public function testAdditiveCompilerAdmissionDoesNotYetNameTheBlueprintCompiler(): void
    {
        $authority = new \ReflectionClass(\Waaseyaa\CLI\Site\SiteInitializationService::class);
        self::assertSame(
            [\Waaseyaa\SiteContract\Generation\SiteArtifactRenderer::class],
            $authority->getConstant('ADDITIVE_COMPILERS'),
            'ApplicationBlueprintCompiler joins this list only in 01D-2, under a reviewed D-13 eligibility gate.',
        );
    }

    public function testInstalledGeneratorFeatureRosterIsEmptyIn01D1(): void
    {
        self::assertSame([], \Waaseyaa\CLI\Site\SiteArtifactRendererFactory::advertisedGeneratorFeatures());
    }

    public function testNoHandlerDoctorOrFactoryPathReferencesTheBlueprintCompiler(): void
    {
        $root = dirname(__DIR__, 2);
        $scanRoots = [
            $root . '/packages/cli/src/Handler',
            $root . '/packages/cli/src/Site/SiteDoctorService.php',
            $root . '/packages/cli/src/Site/SiteArtifactRendererFactory.php',
        ];
        $offenders = [];
        foreach ($scanRoots as $scanRoot) {
            foreach ($this->phpFiles($scanRoot) as $file) {
                $source = (string) file_get_contents($file);
                if (str_contains($source, 'ApplicationBlueprintCompiler')) {
                    $offenders[] = $file;
                }
            }
        }
        self::assertSame(
            [],
            $offenders,
            'The blueprint compiler is unreachable from the CLI until 01D-2 wires it in under the D-13 gate.',
        );
    }

    public function testSiteInitHandlerReferencesGeneratorFeatureNegotiation(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/packages/cli/src/Handler/SiteInitHandler.php');
        self::assertStringContainsString('GeneratorFeatureNegotiation', $source);
    }

    public function testTheCompilerAndItsEmittersMakeNoFilesystemClockOrEnvironmentCalls(): void
    {
        $root = dirname(__DIR__, 2) . '/packages/cli/src/Site/Blueprint';
        $forbidden = [
            'date(', 'time(', 'microtime(', 'strtotime(',
            'getenv(', '$_ENV', '$_SERVER',
            'file_get_contents(', 'file_put_contents(', 'fopen(', 'is_file(', 'is_dir(', 'mkdir(', 'unlink(', 'scandir(', 'glob(',
            'random_bytes(', 'random_int(', 'uniqid(', 'rand(', 'mt_rand(',
        ];
        $offenders = [];
        foreach ($this->phpFiles($root) as $file) {
            $source = (string) file_get_contents($file);
            foreach ($forbidden as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = $file . ' -> ' . $needle;
                }
            }
        }
        self::assertSame([], $offenders, 'The blueprint compiler and its emitters must be pure functions of their input (ADR-025 D-8).');
    }

    /** @return list<string> */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        if (!is_dir($path)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
