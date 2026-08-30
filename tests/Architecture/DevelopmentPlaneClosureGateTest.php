<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Testing\Filesystem\TemporaryDirectory;

/**
 * The seeded positive control for CP009, the development-plane containment
 * rule in `bin/check-composer-policy` (ADR-022 D-1.2 / D-3.0 / D-10; #2655's
 * added acceptance criterion).
 *
 * D-10 leaves the host of this gate open and names two candidates. It is
 * hosted in `check-composer-policy` because the invariant is a *manifest
 * closure* property and not a layer property: an edge from `waaseyaa/full` to
 * `waaseyaa/ai-agent` breaks no layer rule at all, and three of the four
 * production roots are metapackages, which `check-package-layers` skips before
 * it looks at anything else. `check-composer-policy` already reads the root
 * manifest and every packages/* manifest, and already owns a rule of exactly
 * this shape (CP004: core's runtime surface must stay install-safe).
 *
 * **A closure gate never observed failing proves nothing.** So every test
 * below plants a real dependency edge into a disposable fixture root and
 * asserts CP009 goes red, with a green control first so a gate failing for an
 * unrelated reason cannot masquerade as working. The fixture is written under
 * the system temp directory and the gate is pointed at it with `ROOT_DIR`; the
 * repository tree is never written to.
 *
 * `tests/Architecture/LocalOperatorContainmentTest.php` binds the same
 * invariant in-process against the *live* manifests. It is deliberately not
 * duplicated here: that test answers "is the shipped tree contained today?",
 * and this one answers "would we find out if it stopped being?".
 */
#[CoversNothing]
final class DevelopmentPlaneClosureGateTest extends TestCase
{
    private string $root;
    private TemporaryDirectory $temporary;
    private string $sandbox;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->temporary = new TemporaryDirectory('waaseyaa-development-plane-gate-');
        $this->sandbox = $this->temporary->path();

