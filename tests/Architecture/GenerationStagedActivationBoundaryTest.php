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
 * Slice 3 proved that with a whole-production assertion that named its own
 * retirement condition. Slice 4 met that condition for one of the two carriers
 * it covered -- `GenerationViolation` is now a real member of the evaluation
 * and result types -- but retiring the assertion outright would have thrown
 * away the half slice 4 does NOT activate, and would have left the seam slice 4
 * introduces with no ratchet of its own.
 *
 * So the proof is narrowed rather than dropped, on two axes that both survive to
 * the activation slice:
 *
 * - the still-unwired throwing carrier keeps a whole-production guard;
 * - the staged engine surface gets an ENTRYPOINT guard, which is the axis that
 *   matches what constraint 1 actually forbids and is therefore the one that
 *   stays true once slice 8 wires the engine into `site:init`'s own internals.
 *
 * Slice 8 is where these are inverted and retired. Until then a slice that
 * reaches the engine from an entrypoint turns this red, which is the point.
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
            $entersSeam = preg_match('/->\s*receiptFor\s*\(/', $code) === 1
                || (str_contains($code, 'SiteInitializationService') && preg_match('/->\s*evaluate\s*\(/', $code) === 1);
            if ($entersSeam) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'An entrypoint called the half-built engine seam.');
    }

    #[Test]
    public function theThrowingRefusalCarrierStillHasNoProductionCallerAnywhere(): void
    {
        // Slice 4 activates GenerationViolation as a member of the evaluation
        // and result types. It does NOT throw a coded refusal from anywhere, so
        // this half of slice 3's proof is retained verbatim rather than retired
        // alongside the half that genuinely completed.
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
            [],
            $offenders,
            'The slice that first raises a coded generation refusal retires this assertion; until then nothing throws one.',
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
