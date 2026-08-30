<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2649. Release Gate 2 must build the exact candidate into split-shaped
 * Composer artifacts and block tag creation unless packaged create-project
 * acceptance passes.
 *
 * The long half of that proof is a hosted consumer lane
 * (`tests/PackagedForm/check-split-artifact-acceptance`) — minutes of Composer
 * work against a mktemp consumer, so it is not a preflight gate any more than
 * `ci/fresh-install-boot`, `ci/bimaaji-skill-resources`, or the FrankenPHP
 * worker lane are (docs/specs/governed-gates.md §1). This class is the fast
 * repo-state half: it pins the harness's shape, the surface roster, the CI
 * wiring, and — most importantly — the two properties #2649 states outright
 * that a passing run cannot itself demonstrate: that the gate creates no tag,
 * and that post-tag Skeleton Smoke stays alert-only.
 */
#[CoversNothing]
final class SplitArtifactAcceptanceGateTest extends TestCase
{
    private const string HARNESS = 'tests/PackagedForm/check-split-artifact-acceptance';
    private const string ENGINE = 'tests/PackagedForm/split-artifact-acceptance.php';
    private const string PROBE = 'tests/SplitArtifactAcceptance/boot.php';
    private const string FIXTURE = 'tests/PackagedForm/fixtures/split-artifact-acceptance-surfaces.json';

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function the_harness_engine_and_probe_exist_and_are_runnable(): void
    {
        $harness = $this->repoRoot . '/' . self::HARNESS;
        self::assertFileExists($harness);
        self::assertTrue(is_executable($harness), self::HARNESS . ' must be executable.');
        self::assertFileExists($this->repoRoot . '/' . self::ENGINE);
        self::assertFileExists($this->repoRoot . '/' . self::PROBE);
        self::assertFileExists($this->repoRoot . '/' . self::FIXTURE);
    }

    /**
     * The acceptance bullet this gate exists for. A path repository at
     * `symlink: false` copies bytes but still resolves from the checkout; an
     * artifact repository resolves from a distributable archive, which is what
     * the split mirror actually publishes.
     */
    #[Test]
    public function installation_resolves_waaseyaa_from_a_local_artifact_repository(): void
    {
        $harness = $this->read(self::HARNESS);

        self::assertStringContainsString('"type" => "artifact"', $harness);
        self::assertStringContainsString('"only" => ["waaseyaa/*"]', $harness);
        self::assertStringContainsString('composer_bin" create-project waaseyaa/waaseyaa', $harness);

        // No path repository, no VCS repository, no symlink option anywhere:
        // those are the three ways a consumer proof quietly stops being one.
        // Comments are stripped first — the prose explaining WHY the harness
        // avoids symlinks must not itself trip the guard.
        $code = self::codeOnly($harness, '#');
        foreach (['"type" => "path"', '"type":"path"', '"type" => "vcs"', 'symlink'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                'The split-artifact harness must never resolve waaseyaa/* from the checkout.',
            );
        }

        // git archive, not a directory copy: `export-ignore` and archive
        // generation are exactly the layer #2543 found unproven.
        self::assertStringContainsString('git -C %s archive --format=zip', $this->read(self::ENGINE));
    }

    #[Test]
    public function the_gate_creates_no_tag_and_publishes_nothing(): void
    {
        foreach ([self::HARNESS, self::ENGINE, self::PROBE] as $path) {
            $source = self::codeOnly($this->read($path), $path === self::HARNESS ? '#' : '//');
            foreach (['git tag', 'git push', 'packagist.org'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    sprintf('%s must not tag, push, or contact a registry (#2649).', $path),
                );
            }
        }
    }

