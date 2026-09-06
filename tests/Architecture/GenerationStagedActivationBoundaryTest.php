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
 * Slice 8 activates site:init and site:doctor only. Other entrypoints
 * remain barred until their command-specific migrations; preparation and
 * ownership readers remain inside the execution authority.
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
        'config/',
        'tools/',
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
    public function onlySiteInitTransportsGenerationResults(): void
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
            ['packages/cli/src/Handler/SiteInitHandler.php'],
            $offenders,
            'Generation result transport requires a separately reviewed command migration.',
        );
    }

    #[Test]
    public function onlySiteDoctorEntersUnitInspection(): void
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
            $entersSeam = preg_match('/->\s*(?:receiptFor|inspectUnits|readUnitMetadata|readComposerProviderState|prepareUnitPlan)\s*\(/', $code) === 1
                || (str_contains($code, 'SiteInitializationService') && preg_match('/->\s*(?:evaluate|apply)\s*\(/', $code) === 1);
            if ($entersSeam) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(['packages/cli/src/Handler/SiteDoctorHandler.php'], $offenders, 'Only migrated doctor inspection may enter these seams.');
    }

    #[Test]
    public function theThrowingRefusalCarrierIsConfinedToTheExecutionAuthorityAndFeatureNegotiation(): void
    {
        // Typed generation refusals belong to the existing authority, plus
        // FW-SITE-BLUEPRINT-01D's fail-closed generator-feature negotiation
        // (decision (g)): `GeneratorFeatureNegotiation` is a second,
        // deliberately reviewed call site for the SAME closed GEN0xx family
        // (ADR-025 D-5 already reserves GEN007 "for the plan-compilation
        // boundary"), not a second admission engine — it carries no plan,
        // no evaluation, and no apply. `SiteInitHandler` only catches and
        // relays the coded refusal into its existing JSON envelope, exactly
        // as it already does for `SiteManifestValidationException`; it never
        // constructs one. `ApplicationBlueprintCompiler` is a third,
        // deliberately reviewed call site (review round 1, F3): before
        // invoking any emitter it asserts every blueprint id headed for a
        // PHP identifier position is one, reusing `GEN006_MALICIOUS_IDENTIFIER`
        // — the same closed family, at the plan-compilation boundary
        // `compile()` already owns, still carrying no evaluation or apply.
        $offenders = [];
        foreach ($this->productionPhpCodeFiles() as $relative => $code) {
            if (str_starts_with($relative, self::REFUSAL_FAMILY_DIR)) {
                continue;
            }
            if (preg_match('/\bGenerationRefusalException\b/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        // #2788 (FW-SITE-BLUEPRINT-01E): the blueprint emitters are the
        // compiler's own pure functions, invoked only from `compile()` at the
        // same plan-compilation boundary. Each raises the closed GEN006/GEN007
        // family for a declaration the validator admits but the emitter cannot
        // represent safely (an `administrator` role id, an ownership or
        // workflow-state condition on `create`, a colliding generated
        // identifier, a check on an unbound workflow, a reserved workflow
        // field) — before any artifact exists, and still carrying no
        // evaluation or apply. They are recorded here as reviewed call sites
        // of that one family, not as a widening of the execution authority.
        self::assertSame(
            [
                'packages/cli/src/Handler/SiteInitHandler.php',
                'packages/cli/src/Site/Blueprint/ApplicationBlueprintCompiler.php',
                'packages/cli/src/Site/Blueprint/Emitter/AccessPolicyEmitter.php',
                'packages/cli/src/Site/Blueprint/Emitter/EntityClassEmitter.php',
                'packages/cli/src/Site/Blueprint/Emitter/GovernanceCheckEmitter.php',
                'packages/cli/src/Site/Blueprint/Emitter/GovernanceProviderEmitter.php',
                'packages/cli/src/Site/Blueprint/Emitter/PermissionCatalogueEmitter.php',
                'packages/cli/src/Site/Blueprint/Emitter/WorkflowDefinitionEmitter.php',
                'packages/cli/src/Site/SiteInitializationService.php',
                'packages/site-contract/src/Generation/GeneratorFeatureNegotiation.php',
            ],
            $offenders,
            'Coded generation refusals must stay in the execution authority, the reviewed feature-negotiation boundary, or the compiler\'s own emitters.',
        );
    }

    #[Test]
    public function stagedProofScansExecutableSurfacesOutsidePackageSource(): void
    {
        $fixture = sys_get_temp_dir() . '/waaseyaa-staged-inventory-' . bin2hex(random_bytes(8));
        $paths = [
            'packages/example/migrations/probe.php',
            'packages/example/recipe/probe.php',
            'config/probe.php',
            'skeleton/.ci/probe.php',
            'skeleton/config/probe.php',
        ];
        $priorRoot = $this->root;
        try {
            foreach ($paths as $path) {
                mkdir(dirname($fixture . '/' . $path), 0o700, true);
                file_put_contents($fixture . '/' . $path, '<?php $service->inspectUnits();');
            }
            $this->root = $fixture;
            $found = array_keys($this->productionPhpCodeFiles());
            sort($paths, SORT_STRING);
            self::assertSame($paths, $found, 'Executable runtime scripts must participate in the staged proof.');
            foreach ($paths as $path) {
                self::assertTrue($this->isEntrypoint($path), $path . ' must be subject to the entrypoint seam ban.');
            }
        } finally {
            $this->root = $priorRoot;
            new \Symfony\Component\Filesystem\Filesystem()->remove($fixture);
        }
    }

    private function isEntrypoint(string $relative): bool
    {
        if (preg_match('~^packages/[^/]+/(?:migrations|recipe)/~', $relative) === 1) {
            return true;
        }
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
        foreach (['migrations', 'recipe'] as $packageRuntimeDirectory) {
            array_push($sourceRoots, ...(glob($this->root . '/packages/*/' . $packageRuntimeDirectory, GLOB_ONLYDIR) ?: []));
        }
        foreach (['/bin', '/config', '/public', '/skeleton/.ci', '/skeleton/bin', '/skeleton/config', '/skeleton/public', '/skeleton/src', '/tools'] as $relativeRoot) {
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
