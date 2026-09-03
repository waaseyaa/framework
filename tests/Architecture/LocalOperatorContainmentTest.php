<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-022 D-3.0 (where the class lives) and D-6 R-1/R-2/R-3, bound to a gate
 * rather than to review attention.
 *
 * D-3.0 states the invariant — the package homing `LocalOperatorPrincipal`
 * MUST be absent from the production `require` closure of
 * `waaseyaa/framework`, `waaseyaa/core`, `waaseyaa/cms`, and `waaseyaa/full` —
 * and names `waaseyaa/ai-agent` as today's satisfying answer. D-3.0a is
 * explicit that packaging is the weaker control (the runtime refusal, proven
 * out of process in `LocalOperatorTrustBoundaryTest`, is the real one), but it
 * is still a control, and D-10 requires it to be gated: "a routine dependency
 * edit is enough to void it silently — the edit would look like adding a
 * require, not like relocating a security-sensitive class into production".
 *
 * The containment assertions below discharge the *negative* refusal rows.
 * R-1 (HTTP authentication), R-2 (token validation), and R-3 (persistent
 * account resolution) are properties of code that does NOT exist: no auth
 * path, token store, or account loader may ever yield this principal. A test
 * asserting that no production file outside its own directory so much as names
 * the class or its sentinel is what makes that checkable.
 */
#[CoversNothing]
final class LocalOperatorContainmentTest extends TestCase
{
    private const string HOME_PACKAGE = 'waaseyaa/ai-agent';
    private const string HOME_DIRECTORY = 'packages/ai-agent/src/LocalOperator';
    private const string SENTINEL = 'local-operator:stdio';

    /**
     * The one legitimate reference site this docblock already named before it
     * existed: "the local stdio transport bootstrap" (#2659, ADR-022 D-9.2).
     * `McpServeCommand::execute()` is the sole call to
     * `LocalOperatorPrincipal::forLocalStdioTransport()` outside the home
     * directory and outside `LocalOperatorTrustBoundaryTest`'s own probes
     * (which already sit under `tests/`, not `packages/`, so they never reach
     * this scan); `McpStdioServiceProvider` names the sentinel class only as
     * an `OptionalPackageRequirement`, never to construct one. Neither file
     * installs the principal into the kernel `AccountContext` (R-4), holds an
     * HTTP auth path, or resolves a persisted account (R-1/R-2/R-3) — this
     * allowlist is exactly as narrow as those rows require, not an escape
     * hatch for a broader import.
     *
     * @var list<string>
     */
    private const array ALLOWED_BOOTSTRAP_REFERENCES = [
        'packages/cli/src/Command/Mcp/McpServeCommand.php',
        'packages/cli/src/Provider/McpStdioServiceProvider.php',
    ];

