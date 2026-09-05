<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class GenerationAuthorityConstraintsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function transactionRecoveryMarkersRemainOwnedBySiteInitializationService(): void
    {
        $expectedOwner = 'packages/cli/src/Site/SiteInitializationService.php';
        foreach ([
            '.waaseyaa/site-init.transaction.json',
            '.waaseyaa/site-init.lock',
            '.waaseyaa/site-init-stage-',
            '.waaseyaa/site-init-backup-',
            'waaseyaa.site-init-transaction',
        ] as $marker) {
            self::assertSame([$expectedOwner], $this->productionFilesContaining($marker), $marker);
        }
    }

    #[Test]
    public function nativeExclusiveLockImplementationsHaveAClosedSetOfOwners(): void
    {
        self::assertSame([
            'bin/dev-runtime',
            'bin/dev-runtime-consumer',
            'bin/worktree-coordinator',
            'packages/cli/src/Handler/DbInitHandler.php',
            'packages/cli/src/Site/SiteInitializationService.php',
            'packages/migration/src/Runner/MigrationLock.php',
        ], $this->productionFilesMatching('/\\\\?flock\\s*\\([^;]*\\\\?LOCK_EX/s'));
    }

    #[Test]
    public function generatedOwnershipDocumentHasOneClosedSetOfProductionParticipants(): void
    {
        self::assertSame([
            'packages/cli/src/Site/Blueprint/ApplicationBlueprintCompiler.php',
            'packages/cli/src/Site/SiteDoctorService.php',
            'packages/cli/src/Site/SiteInitializationService.php',
            'packages/site-contract/src/Generation/GeneratedSite.php',
            'packages/site-contract/src/Generation/SiteArtifactRenderer.php',
        ], $this->productionFilesWithExactStringLiteral('.waaseyaa/generated.json'));
    }

    #[Test]
    public function generationDoesNotPersistAChangeReceiptOrCreateAnotherOwnershipDocument(): void
    {
        self::assertSame([], $this->productionFilesContaining('receipts.jsonl'));
        self::assertSame([], $this->productionFilesContaining('.waaseyaa/change-receipt'));

        foreach ($this->productionPhpCodeFiles() as $relative => $contents) {
            preg_match_all('/\.waaseyaa\/[a-z0-9._-]*(?:generated|ownership)[a-z0-9._-]*\.json/i', $contents, $matches);
            foreach ($matches[0] as $path) {
                self::assertSame('.waaseyaa/generated.json', $path, $relative);
            }
        }
    }

    /** @return list<string> */
    private function productionFilesContaining(string $needle): array
    {
        $matches = [];
        foreach ($this->productionPhpCodeFiles() as $relative => $contents) {
            if (str_contains($contents, $needle)) {
                $matches[] = $relative;
            }
        }

        sort($matches, SORT_STRING);

        return $matches;
    }

    /** @return list<string> */
    private function productionFilesWithExactStringLiteral(string $literal): array
    {
        $matches = [];
        foreach ($this->productionPhpCodeFiles() as $relative => $contents) {
            foreach (token_get_all($contents) as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                if ($token[1] === "'{$literal}'" || $token[1] === '"' . $literal . '"') {
                    $matches[] = $relative;
                    break;
                }
            }
        }

        sort($matches, SORT_STRING);

        return $matches;
    }

    /** @return list<string> */
    private function productionFilesMatching(string $pattern): array
    {
        $matches = [];
        foreach ($this->productionPhpCodeFiles() as $relative => $contents) {
            if (preg_match($pattern, $contents) === 1) {
                $matches[] = $relative;
            }
        }

        sort($matches, SORT_STRING);

        return $matches;
    }

    /** @return array<string, string> */
    private function productionPhpCodeFiles(): array
    {
        $files = [];
        $sourceRoots = glob($this->root . '/packages/*/src', GLOB_ONLYDIR) ?: [];
        foreach ([
            '/bin',
            '/public',
            '/skeleton/bin',
            '/skeleton/public',
            '/skeleton/src',
            '/tools',
        ] as $relativeRoot) {
            $sourceRoot = $this->root . $relativeRoot;
            if (is_dir($sourceRoot)) {
                $sourceRoots[] = $sourceRoot;
            }
        }

        foreach ($sourceRoots as $sourceRoot) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $relative = str_replace('\\', '/', substr($path, strlen($this->root) + 1));
                $source = file_get_contents($path);
                if ($source === false) {
                    throw new \RuntimeException("Unable to read production source: {$relative}");
                }
                if ($file->getExtension() !== 'php' && !str_contains(substr($source, 0, 256), '<?php')) {
                    continue;
                }
                $code = '';
                foreach (token_get_all($source) as $token) {
                    if (is_array($token)) {
                        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                            continue;
                        }
                        $code .= $token[1];
                    } else {
                        $code .= $token;
                    }
                }
                $files[$relative] = $code;
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