    #[Test]
    public function the_surface_roster_covers_every_acceptance_bullet_and_names_its_blockers(): void
    {
        $fixture = $this->fixture();

        $ids = array_column($fixture['surfaces'], 'id');
        sort($ids);
        self::assertSame(
            ['bootstrap', 'composition', 'exported-files', 'no-dev-exclusion', 'stdio-initialization'],
            $ids,
            "#2649's acceptance names five surfaces. All five stay in the roster; the ones that cannot "
            . 'exist yet are recorded as reserved rather than dropped.',
        );

        foreach ($fixture['surfaces'] as $surface) {
            $id = (string) $surface['id'];
            if ($surface['status'] === 'live') {
                self::assertIsString($surface['implementation'], "Live surface {$id} must name an implementation.");
                self::assertIsString($surface['marker'], "Live surface {$id} must name a marker.");
                $implementation = $this->read((string) $surface['implementation']);
                self::assertStringContainsString(
                    (string) $surface['marker'],
                    $implementation,
                    sprintf('Live surface %s lost its implementation in %s.', $id, (string) $surface['implementation']),
                );
                self::assertNull($surface['blocked_by'], "Live surface {$id} cannot also be blocked.");
                continue;
            }

            self::assertSame('reserved', $surface['status'], "Unknown status for surface {$id}.");
            self::assertNull($surface['implementation'], "Reserved surface {$id} must not claim an implementation.");
            self::assertMatchesRegularExpression(
                '/^#\d+$/',
                (string) $surface['blocked_by'],
                "Reserved surface {$id} must name the issue that unblocks it.",
            );
        }
    }

    /**
     * #2649's first acceptance bullet, as data. The development metapackage is
     * reserved because #2655 DEPENDS ON #2649: requiring it here would make the
     * pair circular and neither could land.
     */
    #[Test]
    public function the_artifact_repository_represents_every_named_member(): void
    {
        $fixture = $this->fixture();

        $ids = array_column($fixture['artifact_repository_members'], 'id');
        sort($ids);
        self::assertSame(
            ['development-metapackage', 'root-framework-dist', 'skeleton', 'split-packages'],
            $ids,
        );

        $byId = array_column($fixture['artifact_repository_members'], null, 'id');
        self::assertSame('reserved', $byId['development-metapackage']['status']);
        self::assertSame('#2655', $byId['development-metapackage']['blocked_by']);
        self::assertSame('waaseyaa/ai-development', $byId['development-metapackage']['package']);

        // The reserved member is not a comment: the engine fails closed the
        // moment the package is sealed, and a seeded negative control proves
        // that it does. This assertion keeps the two ends tied together.
        $engine = $this->read(self::ENGINE);
        self::assertStringContainsString("in_array('waaseyaa/ai-development', \$sealedNames, true)", $engine);
        self::assertStringContainsString("\$controls['reserved-metapackage-appeared']", $engine);

        self::assertDirectoryDoesNotExist(
            $this->repoRoot . '/packages/ai-development',
            'waaseyaa/ai-development now exists (#2655): flip the reserved member and surface to live.',
        );
    }

    /**
     * The evidence standard. A gate that has only ever passed is not evidence,
     * so the harness re-runs every live assertion against deliberately
     * corrupted overlays on EVERY run and fails if any survives.
     */
    #[Test]
    public function every_run_proves_the_harness_can_fail(): void
    {
        self::assertStringContainsString(
            'php "$engine" self-test "$seal" "$consumer" "$nodev"',
            $this->read(self::HARNESS),
        );

        $engine = $this->read(self::ENGINE);
        foreach ([
            'pinned-fixture-removed',
            'exported-file-removed',
            'exported-byte-drift',
            'admin-dist-byte-tampered',
            'composition-package-dropped',
            'composition-foreign-version',
            'dist-outside-artifact-repo',
            'reserved-metapackage-appeared',
            'source-symlink-installed',
            'dev-package-retained',
            'dev-flag-retained',
        ] as $control) {
            self::assertStringContainsString(
                "\$controls['{$control}']",
                $engine,
                sprintf('Negative control %s was removed; the harness stops proving it has teeth.', $control),
            );
        }

        self::assertStringContainsString(
            'The acceptance harness did NOT fail on seeded corruption',
            $engine,
        );
    }

