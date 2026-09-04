<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class GenerationUnitActivationBoundaryTest extends TestCase
{
    public function testSeededCompilerAdmissionIsClosedUntilMigration(): void
    {
        $authority = new \ReflectionClass(\Waaseyaa\CLI\Site\SiteInitializationService::class);
        self::assertSame([], $authority->getConstant('SEEDED_COMPILERS'));
    }

    public function testAdditiveCompilerAdmissionContainsOnlyTheManifestRootCompiler(): void
    {
        $authority = new \ReflectionClass(\Waaseyaa\CLI\Site\SiteInitializationService::class);
        self::assertTrue($authority->hasConstant('ADDITIVE_COMPILERS'));
        self::assertSame(
            [\Waaseyaa\SiteContract\Generation\SiteArtifactRenderer::class],
            $authority->getConstant('ADDITIVE_COMPILERS'),
            'Blueprint and non-root compilers require a separately reviewed authority expansion.',
        );
    }

    public function testOnlySiteDoctorReachesUnitInspection(): void
    {
        $root = dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/packages/cli/src/Handler', \FilesystemIterator::SKIP_DOTS));
        $callers = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }
                foreach (['inspectUnits', 'readUnitMetadata', 'readComposerProviderState', 'prepareUnitPlan'] as $seam) {
                    if ($seam === trim($token[1], "'\"")) {
                        $callers[] = $file->getPathname() . ':' . $seam;
                    }
                }
            }
        }
        self::assertSame(
            [$root . '/packages/cli/src/Handler/SiteDoctorHandler.php:inspectUnits'],
            $callers,
            'Only the migrated doctor may inspect unit state; handlers never own metadata, Composer reconciliation or preparation.',
        );
    }
}
