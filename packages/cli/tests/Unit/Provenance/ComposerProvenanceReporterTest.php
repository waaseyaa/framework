<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provenance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\CLI\Provenance\ComposerProvenanceReporter;
use Waaseyaa\CLI\Provenance\InstalledWaaseyaaPackage;
use Waaseyaa\CLI\Provenance\ProvenanceReport;

#[CoversClass(ComposerProvenanceReporter::class)]
#[CoversClass(ProvenanceReport::class)]
#[CoversClass(InstalledWaaseyaaPackage::class)]
final class ComposerProvenanceReporterTest extends TestCase
{
    private const GOLDEN_ENV = 'WAASEYAA_GOLDEN_SHA';

    private string $fixture;

    /** @var string|false */
    private string|false $previousGolden;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/waaseyaa_prov_' . bin2hex(random_bytes(6));
        mkdir($this->fixture, 0o777, true);
        // Tests must not inherit a golden SHA from the developer's shell.
        $this->previousGolden = getenv(self::GOLDEN_ENV);
        putenv(self::GOLDEN_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->previousGolden === false) {
            putenv(self::GOLDEN_ENV);
        } else {
            putenv(self::GOLDEN_ENV . '=' . $this->previousGolden);
        }
        new Filesystem()->remove($this->fixture);
    }

    // -------------------------------------------------------------------------
    // Sibling application / monorepo topology (#2810)
    // -------------------------------------------------------------------------

    #[Test]
    public function resolves_sibling_monorepo_head_for_declared_path_repositories(): void
    {
        [$app, $monorepo, $head] = $this->createSiblingTopology();

        $report = new ComposerProvenanceReporter($app)->analyze();

        self::assertSame($head, $report->pathMonorepoHead);
        self::assertSame(realpath($monorepo), $report->pathMonorepoRoot);
        self::assertSame([], $report->driftMessages, implode("\n", $report->driftMessages));
        self::assertCount(2, $report->packages);
        foreach ($report->packages as $package) {
            self::assertSame('path', $package->sourceKind);
            self::assertSame($head, $package->gitHead);
            self::assertSame(realpath($monorepo), $package->checkoutRoot);
            self::assertNotNull($package->resolvedPath);
            self::assertStringStartsWith(realpath($monorepo), $package->resolvedPath);
        }

        $array = $report->toArray();
        self::assertSame($head, $array['pathMonorepoHead']);
        self::assertSame(realpath($monorepo), $array['pathMonorepoRoot']);
        self::assertSame(realpath($monorepo), $array['packages'][0]['checkoutRoot']);
    }

    #[Test]
    public function strict_mode_passes_when_sibling_head_matches_golden_sha_from_env(): void
    {
        [$app, , $head] = $this->createSiblingTopology();
        putenv(self::GOLDEN_ENV . '=' . $head);

        $report = new ComposerProvenanceReporter($app)->analyze();
        self::assertSame($head, $report->goldenSha);
        self::assertFalse($report->hasDrift(), implode("\n", $report->driftMessages));

        self::assertSame(0, $this->runMainSilently($app, ['--strict']));
        self::assertSame(0, $this->runMainSilently($app, []));
    }

    #[Test]
    public function strict_mode_passes_when_sibling_head_matches_golden_sha_file(): void
    {
        [$app, , $head] = $this->createSiblingTopology();
        file_put_contents($app . '/.waaseyaa-golden-sha', $head . "\n# trailing lines are ignored\n");

        $report = new ComposerProvenanceReporter($app)->analyze();
        self::assertSame($head, $report->goldenSha);
        self::assertFalse($report->hasDrift(), implode("\n", $report->driftMessages));
        self::assertSame(0, $this->runMainSilently($app, ['--strict']));
    }

    #[Test]
    public function strict_mode_fails_with_actionable_message_on_golden_sha_mismatch(): void
    {
        [$app, $monorepo, $head] = $this->createSiblingTopology();
        $golden = str_repeat('0', 40);
        putenv(self::GOLDEN_ENV . '=' . $golden);

        $report = new ComposerProvenanceReporter($app)->analyze();

        self::assertSame($head, $report->pathMonorepoHead, 'HEAD is still resolved so the operator sees actual vs expected');
        self::assertTrue($report->hasDrift());
        $message = implode("\n", $report->driftMessages);
        self::assertStringContainsString($head, $message, 'actual HEAD must be named');
        self::assertStringContainsString($golden, $message, 'expected golden SHA must be named');
        self::assertStringContainsString(realpath($monorepo), $message, 'the checkout that drifted must be named');
        self::assertStringNotContainsString('git missing', $message);

        self::assertSame(1, $this->runMainSilently($app, ['--strict']));
        self::assertSame(1, $this->runMainSilently($app, []));
        self::assertSame(0, $this->runMainSilently($app, ['--report-only']));
    }

    #[Test]
    public function human_report_names_the_resolved_checkout(): void
    {
        [$app, $monorepo, $head] = $this->createSiblingTopology();

        $lines = [];
        ComposerProvenanceReporter::printHuman(
            new ComposerProvenanceReporter($app)->analyze(),
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );
        $text = implode("\n", $lines);

        self::assertStringContainsString('Path monorepo HEAD: ' . $head, $text);
        self::assertStringContainsString('Path monorepo checkout: ' . realpath($monorepo), $text);
        self::assertStringNotContainsString('unresolved', $text);
    }

    // -------------------------------------------------------------------------
    // Existing in-project path-repository topology
    // -------------------------------------------------------------------------

    #[Test]
    public function resolves_in_project_path_repository_head(): void
    {
        $app = $this->fixture . '/app';
        mkdir($app . '/local/foundation', 0o777, true);
        file_put_contents($app . '/local/foundation/composer.json', '{"name":"waaseyaa/foundation"}');
        $head = $this->initGitRepository($app);
        $this->writeProject($app, [
            'waaseyaa/foundation' => 'local/foundation',
        ]);

        $report = new ComposerProvenanceReporter($app)->analyze();

        self::assertSame($head, $report->pathMonorepoHead);
        self::assertSame(realpath($app), $report->pathMonorepoRoot);
        self::assertSame([], $report->driftMessages, implode("\n", $report->driftMessages));
        self::assertSame(realpath($app . '/local/foundation'), $report->packages[0]->resolvedPath);
    }

    #[Test]
    public function in_project_head_is_compared_against_golden_sha(): void
    {
        $app = $this->fixture . '/app';
        mkdir($app . '/local/foundation', 0o777, true);
        $head = $this->initGitRepository($app);
        $this->writeProject($app, ['waaseyaa/foundation' => 'local/foundation']);

        putenv(self::GOLDEN_ENV . '=' . $head);
        self::assertSame(0, $this->runMainSilently($app, ['--strict']));

        putenv(self::GOLDEN_ENV . '=' . str_repeat('f', 40));
        self::assertSame(1, $this->runMainSilently($app, ['--strict']));
    }

    #[Test]
    public function abbreviated_golden_sha_matches_by_prefix(): void
    {
        [$app, , $head] = $this->createSiblingTopology();
        putenv(self::GOLDEN_ENV . '=' . substr($head, 0, 12));

        self::assertFalse(new ComposerProvenanceReporter($app)->analyze()->hasDrift());
    }

    // -------------------------------------------------------------------------
    // Unresolvable path targets report the actual condition, not "git missing"
    // -------------------------------------------------------------------------

    #[Test]
    public function reports_missing_path_target_as_drift(): void
    {
        $app = $this->fixture . '/app';
        mkdir($app, 0o777, true);
        $this->writeProject($app, ['waaseyaa/foundation' => '../nope/packages/foundation']);

        $report = new ComposerProvenanceReporter($app)->analyze();

        self::assertNull($report->pathMonorepoHead);
        self::assertNull($report->packages[0]->resolvedPath);
        self::assertTrue($report->hasDrift());
        $message = implode("\n", $report->driftMessages);
        self::assertStringContainsString('../nope/packages/foundation', $message);
        self::assertStringContainsString('does not exist', $message);
        self::assertStringNotContainsString('git missing', $message);
    }

    #[Test]
    public function binds_a_declared_target_to_its_nearest_git_ancestor_or_reports_none(): void
    {
        $app = $this->fixture . '/app';
        $target = $this->fixture . '/plain/packages/foundation';
        mkdir($app, 0o777, true);
        mkdir($target, 0o777, true);
        $this->writeProject($app, ['waaseyaa/foundation' => '../plain/packages/foundation']);

        $report = new ComposerProvenanceReporter($app)->analyze();
        $package = $report->packages[0];

        self::assertSame(realpath($target), $package->resolvedPath, 'existing declared target is resolved');

        // The fixture lives under the system temp dir. The contract is "nearest `.git`
        // ancestor of the target"; assert exactly that against the real filesystem so the
        // test is deterministic whether or not the temp dir happens to sit inside a checkout.
        $expectedRoot = $this->nearestGitAncestor((string) realpath($target));
        self::assertSame($expectedRoot, $package->checkoutRoot);

        if ($expectedRoot === null) {
            self::assertNull($package->gitHead);
            self::assertNull($report->pathMonorepoHead);
            self::assertTrue($report->hasDrift());
            $message = implode("\n", $report->driftMessages);
            self::assertStringContainsString(realpath($target), $message);
            self::assertStringContainsString('not inside a Git checkout', $message);

            return;
        }

        self::assertNotNull($package->gitHead);
        self::assertSame($package->gitHead, $report->pathMonorepoHead);
        self::assertStringNotContainsString('not inside a Git checkout', implode("\n", $report->driftMessages));
    }

    #[Test]
    public function two_distinct_checkout_roots_at_the_same_sha_are_reported_as_drift(): void
    {
        [$app, $monorepo, $head] = $this->createSiblingTopology();
        // A second clone at exactly the same commit: same HEAD, different checkout root.
        $clone = $this->fixture . '/waaseyaa-clone';
        $this->git($this->fixture, ['clone', '--quiet', $monorepo, $clone]);
        self::assertSame($head, $this->git($clone, ['rev-parse', 'HEAD']));
        $this->writeProject($app, [
            'waaseyaa/framework' => '../waaseyaa',
            'waaseyaa/foundation' => '../waaseyaa-clone/packages/foundation',
        ]);
        putenv(self::GOLDEN_ENV . '=' . $head);

        $report = new ComposerProvenanceReporter($app)->analyze();

        self::assertSame(realpath($monorepo), $report->packages[0]->checkoutRoot);
        self::assertSame(realpath($clone), $report->packages[1]->checkoutRoot);
        self::assertSame($head, $report->packages[0]->gitHead);
        self::assertSame($head, $report->packages[1]->gitHead);

        self::assertNull($report->pathMonorepoHead, 'no single monorepo HEAD when two checkouts are involved');
        self::assertNull($report->pathMonorepoRoot);
        self::assertTrue($report->hasDrift(), 'two checkouts at one SHA are still two checkouts');
        $message = implode("\n", $report->driftMessages);
        self::assertStringContainsString('multiple distinct Git checkout roots', $message);
        self::assertStringContainsString(realpath($monorepo), $message);
        self::assertStringContainsString(realpath($clone), $message);
        self::assertStringNotContainsString('does not match golden SHA', $message, 'both HEADs equal the golden SHA; the drift is the split checkout, not the SHA');

        self::assertSame(1, $this->runMainSilently($app, ['--strict']));
        self::assertSame(0, $this->runMainSilently($app, ['--report-only']));
    }

    #[Test]
    public function two_checkout_roots_at_different_shas_name_both_roots_and_heads(): void
    {
        [$app, $monorepo, $head] = $this->createSiblingTopology();
        $other = $this->fixture . '/other';
        mkdir($other . '/packages/foundation', 0o777, true);
        $otherHead = $this->initGitRepository($other);
        self::assertNotSame($head, $otherHead);
        $this->writeProject($app, [
            'waaseyaa/framework' => '../waaseyaa',
            'waaseyaa/foundation' => '../other/packages/foundation',
        ]);

        $report = new ComposerProvenanceReporter($app)->analyze();

        self::assertNull($report->pathMonorepoHead);
        self::assertTrue($report->hasDrift());
        $message = implode("\n", $report->driftMessages);
        self::assertStringContainsString(sprintf("'%s' (HEAD %s)", realpath($monorepo), $head), $message);
        self::assertStringContainsString(sprintf("'%s' (HEAD %s)", realpath($other), $otherHead), $message);
    }

    #[Test]
    public function partially_unresolved_path_installs_still_report_drift(): void
    {
        [$app, , $head] = $this->createSiblingTopology(extraPackages: [
            'waaseyaa/ghost' => '../waaseyaa/packages/ghost',
        ]);

        $report = new ComposerProvenanceReporter($app)->analyze();

        self::assertSame($head, $report->pathMonorepoHead, 'the resolvable packages still report the monorepo HEAD');
        self::assertTrue($report->hasDrift(), 'an unprovable package must not pass silently');
        self::assertStringContainsString('../waaseyaa/packages/ghost', implode("\n", $report->driftMessages));
    }

    #[Test]
    public function resolvePath_accepts_existing_targets_and_rejects_missing_ones(): void
    {
        $app = $this->fixture . '/app';
        $sibling = $this->fixture . '/sibling';
        mkdir($app, 0o777, true);
        mkdir($sibling, 0o777, true);

        $reporter = new ComposerProvenanceReporter($app);
        $method = new \ReflectionMethod($reporter, 'resolvePath');

        self::assertSame(realpath($sibling), $method->invoke($reporter, '../sibling'), 'relative sibling');
        self::assertSame(realpath($sibling), $method->invoke($reporter, $sibling), 'absolute sibling');
        self::assertSame(realpath($app), $method->invoke($reporter, '.'), 'project root itself');
        self::assertNull($method->invoke($reporter, '../missing'));
        self::assertNull($method->invoke($reporter, $this->fixture . '/missing'));
        self::assertNull($method->invoke($reporter, ''));
    }

    #[Test]
    public function resolvePath_rejects_a_file_target(): void
    {
        $app = $this->fixture . '/app';
        mkdir($app, 0o777, true);
        file_put_contents($this->fixture . '/file.txt', 'x');

        $reporter = new ComposerProvenanceReporter($app);
        $method = new \ReflectionMethod($reporter, 'resolvePath');

        self::assertNull($method->invoke($reporter, '../file.txt'));
    }

    #[Test]
    public function git_is_only_invoked_at_the_discovered_checkout_root(): void
    {
        [$app, $monorepo] = $this->createSiblingTopology();

        $reporter = new ComposerProvenanceReporter($app);
        $find = new \ReflectionMethod($reporter, 'findGitCheckoutRoot');

        self::assertSame(realpath($monorepo), $find->invoke($reporter, realpath($monorepo . '/packages/foundation')));
        self::assertSame(realpath($monorepo), $find->invoke($reporter, realpath($monorepo)));
    }

    // -------------------------------------------------------------------------
    // Pre-existing constraint / exit-code coverage
    // -------------------------------------------------------------------------

    #[Test]
    public function detects_multiple_constraint_patterns(): void
    {
        $dir = $this->fixture;
        file_put_contents($dir . '/composer.json', <<<'JSON'
            {
                "require": {
                    "waaseyaa/entity": "^0.1.0-alpha.37",
                    "waaseyaa/foundation": "^0.1.0-alpha.63"
                }
            }
            JSON);
        file_put_contents($dir . '/composer.lock', '{"packages": [], "packages-dev": []}');

        $report = new ComposerProvenanceReporter($dir)->analyze();
        $this->assertGreaterThan(1, count($report->uniqueConstraints));
        $this->assertTrue($report->hasDrift());
        $this->assertNotEmpty($report->driftMessages);
    }

    #[Test]
    public function single_constraint_pattern_no_drift_from_constraints(): void
    {
        $dir = $this->fixture;
        file_put_contents($dir . '/composer.json', <<<'JSON'
            {
                "require": {
                    "waaseyaa/entity": "^0.1",
                    "waaseyaa/foundation": "^0.1"
                }
            }
            JSON);
        file_put_contents($dir . '/composer.lock', '{"packages": [], "packages-dev": []}');

        $report = new ComposerProvenanceReporter($dir)->analyze();
        $this->assertSame(1, count($report->uniqueConstraints));
        $constraintDrift = false;
        foreach ($report->driftMessages as $m) {
            if (str_contains($m, 'constraint')) {
                $constraintDrift = true;
            }
        }
        $this->assertFalse($constraintDrift);
    }

    #[Test]
    public function main_exits_failure_on_drift_unless_report_only(): void
    {
        $dir = $this->fixture;
        file_put_contents($dir . '/composer.json', <<<'JSON'
            {
                "require": {
                    "waaseyaa/entity": "^0.1.0-alpha.37",
                    "waaseyaa/foundation": "^0.1.0-alpha.63"
                }
            }
            JSON);
        file_put_contents($dir . '/composer.lock', '{"packages": [], "packages-dev": []}');

        $this->assertSame(1, $this->runMainSilently($dir, []));
        $this->assertSame(1, $this->runMainSilently($dir, ['--strict']));
        $this->assertSame(0, $this->runMainSilently($dir, ['--report-only']));
    }

    #[Test]
    public function main_exits_success_when_no_drift(): void
    {
        $dir = $this->fixture;
        file_put_contents($dir . '/composer.json', <<<'JSON'
            {
                "require": {
                    "waaseyaa/entity": "^0.1",
                    "waaseyaa/foundation": "^0.1"
                }
            }
            JSON);
        file_put_contents($dir . '/composer.lock', '{"packages": [], "packages-dev": []}');

        $this->assertSame(0, $this->runMainSilently($dir, []));
        $this->assertSame(0, $this->runMainSilently($dir, ['--strict']));
    }

    // -------------------------------------------------------------------------
    // Fixture helpers
    // -------------------------------------------------------------------------

    /**
     * Builds `<fixture>/app` and `<fixture>/waaseyaa` (a committed Git checkout with
     * `packages/foundation`), and an application lock that declares both `../waaseyaa`
     * and `../waaseyaa/packages/foundation` as path installs.
     *
     * @param array<string, string> $extraPackages name => dist path
     *
     * @return array{0: string, 1: string, 2: string} [appRoot, monorepoRoot, monorepoHead]
     */
    private function createSiblingTopology(array $extraPackages = []): array
    {
        $app = $this->fixture . '/app';
        $monorepo = $this->fixture . '/waaseyaa';
        mkdir($app, 0o777, true);
        mkdir($monorepo . '/packages/foundation', 0o777, true);
        file_put_contents($monorepo . '/composer.json', '{"name":"waaseyaa/framework"}');
        file_put_contents($monorepo . '/packages/foundation/composer.json', '{"name":"waaseyaa/foundation"}');
        $head = $this->initGitRepository($monorepo);

        $this->writeProject($app, [
            'waaseyaa/framework' => '../waaseyaa',
            'waaseyaa/foundation' => '../waaseyaa/packages/foundation',
        ] + $extraPackages);

        return [$app, $monorepo, $head];
    }

    /**
     * @param array<string, string> $pathPackages name => path dist url
     */
    private function writeProject(string $root, array $pathPackages): void
    {
        $require = [];
        $packages = [];
        foreach ($pathPackages as $name => $url) {
            $require[$name] = '^0.1';
            $packages[] = [
                'name' => $name,
                'version' => 'dev-main',
                'dist' => ['type' => 'path', 'url' => $url, 'reference' => 'abcdef0'],
            ];
        }
        file_put_contents($root . '/composer.json', json_encode([
            'require' => $require,
            'repositories' => [['type' => 'path', 'url' => '../waaseyaa/packages/*'], ['type' => 'path', 'url' => '../waaseyaa']],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($root . '/composer.lock', json_encode([
            'packages' => $packages,
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** Initialises a repository with one commit and returns its HEAD SHA. */
    private function initGitRepository(string $dir): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $this->git($dir, ['init', '--quiet']);
        $this->git($dir, ['add', '-A']);
        $this->git($dir, ['commit', '--quiet', '--allow-empty', '-m', 'fixture']);

        return $this->git($dir, ['rev-parse', 'HEAD']);
    }

    /**
     * @param list<string> $args
     */
    private function git(string $dir, array $args): string
    {
        $cmd = array_merge(
            ['git', '-C', $dir, '-c', 'user.name=fixture', '-c', 'user.email=fixture@example.test', '-c', 'commit.gpgsign=false'],
            $args,
        );
        $process = new Process($cmd);
        $process->run();
        self::assertSame(0, $process->getExitCode(), 'git ' . implode(' ', $args) . ' failed: ' . $process->getErrorOutput());

        return trim($process->getOutput());
    }

    private function nearestGitAncestor(string $dir): ?string
    {
        $current = $dir;
        while (true) {
            if (file_exists($current . DIRECTORY_SEPARATOR . '.git')) {
                return $current;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }
    }

    /**
     * @param list<string> $argv
     */
    private function runMainSilently(string $root, array $argv): int
    {
        // main() writes straight to the STDOUT stream (not the output buffer), so it
        // cannot be captured here; the exit code is the assertion surface.
        return ComposerProvenanceReporter::main($root, $argv);
    }
}
