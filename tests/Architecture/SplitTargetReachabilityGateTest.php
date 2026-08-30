<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A release pushes every `remote:` target in the split.yml matrix, and a
 * release tag is immutable — recovery is forward-only (VERSIONING.md §8). So an
 * unreachable split target must be found BEFORE the tag exists, not when the
 * matrix leg fails to push afterwards.
 *
 * `bin/check-release-require-parity` derived its probe roster from runtime
 * `require` edges (RR001), which is structurally incapable of seeing a package
 * no first-party manifest requires. `waaseyaa/ai-development` is exactly that
 * by design: ADR-022 D-1.2 keeps it out of every production `require` and
 * D-1.3 puts it in the skeleton's `require-dev` only. It therefore never
 * reached the existence probe, and the release-cut preflight would have passed
 * and tagged before the split failed.
 *
 * RR002 fixes the input rather than the symptom: the matrix is the
 * authoritative roster of what a release pushes, so the matrix is what gets
 * probed.
 *
 * This class is the offline half — it pins where the roster comes from and
 * that the gate runs before anything irreversible. The live both-directions
 * evidence needs the network and belongs to the gate itself.
 */
#[CoversNothing]
final class SplitTargetReachabilityGateTest extends TestCase
{
    private const string GATE = 'bin/check-release-require-parity';
    private const string RELEASE_CUT = '.github/workflows/release-cut.yml';
    private const string SPLIT = '.github/workflows/split.yml';