    /** @var list<string> */
    private const array PRODUCTION_ROOTS = [
        'composer.json',
        'packages/core/composer.json',
        'packages/cms/composer.json',
        'packages/full/composer.json',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /** D-3.0 — the class is where the ADR designates. */
    #[Test]
    public function the_principal_lives_in_the_designated_package(): void
    {
        self::assertFileExists($this->root . '/' . self::HOME_DIRECTORY . '/LocalOperatorPrincipal.php');
    }

    /**
     * D-3.0 / D-10 — the closure invariant. If `waaseyaa/ai-agent` ever enters
     * a production require closure, the principal MUST move rather than the
     * invariant bending, and this test is what says so out loud.
     */
    #[Test]
    public function the_home_package_is_outside_every_production_require_closure(): void
    {
        foreach (self::PRODUCTION_ROOTS as $manifest) {
            $closure = $this->productionRequireClosure($manifest);

            self::assertNotEmpty($closure, sprintf('%s must have a non-empty waaseyaa closure.', $manifest));
            self::assertNotContains(
                self::HOME_PACKAGE,
                $closure,
                sprintf(
                    '%s reaches %s in its production require closure. ADR-022 D-3.0: the package homing '
                    . 'LocalOperatorPrincipal must stay outside every production closure — either revert the '
                    . 'dependency edit, or relocate the principal to a package that still satisfies the invariant.',
                    $manifest,
                    self::HOME_PACKAGE,
                ),
            );
        }
    }

    /**
     * The control that makes the assertion above meaningful: the same closure
     * computation does reach a package that is genuinely production-present,
     * so an empty or broken traversal cannot pass by accident.
     */
    #[Test]
    public function the_closure_computation_reaches_a_known_production_package(): void
    {
        $closure = $this->productionRequireClosure('composer.json');

        self::assertContains('waaseyaa/ai-tools', $closure, 'ai-tools is a production require of the root manifest');
        self::assertContains('waaseyaa/foundation', $closure);
    }

    /** D-3.0 — the home package is `require-dev` in the root manifest. */
    #[Test]
    public function the_home_package_is_a_development_dependency_of_the_root_manifest(): void
    {
        $manifest = $this->manifest('composer.json');

        self::assertArrayHasKey(self::HOME_PACKAGE, $manifest['require-dev'] ?? []);
        self::assertArrayNotHasKey(self::HOME_PACKAGE, $manifest['require'] ?? []);
    }

    /**
     * R-1 / R-2 / R-3 — no HTTP authentication path, token store, or account
     * loader may name the principal. Containment is the checkable form of a
     * requirement about code that must not exist.
     */
    #[Test]
    public function no_production_source_outside_the_home_directory_references_the_principal(): void
    {
        $offenders = [];
        foreach ($this->productionSources() as $relative => $absolute) {
            if (str_starts_with($relative, self::HOME_DIRECTORY . '/')) {
                continue;
            }
            if (in_array($relative, self::ALLOWED_BOOTSTRAP_REFERENCES, true)) {
                continue;
            }
            $contents = (string) file_get_contents($absolute);
            foreach (['LocalOperatorPrincipal', 'LocalOperatorTransportAttestation', self::SENTINEL] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = sprintf('%s references %s', $relative, $needle);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "ADR-022 D-6 R-1/R-2/R-3: no HTTP auth path, token validator, or account loader may yield a\n"
            . "LocalOperatorPrincipal. Only the local stdio transport bootstrap constructs it, and only through\n"
            . "LocalOperatorTransportAttestation. Offending references:\n" . implode("\n", $offenders),
        );
    }

    /**
     * The allowlist stays honest: every entry must still exist and still
     * actually reference one of the needles. Otherwise a rename or a refactor
     * that stopped needing the reference would leave a stale, silently
     * widening exemption behind — the allowlist would keep passing for a
     * reason that no longer applies.
     */
    #[Test]
    public function every_allowlisted_bootstrap_file_still_exists_and_still_references_the_principal(): void
    {
        foreach (self::ALLOWED_BOOTSTRAP_REFERENCES as $relative) {
            $absolute = $this->root . '/' . $relative;
            self::assertFileExists($absolute, sprintf('Allowlisted file %s no longer exists; remove it from ALLOWED_BOOTSTRAP_REFERENCES.', $relative));

            $contents = (string) file_get_contents($absolute);
            $referencesSomething = false;
            foreach (['LocalOperatorPrincipal', 'LocalOperatorTransportAttestation', self::SENTINEL] as $needle) {
                $referencesSomething = $referencesSomething || str_contains($contents, $needle);
            }
            self::assertTrue(
                $referencesSomething,
                sprintf('Allowlisted file %s no longer references the principal; remove it from ALLOWED_BOOTSTRAP_REFERENCES.', $relative),
            );
        }
    }

    /**
     * The paired control: the scan really does see production sources, so an
     * empty offender list is a finding rather than an artefact of a scan that
     * looked at nothing.
     */
    #[Test]
    public function the_containment_scan_covers_the_authentication_surfaces_it_claims_to(): void
    {
        $sources = $this->productionSources();

        self::assertGreaterThan(1000, count($sources), 'the scan must cover the whole production tree');
        foreach ([
            'packages/user/src/Middleware/SessionMiddleware.php',
            'packages/user/src/DevAdminAccount.php',
            'packages/mcp/src/McpEndpoint.php',
            'packages/ai-agent/src/LocalOperator/LocalOperatorPrincipal.php',
        ] as $expected) {
            self::assertArrayHasKey($expected, $sources, sprintf('%s must be inside the scanned set.', $expected));
        }
    }

    /**
     * The transitive `waaseyaa/*` runtime-require closure of one manifest.
     *
     * @return list<string>
     */
    private function productionRequireClosure(string $manifestPath): array
    {
        $seen = [];
        $queue = array_keys(array_filter(
            $this->manifest($manifestPath)['require'] ?? [],
            static fn(string $_, string $name): bool => str_starts_with($name, 'waaseyaa/'),
            ARRAY_FILTER_USE_BOTH,
        ));

        while ($queue !== []) {
            $package = array_shift($queue);
            if (isset($seen[$package])) {
                continue;
            }
            $seen[$package] = true;

            $directory = substr($package, strlen('waaseyaa/'));
            $childPath = 'packages/' . $directory . '/composer.json';
            if (!is_file($this->root . '/' . $childPath)) {
                continue;
            }
            foreach (array_keys($this->manifest($childPath)['require'] ?? []) as $dependency) {
                if (str_starts_with($dependency, 'waaseyaa/') && !isset($seen[$dependency])) {
                    $queue[] = $dependency;
                }
            }
        }

        return array_keys($seen);
    }

    /** @return array<string, mixed> */
    private function manifest(string $relative): array
    {
        return json_decode(
            (string) file_get_contents($this->root . '/' . $relative),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Every production PHP source: `packages/*` /`src`, plus the application
     * entry point and configuration.
     *
     * @return array<string, string> relative => absolute
     */
    private function productionSources(): array
    {
        $sources = [];
        foreach (new \DirectoryIterator($this->root . '/packages') as $package) {
            if ($package->isDot() || !$package->isDir()) {
                continue;
            }
            $sources += $this->phpFilesUnder($package->getPathname() . '/src');
        }
        $sources += $this->phpFilesUnder($this->root . '/public');
        $sources += $this->phpFilesUnder($this->root . '/config');

        return $sources;
    }

    /** @return array<string, string> */
    private function phpFilesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen(str_replace('\\', '/', $this->root)) + 1);
            if (str_contains($relative, '/vendor/')) {
                continue;
            }
            $files[$relative] = $path;
        }

        return $files;
    }
}
