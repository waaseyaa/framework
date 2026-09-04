<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-025 D-5 reserves the `GEN0xx` family to `waaseyaa/site-contract` and
 * says that coding the exceptions to carry those ids is #2846 implementation.
 * D-12.1 slice 3 ships the family "coded but thrown from no new path".
 *
 * The assertions here are the DURABLE half of that: they stay true through
 * slices 4-8 and through activation, so no later implementer has to edit them
 * to add a legitimate refusal. A slice that raises a coded refusal names the
 * exception class; it never inlines an id, which is exactly what centralizing
 * the family in one namespace buys.
 *
 * The TRANSIENT half -- that nothing in production constructs these types yet
 * -- is asserted explicitly by the slice-three-only test below. Slice 4 must
 * delete that test when it introduces the first caller. This visible staged
 * assertion is intentionally not encoded as growth in the repository's
 * shrink-only dead-code baseline.
 */
#[CoversNothing]
final class GenerationErrorCodeBoundaryTest extends TestCase
{
    private const string FAMILY_DIR = 'packages/site-contract/src/Generation/Exception/';

    private const string SURFACE_MAP = 'docs/public-surface-map.php';

    private const string AUTHORITY = 'docs/adr/025-unified-artifact-generation-authority.md';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function generationErrorCodeLiteralsAppearOnlyInsideTheFamilyThatOwnsThem(): void
    {
        $offenders = [];
        foreach ($this->productionPhpCodeFiles() as $relative => $code) {
            if (str_starts_with($relative, self::FAMILY_DIR)) {
                continue;
            }
            if (preg_match('/GEN[0-9]{3}/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Raise the typed refusal instead of inlining a GEN0xx id: the family is owned by ' . self::FAMILY_DIR . '.',
        );
    }

    #[Test]
    public function theFamilyIsHomedInSiteContractBesideTheManifestContentFamily(): void
    {
        self::assertDirectoryExists($this->root . '/' . self::FAMILY_DIR);
        self::assertDirectoryExists($this->root . '/packages/site-contract/src/Exception');
    }

    #[Test]
    public function theClosedVocabularyMatchesTheDecisionTableWithoutASecondHandMaintainedEnumeration(): void
    {
        $adr = file_get_contents($this->root . '/' . self::AUTHORITY);
        self::assertIsString($adr);
        self::assertSame(1, preg_match('/^### D-5\..*?(?=^### D-6\.)/ms', $adr, $section));
        self::assertGreaterThan(0, preg_match_all('/^\s*\|\s*`(GEN[0-9]{3}_[A-Z][A-Z_]*)`\s*\|/m', $section[0], $matches));

        $decisionIds = $matches[1];
        $enumIds = array_map(static fn(\Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode $code): string => $code->value, \Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode::cases());
        $sortedDecisionIds = $decisionIds;
        $sortedEnumIds = $enumIds;
        sort($sortedDecisionIds, SORT_STRING);
        sort($sortedEnumIds, SORT_STRING);

        self::assertSame($sortedDecisionIds, $sortedEnumIds, 'ADR-025 D-5 is the closed GEN0xx authority; code must not add or omit an id.');
        self::assertSame($sortedEnumIds, $enumIds, 'Enum cases stay in numeric order even if the decision table is edited out of order.');
    }

    #[Test]
    public function theClosedCodeVocabularyIsTrackedAsPublicSurfaceAndItsCarriersAreNot(): void
    {
        $map = require $this->root . '/' . self::SURFACE_MAP;
        self::assertIsArray($map);

        self::assertSame(
            'public',
            $map['Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode'] ?? null,
            'The reserved id vocabulary is a contract shape every plan reader switches on.',
        );

        // The surface map tracks contract shapes only. The exception and its
        // violation are concrete final classes -- implementations, not
        // extension points -- so they are intentionally absent.
        foreach ([
            'Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException',
            'Waaseyaa\SiteContract\Generation\Exception\GenerationViolation',
        ] as $concrete) {
            self::assertArrayNotHasKey($concrete, $map);
        }
    }

    #[Test]
    public function sliceThreeHasNoProductionCallerOfTheRefusalCarriers(): void
    {
        $offenders = [];
        foreach ($this->productionPhpCodeFiles() as $relative => $code) {
            if (str_starts_with($relative, self::FAMILY_DIR)) {
                continue;
            }
            if (preg_match('/\b(?:GenerationRefusalException|GenerationViolation)\b/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Slice 3 is non-activating. Delete this slice-only assertion when slice 4 introduces the first typed refusal caller.',
        );
    }

    /** @return array<string, string> */
    private function productionPhpCodeFiles(): array
    {
        $files = [];
        $sourceRoots = glob($this->root . '/packages/*/src', GLOB_ONLYDIR) ?: [];
        foreach (['migrations', 'recipe'] as $packageRuntimeDirectory) {
            array_push($sourceRoots, ...(glob($this->root . '/packages/*/' . $packageRuntimeDirectory, GLOB_ONLYDIR) ?: []));
        }
        foreach ([
            '/bin',
            '/config',
            '/public',
            '/skeleton/.ci',
            '/skeleton/bin',
            '/skeleton/config',
            '/skeleton/public',
            '/skeleton/src',
            '/tools',
        ] as $relativeRoot) {
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
