<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-025 D-12.1 constraint 1: "No partially-wired type is constructible from a
 * handler, no `GEN0xx` code is emitted by a path that cannot honour it, and no
 * entrypoint reaches a half-built engine."
 *
 * Slice 5 completes coded unit refusals inside the dormant execution
 * authority. The production reference is pinned to that single service;
 * entrypoints still cannot reach the generation seams until slice 8.
 */
#[CoversNothing]
final class GenerationStagedActivationBoundaryTest extends TestCase
{
    /** Where a user-reachable command actually begins. */
    private const array ENTRYPOINT_ROOTS = [
        'packages/cli/src/Handler/',
        'packages/cli/src/Command/',
        'packages/cli/src/Provider/',
        'bin/',
        'public/',
        'skeleton/',
    ];

    private const string REFUSAL_FAMILY_DIR = 'packages/site-contract/src/Generation/Exception/';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function noEntrypointReferencesTheStagedEngineSurface(): void
    {
        $staged = '/\b(?:EvaluatedArtifactPlan|ArtifactApplyRequest|ArtifactApplyResult|ArtifactPlan|ChangeReceipt)\b/';
        $offenders = [];
        foreach ($this->productionPhpCodeFiles() as $relative => $code) {
            if (!$this->isEntrypoint($relative)) {
                continue;
            }
            if (preg_match($staged, $code) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A staged generation type reached an entrypoint before the activation slice.',
        );
    }

    #[Test]
    public function noEntrypointEntersTheEvaluationOrReceiptSeam(): void
    {
        $offenders = [];
        foreach ($this->productionPhpCodeFiles() as $relative => $code) {
            if (!$this->isEntrypoint($relative)) {
                continue;
            }
            // `receiptFor` is unique to this authority, so a bare call is
            // enough. `evaluate` is an ordinary English verb that unrelated
            // scripts use, so it only counts inside a file that also names the
            // service -- precision here, not an allowlist.
            $entersSeam = preg_match('/->\s*(?:receiptFor|inspectUnits|readUnitMetadata|prepareUnitPlan)\s*\(/', $code) === 1
                || (str_contains($code, 'SiteInitializationService') && preg_match('/->\s*evaluate\s*\(/', $code) === 1);
            if ($entersSeam) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'An entrypoint called the half-built engine seam.');
    }

    #[Test]
    public function theThrowingRefusalCarrierIsConfinedToTheDormantExecutionAuthority(): void
    {
        // Slice 5 completes typed unit refusals within the same dormant
        // authority. A second production caller is still an activation leak.
        $offenders = [];
        foreach ($this->productionPhpCodeFiles() as $relative => $code) {
            if (str_starts_with($relative, self::REFUSAL_FAMILY_DIR)) {
                continue;
            }
            if (preg_match('/\bGenerationRefusalException\b/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            ['packages/cli/src/Site/SiteInitializationService.php'],
            $offenders,
            'Coded generation refusals must stay in the completed dormant execution authority.',
        );
    }

    private function isEntrypoint(string $relative): bool
    {
        foreach (self::ENTRYPOINT_ROOTS as $root) {
            if (str_starts_with($relative, $root)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function productionPhpCodeFiles(): array
    {
        $files = [];
        $sourceRoots = glob($this->root . '/packages/*/src', GLOB_ONLYDIR) ?: [];
        foreach (['/bin', '/public', '/skeleton/bin', '/skeleton/public', '/skeleton/src', '/tools'] as $relativeRoot) {
            if (is_dir($this->root . $relativeRoot)) {
                $sourceRoots[] = $this->root . $relativeRoot;
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
                $source = file_get_contents($file->getPathname());
                if ($source === false) {
                    continue;
                }
                if ($file->getExtension() !== 'php' && !str_contains(substr($source, 0, 256), '<?php')) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root) + 1));
                $code = '';
                foreach (token_get_all($source) as $token) {
                    if (is_array($token)) {
                        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                            continue;
                        }
                        $code .= $token[1];
                        continue;
                    }
                    $code .= $token;
                }
                $files[$relative] = $code;
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
