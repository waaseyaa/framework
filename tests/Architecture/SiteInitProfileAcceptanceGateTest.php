<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * #2442's installed-consumer proof takes long enough to belong outside ordinary
 * PHPUnit. This fast gate keeps its exact-candidate shape, fixture wiring, and
 * discriminating controls reviewable without executing Composer.
 */
#[CoversNothing]
final class SiteInitProfileAcceptanceGateTest extends TestCase
{
    private const string HARNESS = 'tests/PackagedForm/check-site-init-profile-acceptance';
    private const string SEED = 'tests/PackagedForm/fixtures/site-init-profile-seed.yaml';
    private const string PROBE = 'tests/PackagedForm/fixtures/site-init-profile-probe.php';
    private const string WORKFLOW = '.github/workflows/ci.yml';

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function the_packaged_proof_files_are_wired_without_running_the_proof(): void
    {
        self::assertFileExists($this->path(self::HARNESS));
        self::assertTrue(is_executable($this->path(self::HARNESS)), self::HARNESS . ' must be executable.');
        self::assertFileExists($this->path(self::SEED));
        self::assertFileExists($this->path(self::PROBE));

        $harness = $this->read(self::HARNESS);
        self::assertStringContainsString(self::SEED, $harness);
        self::assertStringContainsString(self::PROBE, $harness);
    }

    #[Test]
    public function the_seed_is_the_closed_identity_and_content_type_input(): void
    {
        $seed = Yaml::parseFile($this->path(self::SEED));
        self::assertIsArray($seed);
        self::assertSame(['schema', 'version', 'application', 'content_types'], array_keys($seed));
        self::assertSame('waaseyaa.site-seed', $seed['schema']);
        self::assertSame(1, $seed['version']);
        self::assertSame(['id', 'name', 'canonical_origin'], array_keys($seed['application']));
        self::assertSame(['config_key' => 'APP_ORIGIN'], $seed['application']['canonical_origin']);
        self::assertNotSame([], $seed['content_types']);
    }

    #[Test]
    public function the_harness_installs_copied_exact_candidate_bytes_from_one_skeleton(): void
    {
        $harness = $this->read(self::HARNESS);

        self::assertStringContainsString('"$root/bin/git" -C "$root" archive --format=tar HEAD', $harness);
        self::assertStringContainsString('cp -R "$source_root/skeleton/." "$base/"', $harness);
        self::assertStringContainsString('"options" => ["symlink" => false]', $harness);
        self::assertStringContainsString('assert_install_provenance "$base"', $harness);
        self::assertStringContainsString('$package["dist"]["type"]', $harness);
        self::assertStringContainsString('does not resolve into the candidate archive', $harness);
        self::assertStringContainsString('vendor/waaseyaa/cli', $harness);
        self::assertStringContainsString('vendor/waaseyaa/site-contract', $harness);
        self::assertStringContainsString('rm -rf -- "$source_root"', $harness);
        self::assertStringNotContainsString('/home/', $harness);
        self::assertStringNotContainsString('repo.packagist.org', $harness);
    }

    #[Test]
    public function both_declarative_profiles_and_the_residual_activation_boundary_are_explicit(): void
    {
        $harness = $this->read(self::HARNESS);
        $probe = $this->read(self::PROBE);

        self::assertStringContainsString('run_site_init "$minimal" minimal', $harness);
        self::assertStringContainsString('run_site_init "$editorial" editorial', $harness);
        self::assertStringContainsString('"--preset=$profile"', $harness);
        self::assertStringContainsString("'governed_authoring'", $probe);
        self::assertStringContainsString("'subscription'", $probe);
        self::assertStringContainsString("'not_needed'", $probe);
        self::assertStringContainsString("'active'", $probe);
        self::assertStringContainsString('composer.governed-authoring-recipe.json', $probe);
        self::assertStringContainsString('composer.subscription-recipe.json', $probe);

        // #2857 remains the activation boundary. This slice proves generated
        // declarations and bytes; it must not boot an authoring provider or
        // manufacture an authenticated account to imply activation.
        self::assertStringNotContainsString('waaseyaa install:init', $harness);
        self::assertStringNotContainsString('waaseyaa user:create', $harness);
        self::assertStringNotContainsString('HttpKernel', $probe);
        self::assertStringNotContainsString('PackageManifestCompiler', $probe);
    }

    #[Test]
    public function the_proof_contains_non_vacuous_safety_and_determinism_controls(): void
    {
        $harness = $this->read(self::HARNESS);

        foreach ([
            '--dry-run',
            'tree-digest',
            'compare-governed',
            'Initialized 0 generated artifacts.',
            'Refusing to overwrite unowned artifact',
            'Generated artifact was edited outside an extension region',
            'collision_exit',
            'tamper_exit',
        ] as $control) {
            self::assertStringContainsString($control, $harness, "Missing packaged control: {$control}");
        }

        self::assertStringContainsString('cmp -- "$minimal/composer.lock" "$editorial_a/composer.lock"', $harness);
        self::assertStringContainsString('cmp -- "$editorial_a/composer.lock" "$editorial_b/composer.lock"', $harness);
        self::assertStringContainsString('cmp -- "$editorial_a/seed.yaml" "$editorial_b/seed.yaml"', $harness);
    }

    #[Test]
    public function pull_request_ci_runs_the_exact_candidate_proof_without_a_success_escape(): void
    {
        $workflow = $this->read(self::WORKFLOW);
        self::assertSame(1, preg_match(
            '/^  site-init-profile-acceptance:\R(?<job>.*?)(?=^  [a-z0-9_-]+:\R|\z)/ms',
            $workflow,
            $matches,
        ));
        $job = $matches['job'];

        self::assertStringContainsString('name: ci/site-init-profile-acceptance', $job);
        self::assertStringContainsString(
            'ref: ${{ inputs.sha || github.event.pull_request.head.sha || github.sha }}',
            $job,
        );
        self::assertStringContainsString(
            'run: bash tests/PackagedForm/check-site-init-profile-acceptance',
            $job,
        );
        self::assertStringNotContainsString('continue-on-error', $job);
        self::assertStringNotContainsString('|| true', $job);
    }

    private function path(string $relative): string
    {
        return $this->root . '/' . $relative;
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents($this->path($relative));
        self::assertIsString($contents);

        return $contents;
    }
}