        foreach ($this->fixtureManifests() as $relative => $manifest) {
            $this->writeManifest($relative, $manifest);
        }
    }

    protected function tearDown(): void
    {
        $this->temporary->remove();
    }

    /**
     * Green control. The fixture reproduces the repository's own shape — the
     * development metapackage requires the home package, and nothing
     * production-side reaches either — so a red result below is caused by the
     * seeding and nothing else.
     */
    #[Test]
    public function the_gate_passes_on_a_contained_fixture(): void
    {
        [$exitCode, $output] = $this->runGate();

        self::assertSame(0, $exitCode, $output);
        self::assertStringNotContainsString('CP009', $output);
    }

    /**
     * The green control above is only meaningful if the fixture really does
     * contain the edge the gate must not follow. This pins that: the
     * development metapackage requires the home package, and the gate is
     * nonetheless green, because production closures are computed from the
     * four production roots and the dev metapackage is not one of them.
     */
    #[Test]
    public function the_development_metapackages_own_graph_does_not_trip_the_gate(): void
    {
        $manifest = $this->readManifest('packages/ai-development/composer.json');
        self::assertArrayHasKey('waaseyaa/ai-agent', $manifest['require']);

        // Widen its graph further: still not a production root, still invisible.
        $manifest['require']['waaseyaa/testing'] = '^0.1.0-alpha.299';
        $this->writeManifest('packages/ai-development/composer.json', $manifest);

        [$exitCode, $output] = $this->runGate();

        self::assertSame(0, $exitCode, $output);
    }

    /** D-3.0 — the blunt form: a production root requires the home package outright. */
    #[Test]
    public function a_direct_require_edge_into_the_home_package_fails(): void
    {
        $this->seedRequire('composer.json', 'waaseyaa/ai-agent', 'self.version');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('FAIL [CP009] composer.json', $output);
        self::assertStringContainsString('composer.json -> waaseyaa/ai-agent', $output);
        self::assertStringContainsString('LocalOperatorPrincipal', $output);
    }

    /**
     * The exact hazard D-10 names: "a routine dependency edit is enough to
     * void it silently — the edit would look like adding a require, not like
     * relocating a security-sensitive class into production". Nobody touches a
     * production root here; a production-present library grows one dependency.
     */
    #[Test]
    public function a_transitive_require_edge_into_the_home_package_fails(): void
    {
        $this->seedRequire('packages/ai-tools/composer.json', 'waaseyaa/ai-agent', '^0.1.0-alpha.299');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString(
            'composer.json -> waaseyaa/ai-tools -> waaseyaa/ai-agent',
            $output,
            'CP009 must name the edit that caused the failure, not only its consequence.',
        );
        // Every production root that reaches it is reported, not just the first.
        self::assertStringContainsString('FAIL [CP009] packages/full/composer.json', $output);
    }

    /** D-1.2 — the metapackage itself may not be required by a production metapackage. */
    #[Test]
    public function requiring_the_development_metapackage_from_a_production_metapackage_fails(): void
    {
        $this->seedRequire('packages/full/composer.json', 'waaseyaa/ai-development', '^0.1.0-alpha.299');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('waaseyaa/ai-development', $output);
        self::assertStringContainsString('FAIL [CP009] packages/full/composer.json', $output);
    }

    /**
     * D-1.3 — the skeleton installs the plane under `require-dev` only, so
     * that `composer install --no-dev` removes it and everything it pulls.
     * Promoting it to `require` is the one-line edit that would void that.
     */
    #[Test]
    public function promoting_the_development_metapackage_out_of_require_dev_fails(): void
    {
        $skeleton = $this->readManifest('skeleton/composer.json');
        unset($skeleton['require-dev']['waaseyaa/ai-development']);
        $skeleton['require']['waaseyaa/ai-development'] = '^0.1.0-alpha.299';
        $this->writeManifest('skeleton/composer.json', $skeleton);

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('FAIL [CP009] skeleton/composer.json', $output);
        self::assertStringContainsString('move it to require-dev', $output);
    }

    /**
     * The control that stops a truncated traversal from passing as
     * containment. A closure that stops walking would satisfy "the home
     * package is not in it" trivially, so a manifest that declares a
     * `waaseyaa/*` graph must reach `waaseyaa/foundation` through it.
     *
     * The seed severs the chain one hop below `packages/cms`, which reaches
     * foundation only transitively — so this fires on the *walk*, not on a
     * direct require the root happens to declare.
     */
    #[Test]
    public function a_truncated_closure_cannot_pass_as_containment(): void
    {
        $core = $this->readManifest('packages/core/composer.json');
        $core['require'] = [];
        $core['repositories'] = [];
        $this->writeManifest('packages/core/composer.json', $core);

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('FAIL [CP009] packages/cms/composer.json', $output);
        self::assertStringContainsString('waaseyaa/foundation', $output);
    }

    /**
     * The paired restraint. A manifest with no `waaseyaa/*` require has an
     * empty closure because there is nothing to contain, not because the walk
     * broke — and this script accepts a `ROOT_DIR` override, so foreign roots
     * of exactly that shape are real (the fixture roots in
     * `tests/Integration/Policy/CheckComposerPolicyTest.php`). Failing them
     * would make CP009 an obstacle rather than a control.
     */
    #[Test]
    public function a_manifest_with_no_internal_requires_is_not_treated_as_a_broken_walk(): void
    {
        foreach ($this->fixtureManifests() as $relative => $_) {
            if (str_starts_with($relative, 'packages/')) {
                @unlink($this->sandbox . '/' . $relative);
            }
        }
        $this->writeManifest('composer.json', [
            'name' => 'waaseyaa/framework',
            'require' => ['php' => '>=8.5'],
            'config' => ['sort-packages' => true],
        ]);
        @unlink($this->sandbox . '/skeleton/composer.json');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(0, $exitCode, $output);
        self::assertStringNotContainsString('CP009', $output);
    }

    /**
     * The gate is only as wide as its root list. Pinning it here means
     * dropping a production manifest from CP009 is a visible edit rather than
     * a silent narrowing of what containment means.
     */
    #[Test]
    public function the_gate_covers_all_four_production_roots(): void
    {
        $gate = (string) file_get_contents($this->root . '/bin/check-composer-policy');

        self::assertStringContainsString("'development_plane_containment' => 'CP009'", $gate);
        foreach ([
            "'composer.json'",
            "'packages/core/composer.json'",
            "'packages/cms/composer.json'",
            "'packages/full/composer.json'",
        ] as $rootManifest) {
            self::assertStringContainsString($rootManifest, $gate);
        }
        foreach (['waaseyaa/ai-development', 'waaseyaa/ai-agent'] as $contained) {
            self::assertStringContainsString("'{$contained}' =>", $gate);
        }
    }

    /**
     * The fixture root: the repository's containment shape in miniature.
     *
     * `waaseyaa/ai-tools` and `waaseyaa/foundation` are production-present, so
     * the traversal has something real to find; `waaseyaa/ai-agent` and
     * `waaseyaa/testing` are reachable only from the development metapackage,
     * which is reachable only from the skeleton's `require-dev`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fixtureManifests(): array
    {
        return [
            'composer.json' => [
                'name' => 'waaseyaa/framework',
                'type' => 'metapackage',
                'require' => [
                    'waaseyaa/ai-tools' => 'self.version',
                    'waaseyaa/foundation' => 'self.version',
                ],
                'require-dev' => ['waaseyaa/ai-agent' => 'self.version'],
                'config' => ['sort-packages' => true],
            ],
            'packages/foundation/composer.json' => $this->package('waaseyaa/foundation', 'library', []),
            'packages/ai-tools/composer.json' => $this->package('waaseyaa/ai-tools', 'library', ['foundation']),
            'packages/ai-agent/composer.json' => $this->package('waaseyaa/ai-agent', 'library', ['ai-tools']),
            'packages/testing/composer.json' => $this->package('waaseyaa/testing', 'library', ['foundation']),
            'packages/ai-development/composer.json' => $this->package(
                'waaseyaa/ai-development',
                'metapackage',
                ['ai-agent', 'testing'],
            ),
            'packages/core/composer.json' => $this->package('waaseyaa/core', 'metapackage', ['foundation']),
            'packages/cms/composer.json' => $this->package('waaseyaa/cms', 'metapackage', ['core']),
            'packages/full/composer.json' => $this->package('waaseyaa/full', 'metapackage', ['cms', 'ai-tools']),
            'skeleton/composer.json' => [
                'name' => 'waaseyaa/waaseyaa',
                'type' => 'project',
                'require' => ['waaseyaa/framework' => '^0.1.0-alpha.299'],
                'require-dev' => ['waaseyaa/ai-development' => '^0.1.0-alpha.299'],
                'config' => ['sort-packages' => true],
            ],
        ];
    }

    /**
     * @param list<string> $dependencies package directory names
     *
     * @return array<string, mixed>
     */
    private function package(string $name, string $type, array $dependencies): array
    {
        $require = [];
        $repositories = [];
        foreach ($dependencies as $dependency) {
            $require['waaseyaa/' . $dependency] = '^0.1.0-alpha.299';
            $repositories[] = ['type' => 'path', 'url' => '../' . $dependency];
        }

        return [
            'name' => $name,
            'type' => $type,
            'require' => $require,
            'config' => ['sort-packages' => true],
            'repositories' => $repositories,
        ];
    }

    private function seedRequire(string $relative, string $package, string $constraint): void
    {
        $manifest = $this->readManifest($relative);
        $manifest['require'][$package] = $constraint;
        if ($relative !== 'composer.json') {
            $manifest['repositories'][] = [
                'type' => 'path',
                'url' => '../' . substr($package, strlen('waaseyaa/')),
            ];
        }
        $this->writeManifest($relative, $manifest);
    }

    /** @return array<string, mixed> */
    private function readManifest(string $relative): array
    {
        return json_decode(
            (string) file_get_contents($this->sandbox . '/' . $relative),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(string $relative, array $manifest): void
    {
        $path = $this->sandbox . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }
        file_put_contents(
            $path,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /** @return array{0: int, 1: string} */
    private function runGate(): array
    {
        $command = sprintf(
            'ROOT_DIR=%s %s %s 2>&1',
            escapeshellarg($this->sandbox),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/bin/check-composer-policy'),
        );

        exec($command, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