    private string $repoRoot;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $directory) {
            exec(sprintf('rm -rf %s', escapeshellarg($directory)));
        }
        $this->cleanup = [];
    }

    /**
     * The whole point of the fix: the roster is the split matrix, not the
     * require graph. If this assertion is ever relaxed, the gate goes back to
     * being blind to precisely the packages that need it most.
     */
    #[Test]
    public function the_reachability_roster_is_derived_from_the_split_matrix(): void
    {
        $gate = $this->read(self::GATE);

        self::assertStringContainsString('FAIL [RR002]', $gate);
        self::assertStringContainsString('for repo in sorted(matrix_remotes):', $gate);
        self::assertStringContainsString('unreachable_targets', $gate);
    }

    /** RR001 keeps its own roster and its own diagnostics; RR002 is additive. */
    #[Test]
    public function the_require_edge_check_is_kept_alongside_it(): void
    {
        $gate = $this->read(self::GATE);

        self::assertStringContainsString('FAIL [RR001]', $gate);
        self::assertStringContainsString('for pkg in sorted(required):', $gate);
    }

    /**
     * A gate that runs after the tag cannot gate the tag. Ordering is the
     * property that makes this a pre-tag check rather than a post-mortem.
     */
    #[Test]
    public function the_gate_runs_before_anything_irreversible(): void
    {
        $releaseCut = $this->read(self::RELEASE_CUT);

        $gate = strpos($releaseCut, 'bash bin/check-release-require-parity');
        self::assertIsInt($gate, 'release-cut.yml must run the parity gate.');

        foreach ([
            'Mint the release-identity token',
            'Tag the gated commit and fast-forward main (atomic)',
        ] as $irreversible) {
            $position = strpos($releaseCut, $irreversible);
            self::assertIsInt($position, sprintf('release-cut.yml must still contain "%s".', $irreversible));
            self::assertLessThan(
                $position,
                $gate,
                sprintf('The split-target reachability gate must run before "%s".', $irreversible),
            );
        }
    }

    /**
     * The development metapackage is a declared split target, which is what
     * puts it inside RR002's roster. Without the matrix row it would ship
     * nowhere; with it, RR002 owns proving the target exists.
     */
    #[Test]
    public function the_development_metapackage_is_a_declared_split_target(): void
    {
        self::assertStringContainsString(
            "- { local: 'packages/ai-development', remote: 'ai-development' }",
            $this->read(self::SPLIT),
        );
        self::assertFileExists($this->repoRoot . '/packages/ai-development/composer.json');
    }

    /**
     * Two different questions, deliberately kept apart: *can we publish it?*
     * (this gate, retired when the repository is provisioned) and *can a
     * consumer resolve it before it is published?*
     * (`support/skeleton-unpublished-packages.json`, retired when Packagist
     * serves it). Collapsing them would tie the release gate's correctness to
     * a consumer-lane workaround, and would let removing one silently disable
     * the other.
     */
    #[Test]
    public function the_release_gate_does_not_consult_the_consumer_lane_roster(): void
    {
        self::assertStringNotContainsString(
            'skeleton-unpublished-packages',
            $this->read(self::GATE),
            'Repository existence and consumer resolvability are separate mechanisms with separate '
            . 'retirement conditions; the release gate must not depend on the consumer-lane roster.',
        );
        self::assertStringNotContainsString(
            'check-release-require-parity',
            $this->read('support/skeleton-unpublished-packages.json'),
        );
    }

    /**
     * The failing direction, deterministically.
     *
     * A declared target with no repository behind it must fail. The fixture
     * creates real bare repositories for the targets that exist and simply
     * does not create the one that does not, probing them through
     * `REPO_REMOTE_TEMPLATE`, so `git ls-remote` remains the actual mechanism
     * rather than a stub — and the result does not depend on the live state of
     * any GitHub repository. An earlier version of this evidence relied on
     * `waaseyaa/ai-development` being absent from the org; that repository now
     * exists, which is exactly why a test must never be written that way.
     */
    #[Test]
    public function a_declared_target_with_no_repository_fails_the_gate(): void
    {
        $fixture = $this->fixture(['alpha', 'beta'], provisioned: ['alpha']);

        [$exitCode, $output] = $this->runGate($fixture);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('FAIL [RR002]', $output);
        self::assertStringContainsString('beta', $output);
        self::assertStringNotContainsString('/alpha ', $output, 'A provisioned target must not be reported.');
    }

    /** The passing direction: provision the missing target and the gate goes green. */
    #[Test]
    public function the_gate_passes_once_every_declared_target_is_provisioned(): void
    {
        $fixture = $this->fixture(['alpha', 'beta'], provisioned: ['alpha', 'beta']);

        [$exitCode, $output] = $this->runGate($fixture);

        self::assertSame(0, $exitCode, $output);
        self::assertStringNotContainsString('RR002', $output);
    }

    /**
     * A freshly created split target has no refs yet. It still exists, and the
     * split force-pushes into it, so an EMPTY repository must count as
     * reachable — which is the state `waaseyaa/ai-development` is in right now.
     * The bare repositories this fixture creates carry no commits, so the
     * passing direction above already exercises that; this pins the reason.
     */
    #[Test]
    public function an_empty_repository_counts_as_provisioned(): void
    {
        $fixture = $this->fixture(['alpha'], provisioned: ['alpha']);

        self::assertSame(
            [],
            glob($fixture . '/remotes/alpha/refs/heads/*') ?: [],
            'The fixture repository must be empty for this assertion to mean anything.',
        );

        [$exitCode, $output] = $this->runGate($fixture);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * The roster is the matrix, so a target nothing requires is still probed.
     * This is the shape of the original defect: `waaseyaa/ai-development` is in
     * no first-party `require`, and the fixture's manifests require nothing at
     * all, yet the unprovisioned target still fails.
     */
    #[Test]
    public function a_target_no_manifest_requires_is_still_probed(): void
    {
        $fixture = $this->fixture(['orphan'], provisioned: []);

        $internal = array_filter(
            array_keys($this->json($fixture . '/composer.json')['require'] ?? []),
            static fn(string $name): bool => str_starts_with($name, 'waaseyaa/'),
        );
        self::assertSame(
            [],
            $internal,
            'The fixture root manifest must declare no waaseyaa/* require, so RR001 has an empty roster '
            . 'and only the split matrix can supply a target to probe.',
        );

        [$exitCode, $output] = $this->runGate($fixture);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('orphan', $output);
    }

    /**
     * The concealment shape, which is the whole point of this fixture.
     *
     * The roster is parsed with a regex (see the gate's header for why a YAML
     * library is not available in either lane that runs it), and a regex
     * recognises exactly one spelling. A matrix that mixes spellings is what
     * makes the defect dangerous rather than obvious: the single-quoted entry
     * parses, so the roster is non-empty, so the blind-pass guard is satisfied
     * — and the double-quoted and bare entries vanish unseen. Neither missing
     * target is provisioned here, so a gate that dropped them would report
     * success on a matrix with two unreachable targets.
     *
     * Both unparseable rows must be named, not just the first: stopping at one
     * would leave the second concealed on the next run.
     */
    #[Test]
    public function mixed_scalar_formats_are_refused_rather_than_silently_dropped(): void
    {
        $fixture = $this->fixture(
            ['alpha', 'beta', 'gamma'],
            provisioned: ['alpha'],
            styles: ['alpha' => 'single', 'beta' => 'double', 'gamma' => 'bare'],
        );

        [$exitCode, $output] = $this->runGate($fixture);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('FAIL [RR003]', $output);
        self::assertStringContainsString('remote: "beta"', $output);
        self::assertStringContainsString('remote: gamma', $output);
        self::assertStringNotContainsString(
            'Require parity verified',
            $output,
            'The parseable survivor must not be allowed to report success for the whole matrix.',
        );
    }

    /**
     * The same mixed matrix, fully provisioned. It must STILL refuse: the
     * objection is that the gate cannot read the rows, not that those
     * particular repositories are missing. Passing here would mean the gate
     * had gone back to probing only what it happens to understand.
     */
    #[Test]
    public function unreadable_rows_are_refused_even_when_every_repository_exists(): void
    {
        $fixture = $this->fixture(
            ['alpha', 'beta', 'gamma'],
            provisioned: ['alpha', 'beta', 'gamma'],
            styles: ['alpha' => 'single', 'beta' => 'double', 'gamma' => 'bare'],
        );

        [$exitCode, $output] = $this->runGate($fixture);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('FAIL [RR003]', $output);
    }

    /**
     * Structure the gate cannot understand must stop it, not shrink it. The
     * total-emptiness guard (RR000) is a floor, not a substitute: these are
     * the partial and malformed shapes it cannot see.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function malformedMatrices(): iterable
    {
        yield 'no matrix block at all' => ["jobs:\n  split:\n    steps:\n      - run: true\n", 'RR004'];
        yield 'matrix present but empty' => ["        package:\n", 'RR004'];
        yield 'matrix with no list entries' => ["        package: {}\n", 'RR004'];
        yield 'entry without a remote key' => ["        package:\n          - { local: 'packages/alpha' }\n", 'RR004'];
    }

    #[Test]
    #[DataProvider('malformedMatrices')]
    public function malformed_matrix_structure_is_a_named_failure(string $body, string $rule): void
    {
        $fixture = $this->fixture(['alpha'], provisioned: ['alpha']);
        if (str_starts_with($body, 'jobs:')) {
            file_put_contents($fixture . '/split.yml', $body);
        } else {
            $this->writeSplitYaml($fixture, $body);
        }

        [$exitCode, $output] = $this->runGate($fixture);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('FAIL [' . $rule . ']', $output);
    }

    /**
     * Fast feedback on the real workflow. The release gate would catch a row
     * this parser cannot read, but only at release time; this says so on the
     * commit that introduces it.
     */
    #[Test]
    public function every_matrix_row_in_this_repository_uses_the_supported_form(): void
    {
        $offenders = [];
        foreach (explode("\n", $this->read(self::SPLIT)) as $number => $line) {
            if (str_starts_with(ltrim($line), '#') || preg_match('/(?<![A-Za-z0-9_])remote:/', $line) !== 1) {
                continue;
            }
            if (preg_match("/remote:\s*'[^']+'/", $line) !== 1) {
                $offenders[] = sprintf('%s:%d: %s', self::SPLIT, $number + 1, trim($line));
            }
        }

        self::assertSame([], $offenders, "Rows the release gate cannot parse:\n" . implode("\n", $offenders));
    }

    /**
     * A disposable repository shaped like this one: a root manifest requiring
     * nothing (so RR001's roster is empty and only RR002 can speak), an empty
     * packages directory, a split workflow declaring `$targets`, and a bare git
     * repository for each of `$provisioned`.
     *
     * @param list<string>          $targets
     * @param list<string>          $provisioned
     * @param array<string, string> $styles target => single|double|bare
     */
    private function fixture(array $targets, array $provisioned, array $styles = []): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa-split-reachability-' . bin2hex(random_bytes(6));
        mkdir($root . '/packages', recursive: true);
        mkdir($root . '/remotes', recursive: true);
        $this->cleanup[] = $root;

        file_put_contents(
            $root . '/composer.json',
            json_encode(
                ['name' => 'waaseyaa/framework', 'require' => ['php' => '>=8.5']],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );

        $rows = '';
        foreach ($targets as $target) {
            $rows .= sprintf(
                "          - { local: 'packages/%s', remote: %s }\n",
                $target,
                match ($styles[$target] ?? 'single') {
                    'single' => "'" . $target . "'",
                    'double' => '"' . $target . '"',
                    'bare' => $target,
                },
            );
        }
        $this->writeSplitYaml($root, "        package:\n" . $rows);

        foreach ($provisioned as $target) {
            $path = $root . '/remotes/' . $target;
            mkdir($path, recursive: true);
            exec(sprintf('git init --bare --quiet %s 2>&1', escapeshellarg($path)), $ignored, $code);
            self::assertSame(0, $code, 'Could not create the fixture bare repository ' . $path);
        }

        return $root;
    }

    /** Write the split workflow around a caller-supplied matrix body. */
    private function writeSplitYaml(string $root, string $matrixBody): void
    {
        file_put_contents(
            $root . '/split.yml',
            "jobs:\n  split:\n    strategy:\n      matrix:\n" . $matrixBody,
        );
    }

    /** @return array{0: int, 1: string} */
    private function runGate(string $fixture): array
    {
        $command = sprintf(
            'SPLIT_YML=%s COMPOSER_JSON=%s PACKAGES_DIR=%s REPO_REMOTE_TEMPLATE=%s CHECK_REMOTE=1 GITHUB_TOKEN= bash %s 2>&1',
            escapeshellarg($fixture . '/split.yml'),
            escapeshellarg($fixture . '/composer.json'),
            escapeshellarg($fixture . '/packages'),
            escapeshellarg('file://' . $fixture . '/remotes/{repo}'),
            escapeshellarg($this->repoRoot . '/' . self::GATE),
        );

        exec($command, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function read(string $relative): string
    {
        $path = $this->repoRoot . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