    /**
     * #2543's manifests are a PINNED FIXTURE. The gate consumes them through
     * the documented consumer procedure; it must never regenerate them, or it
     * would be checking its own arithmetic instead of what a consumer receives.
     */
    #[Test]
    public function the_admin_surface_manifests_are_consumed_not_regenerated(): void
    {
        $engine = $this->read(self::ENGINE);

        self::assertStringContainsString("'dist.manifest.json'", $engine);
        self::assertStringContainsString("'dist.markers.json'", $engine);
        self::assertStringContainsString('function assert_admin_surface_pinned_fixture(', $engine);
        self::assertStringContainsString('identityDigest', $engine);
        self::assertStringContainsString('treeDigest', $engine);

        foreach (['build-admin-dist', 'admin-dist-acceptance'] as $producer) {
            self::assertStringNotContainsString(
                $producer,
                self::codeOnly($engine, '//'),
                'The acceptance engine must consume the #2543 manifests, never produce them.',
            );
        }

        self::assertFileExists($this->repoRoot . '/packages/admin-surface/dist.manifest.json');
        self::assertFileExists($this->repoRoot . '/packages/admin-surface/dist.markers.json');
    }

    #[Test]
    public function the_pull_request_pipeline_runs_the_gate_on_the_committed_tree(): void
    {
        $workflow = $this->read('.github/workflows/ci.yml');

        self::assertStringContainsString('name: ci/split-artifact-acceptance', $workflow);
        self::assertStringContainsString('bash ' . self::HARNESS, $workflow);

        // --allow-dirty exists for local iteration only. In CI it would seal
        // bytes that are not the bytes under review. Comments are stripped so
        // the job's own explanation of that rule does not trip the guard.
        self::assertStringNotContainsString('--allow-dirty', self::codeOnly($workflow, '#'));
    }

    /**
     * "This gate does not create a tag or authorize a release" (#2649). It
     * blocks tag creation the way every other ci.yml job does: release-cut.yml
     * refuses to proceed until the whole workflow is green on the exact commit,
     * and that wait happens before the release-identity token is minted.
     */
    #[Test]
    public function the_release_cut_cannot_tag_before_this_workflow_is_green(): void
    {
        $releaseCut = $this->read('.github/workflows/release-cut.yml');

        $gate = strpos($releaseCut, 'Gate 2/5: require green CI on the exact commit being tagged');
        self::assertIsInt($gate);
        self::assertStringContainsString('bash bin/wait-for-green-ci "$RELEASE_SHA" 2700', $releaseCut);

        foreach (['Mint the release-identity token', 'Tag the gated commit and fast-forward main (atomic)'] as $irreversible) {
            $position = strpos($releaseCut, $irreversible);
            self::assertIsInt($position, sprintf('release-cut.yml must still contain "%s".', $irreversible));
            self::assertLessThan($position, $gate);
        }
    }

    /**
     * "Post-tag skeleton-smoke remains alert-only for registry propagation"
     * (#2649). It resolves genuinely published bytes, so it sees a property
     * this gate structurally cannot — and it runs after the tag it would have
     * to gate, so it can never be made blocking without becoming a lie.
     */
    #[Test]
    public function post_tag_skeleton_smoke_remains_alert_only(): void
    {
        $smoke = $this->read('.github/workflows/skeleton-smoke.yml');

        self::assertStringContainsString('Alert-only — does not gate the release tag.', $smoke);
        self::assertStringContainsString('workflow_run:', $smoke);

        foreach (['.github/workflows/ci.yml', '.github/workflows/release-cut.yml'] as $blocking) {
            self::assertStringNotContainsString(
                'skeleton-smoke',
                $this->read($blocking),
                sprintf('%s must not depend on the post-tag alert-only smoke.', $blocking),
            );
        }
    }

    /**
     * Strip comment lines so an absence guard tests the CODE, not the prose
     * that explains why the code avoids something. Without this, documenting
     * "no symlink back into the checkout" would fail a no-symlink assertion —
     * which pressures the next author to delete the explanation.
     */
    private static function codeOnly(string $source, string $marker): string
    {
        $kept = [];
        foreach (explode("\n", $source) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, $marker)) {
                continue;
            }
            if ($marker === '//' && (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*'))) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->read(self::FIXTURE), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function read(string $relative): string
    {
        $path = $this->repoRoot . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
