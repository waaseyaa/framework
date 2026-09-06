<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class GenerationUnitActivationBoundaryTest extends TestCase
{
    public function testSeededCompilerAdmissionContainsOnlyTheReviewedContentTypeCompiler(): void
    {
        $authority = new \ReflectionClass(\Waaseyaa\CLI\Site\SiteInitializationService::class);
        self::assertSame(
            [\Waaseyaa\CLI\Site\Scaffold\ContentTypeScaffoldCompiler::class],
            $authority->getConstant('SEEDED_COMPILERS'),
        );
    }

    public function testAdditiveCompilerAdmissionContainsOnlyTheTwoReviewedRootCompilers(): void
    {
        $authority = new \ReflectionClass(\Waaseyaa\CLI\Site\SiteInitializationService::class);
        self::assertTrue($authority->hasConstant('ADDITIVE_COMPILERS'));
        self::assertSame(
            [
                \Waaseyaa\SiteContract\Generation\SiteArtifactRenderer::class,
                \Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompiler::class,
            ],
            $authority->getConstant('ADDITIVE_COMPILERS'),
            'Every other compiler and every non-root mode remain outside the reviewed additive admission.',
        );
    }

    public function testEveryOtherArtifactPlanProducerRemainsFrozenByDefault(): void
    {
        $constructor = new \ReflectionMethod(\Waaseyaa\SiteContract\Generation\ArtifactPlan::class, '__construct');
        $evolution = array_values(array_filter(
            $constructor->getParameters(),
            static fn(\ReflectionParameter $parameter): bool => $parameter->getName() === 'setEvolution',
        ));
        self::assertCount(1, $evolution);
        self::assertTrue($evolution[0]->isDefaultValueAvailable());
        self::assertSame(\Waaseyaa\SiteContract\Generation\ArtifactSetEvolution::Frozen, $evolution[0]->getDefaultValue());

        $root = dirname(__DIR__, 2);
        $explicitAdditiveOwners = [];
        $sourceRoots = glob($root . '/packages/*/src', GLOB_ONLYDIR) ?: [];
        foreach ($sourceRoots as $sourceRoot) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $code = '';
                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                        continue;
                    }
                    $code .= is_array($token) ? $token[1] : $token;
                }
                if (str_contains($code, 'ArtifactSetEvolution::Additive')) {
                    $explicitAdditiveOwners[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($explicitAdditiveOwners, SORT_STRING);
        self::assertSame([
            'packages/cli/src/Site/Blueprint/ApplicationBlueprintCompiler.php',
            'packages/cli/src/Site/SiteInitializationService.php',
        ], $explicitAdditiveOwners);
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
