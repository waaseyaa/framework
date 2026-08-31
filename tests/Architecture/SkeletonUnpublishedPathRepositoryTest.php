<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `bin/skeleton-unpublished-repositories` renders the Composer `repositories`
 * entries that ci/skeleton-create-project's ordinary lane adds for first-party
 * packages Packagist cannot serve yet.
 *
 * The defect it exists to prevent (#2717): a path repository carries no version
 * of its own, so Composer guesses one from the checkout's Git state. On a pull
 * request `actions/checkout` lands on a DETACHED merge commit with no branch
 * ref, the guess becomes `dev-<sha>`, and `dev-<sha>` has no `branch-alias`, so
 * it cannot satisfy the skeleton's governed `^<VERSION>` floor. Every Composer
 * attempt in the job failed on exactly that.
 *
 * These tests therefore do **not** read the script's source. They build a real
 * detached checkout, run the real script, feed its real output into a real
 * consumer manifest, and run the real Composer resolver over it — because the
 * only claim worth making is that the generated configuration RESOLVES. The
 * negative control (the pre-repair repository shape, on the identical fixture)
 * is what keeps the positive case from passing for an unrelated reason.
 */
#[CoversNothing]
final class SkeletonUnpublishedPathRepositoryTest extends TestCase
{
    private const string SCRIPT = 'bin/skeleton-unpublished-repositories';
    private const string FIXTURE_VERSION = '0.1.0-alpha.299';
    private const string FIXTURE_PACKAGE = 'waaseyaa/ai-development';

    private string $repoRoot;

    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            $this->removeTree($path);
        }
        $this->cleanupPaths = [];
    }

    // ── The regression, proved by resolution ────────────────────────────────

    /**
     * The whole repair in one assertion: on a detached checkout with no branch
     * ref — the exact shape a PR run gets — the generated repository resolves
     * against the governed `^<VERSION>` floor.
     */
    #[Test]
    public function the_generated_repository_resolves_the_governed_floor_on_a_detached_checkout(): void
    {
        $fixture = $this->fixtureRoot();
        $record = $this->soleRecord($fixture);

        $result = $this->resolve($fixture, self::FIXTURE_PACKAGE, '^' . self::FIXTURE_VERSION, [$record['repository']]);

        self::assertSame(0, $result['exit_code'], "Composer could not resolve the generated repository:\n" . $result['output']);
        self::assertSame(
            self::FIXTURE_VERSION,
            $result['locked'][self::FIXTURE_PACKAGE] ?? null,
            'The path package must lock at the tracked VERSION, not at a guessed dev version.',
        );
    }

    /**
     * The negative control. Strip only `options.versions` from the very same
     * generated repository, on the very same fixture, and Composer falls back
     * to guessing — reproducing #2717 verbatim. Without this, the test above
     * could pass for reasons unrelated to the pin.
     */
    #[Test]
    public function without_the_pin_the_same_fixture_fails_on_a_guessed_dev_version(): void
    {
        $fixture = $this->fixtureRoot();
        $record = $this->soleRecord($fixture);

        $unpinned = $record['repository'];
        unset($unpinned['options']);

        $result = $this->resolve($fixture, self::FIXTURE_PACKAGE, '^' . self::FIXTURE_VERSION, [$unpinned]);

        self::assertNotSame(0, $result['exit_code'], "The pre-repair repository shape must not resolve:\n" . $result['output']);
        self::assertStringContainsString(
            'dev-' . $this->headSha($fixture),
            $result['output'],
            'The pre-repair failure must be the detached-HEAD version guess this repair removes.',
        );
    }

    /**
     * The pin must not become a way to broaden what the checkout can satisfy.
     * `only` keeps the repository scoped to the one rostered name; every other
     * package the skeleton needs still has to come from the published line.
     */
    #[Test]
    public function the_generated_repository_stays_scoped_to_the_rostered_package(): void
    {
        $fixture = $this->fixtureRoot();
        $record = $this->soleRecord($fixture);

        self::assertSame([self::FIXTURE_PACKAGE], $record['repository']['only'] ?? null);
        self::assertSame(
            [self::FIXTURE_PACKAGE => self::FIXTURE_VERSION],
            $record['repository']['options']['versions'] ?? null,
            'Exactly one package may be pinned, and only the one this repository is rostered for.',
        );

        // Behavioural half: with Packagist off and only this repository
        // configured, an unrostered name must not resolve from the checkout.
        $result = $this->resolve($fixture, 'waaseyaa/not-rostered', '^' . self::FIXTURE_VERSION, [$record['repository']]);

        self::assertNotSame(0, $result['exit_code'], "A scoped repository must not satisfy an unrostered name:\n" . $result['output']);
    }

    /**
     * The emitted `url` is read from inside the CREATED CONSUMER PROJECT, not
     * from the framework checkout, so a root that survived as a relative path
     * would send Composer looking somewhere the package has never existed. The
     * workflow passes an absolute `$GITHUB_WORKSPACE`, so this is the helper's
     * own contract rather than the lane's — and it is asserted by resolving
     * from a relative root, not by inspecting the string.
     */
    #[Test]
    public function a_relative_repository_root_still_yields_a_resolvable_absolute_url(): void
    {
        $fixture = $this->fixtureRoot();

        $result = $this->runScript('.', cwd: $fixture);
        self::assertSame(0, $result['exit_code'], $result['stderr']);

        $records = $this->parse($result['stdout']);
        self::assertCount(1, $records);

        $url = $records[0]['repository']['url'] ?? null;
        self::assertIsString($url);
        self::assertTrue(str_starts_with($url, '/'), "Emitted url must be absolute, got: {$url}");

        $resolved = $this->resolve(
            $fixture,
            self::FIXTURE_PACKAGE,
            '^' . self::FIXTURE_VERSION,
            [$records[0]['repository']],
        );

        self::assertSame(0, $resolved['exit_code'], $resolved['output']);
        self::assertSame(self::FIXTURE_VERSION, $resolved['locked'][self::FIXTURE_PACKAGE] ?? null);
    }

    /** A root that is not an existing directory is refused before anything is built from it. */
    #[Test]
    public function a_repository_root_that_does_not_exist_is_rejected(): void
    {
        $result = $this->runScript($this->repoRoot . '/no-such-checkout');

        self::assertSame(1, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('existing directory', $result['stderr']);
    }

    /** The correct steady state is an empty roster, and it must be a no-op. */
    #[Test]
    public function an_empty_roster_emits_nothing_and_succeeds(): void
    {
        $fixture = $this->fixtureRoot(roster: []);

        $result = $this->runScript($fixture);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    // ── Fail-closed inputs ──────────────────────────────────────────────────

    /**
     * The shared library keeps a compatibility fallback to `git describe
     * --tags` for checkouts with no VERSION file. That is right for the
     * library and wrong here, so this script requires the tracked file
     * explicitly. The fixture carries a REAL semver tag on HEAD, so a
     * regression that dropped the explicit check would resolve the tag and
     * pass — the rejection has to be the script's own, not an accident of
     * there being nothing to fall back to.
     */
    #[Test]
    public function a_missing_version_file_is_rejected_even_when_a_semver_tag_would_resolve(): void
    {
        $fixture = $this->fixtureRoot();
        unlink($fixture . '/VERSION');
        $this->git($fixture, ['tag', 'v' . self::FIXTURE_VERSION, $this->headSha($fixture)]);

        // Positive control: the fallback this script must not take really is
        // available in this fixture.
        $described = $this->git($fixture, ['describe', '--tags', '--abbrev=0', '--match=v*.*.*']);
        self::assertSame('v' . self::FIXTURE_VERSION, trim($described['stdout']), 'The tag fallback must be reachable here.');

        $result = $this->runScript($fixture);

        self::assertSame(1, $result['exit_code'], $result['stdout']);
        self::assertStringContainsString('VERSION', $result['stderr']);
        self::assertSame('', $result['stdout'], 'A rejected input must emit no repository at all.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ["   \n"];
        yield 'dev alias' => ['dev-main'];
        yield 'wildcard' => ['0.1.x'];
        yield 'not a version' => ['alpha'];
    }

    #[Test]
    #[DataProvider('malformedVersions')]
    public function a_malformed_tracked_version_is_rejected(string $version): void
    {
        $fixture = $this->fixtureRoot();
        file_put_contents($fixture . '/VERSION', $version);

        $result = $this->runScript($fixture);

        self::assertSame(1, $result['exit_code'], $result['stdout']);
        self::assertSame('', $result['stdout']);
    }

    /**
     * A pin only helps if it satisfies what the skeleton actually asks for. If
     * the floor and the tracked version have drifted, the pin would be a guess
     * dressed up as a fact.
     */
    #[Test]
    public function a_skeleton_floor_that_has_drifted_from_the_tracked_version_is_rejected(): void
    {
        $fixture = $this->fixtureRoot(skeletonConstraint: '^0.1.0-alpha.298');

        $result = $this->runScript($fixture);

        self::assertSame(1, $result['exit_code'], $result['stdout']);
        self::assertStringContainsString('sync-internal-versions', $result['stderr']);
    }

    /**
     * The last case is deliberately not an object at all, so the element type
     * here is wider than a well-formed entry: the point is what the script does
     * with a roster it cannot read.
     *
     * @return iterable<string, array{list<mixed>, string}>
     */
    public static function malformedRosters(): iterable
    {
        $valid = ['package' => self::FIXTURE_PACKAGE, 'path' => 'packages/ai-development'];

        yield 'path escaping the packages directory' => [
            [['package' => self::FIXTURE_PACKAGE, 'path' => '../../etc']],
            'permitted path',
        ];
        yield 'path pointing at another package' => [
            [['package' => self::FIXTURE_PACKAGE, 'path' => 'packages/testing']],
            'permitted path',
        ];
        yield 'third-party package name' => [
            [['package' => 'evil/payload', 'path' => 'packages/payload']],
            'first-party',
        ];
        yield 'package name absent' => [
            [['path' => 'packages/ai-development']],
            'first-party',
        ];
        yield 'duplicate entry' => [
            [$valid, $valid],
            'rostered once',
        ];
        yield 'entry is not an object' => [
            [['waaseyaa/ai-development']],
            'JSON object',
        ];
    }

    /**
     * @param list<mixed> $packages
     */
    #[Test]
    #[DataProvider('malformedRosters')]
    public function a_malformed_roster_is_rejected_rather_than_silently_narrowing(array $packages, string $expected): void
    {
        $fixture = $this->fixtureRoot(roster: $packages);

        $result = $this->runScript($fixture);

        self::assertSame(1, $result['exit_code'], $result['stdout']);
        self::assertStringContainsString($expected, $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    #[Test]
    public function an_unreadable_or_wrongly_versioned_roster_is_rejected(): void
    {
        $fixture = $this->fixtureRoot();
        file_put_contents(
            $fixture . '/support/skeleton-unpublished-packages.json',
            (string) json_encode(['schema_version' => 2, 'packages' => []]),
        );
        self::assertSame(1, $this->runScript($fixture)['exit_code']);

        file_put_contents($fixture . '/support/skeleton-unpublished-packages.json', '{ not json');
        self::assertSame(1, $this->runScript($fixture)['exit_code']);

        unlink($fixture . '/support/skeleton-unpublished-packages.json');
        self::assertSame(1, $this->runScript($fixture)['exit_code']);
    }

    /** A rostered package the skeleton never asks for buys nothing. */
    #[Test]
    public function a_package_the_skeleton_does_not_require_is_rejected(): void
    {
        $fixture = $this->fixtureRoot(skeletonConstraint: null);

        $result = $this->runScript($fixture);

        self::assertSame(1, $result['exit_code'], $result['stdout']);
        self::assertStringContainsString('does not require it', $result['stderr']);
    }

    /** The rostered directory must really be the package it claims to be. */
    #[Test]
    public function a_directory_that_does_not_name_the_rostered_package_is_rejected(): void
    {
        $fixture = $this->fixtureRoot();
        $manifest = $fixture . '/packages/ai-development/composer.json';
        $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $decoded['name'] = 'waaseyaa/something-else';
        file_put_contents($manifest, (string) json_encode($decoded, JSON_PRETTY_PRINT));

        $result = $this->runScript($fixture);

        self::assertSame(1, $result['exit_code'], $result['stdout']);
        self::assertStringContainsString('waaseyaa/something-else', $result['stderr']);
    }

    // ── Binding to the live repository ──────────────────────────────────────

    /**
     * The script is inert unless the job runs it, and the roster is inert
     * unless the script reads it. This is the seam between the two — the
     * behavioural claims above are made against a fixture, so something has to
     * assert that the real lane is wired to the thing being tested.
     */
    #[Test]
    public function the_ordinary_lane_renders_its_repositories_through_this_script(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');

        self::assertStringContainsString(
            'php "$GITHUB_WORKSPACE/' . self::SCRIPT . '" "$GITHUB_WORKSPACE"',
            $workflow,
            'The ordinary lane must render its path repositories through the validated script.',
        );
        self::assertStringContainsString(
            'composer config --working-dir="$work/skel-proj" "repositories.${slug}" "$config"',
            $workflow,
            'The job must apply the repository object the script emits, not one it assembles itself.',
        );
        self::assertStringNotContainsString(
            '\\"only\\":[\\"${pkg}\\"]',
            $workflow,
            'The hand-assembled repository JSON is what omitted the version pin; it must not come back.',
        );
    }

    /**
     * The live roster must render. An entry that cannot be turned into a
     * repository would fail the lane at the worst possible moment — inside a
     * job whose failure looks like a Packagist outage.
     */
    #[Test]
    public function the_live_roster_renders_against_this_checkout(): void
    {
        $result = $this->runScript($this->repoRoot);

        self::assertSame(0, $result['exit_code'], $result['stderr']);

        $version = trim((string) file_get_contents($this->repoRoot . '/VERSION'));

        foreach ($this->parse($result['stdout']) as $record) {
            self::assertDirectoryExists($record['repository']['url']);
            self::assertSame([$record['package']], $record['repository']['only'] ?? null);
            self::assertSame(
                [$record['package'] => $version],
                $record['repository']['options']['versions'] ?? null,
            );
            self::assertSame($version, $record['pinned']);
        }
    }

    // ── Fixture and process helpers ─────────────────────────────────────────

    /**
     * A miniature framework checkout, detached at a commit no branch points
     * at. That is the state `actions/checkout` leaves a pull-request run in,
     * and it is the state in which Composer's version guess goes wrong.
     *
     * @param list<mixed>|null $roster A malformed roster is a valid fixture: the
     *                                 script's rejection of one is under test.
     */
    private function fixtureRoot(?array $roster = null, ?string $skeletonConstraint = '^0.1.0-alpha.299'): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_unpublished_' . uniqid('', true);
        $this->cleanupPaths[] = $root;

        mkdir($root . '/support', 0o777, true);
        mkdir($root . '/skeleton', 0o777, true);
        mkdir($root . '/packages/ai-development', 0o777, true);
        mkdir($root . '/packages/testing', 0o777, true);

        file_put_contents($root . '/VERSION', self::FIXTURE_VERSION . "\n");

        // Deliberately dependency-free so the resolver runs fully offline: the
        // claim under test is version resolution, not network reachability.
        $this->writeJson($root . '/packages/ai-development/composer.json', [
            'name' => self::FIXTURE_PACKAGE,
            'type' => 'metapackage',
            'require' => new \stdClass(),
            'extra' => ['branch-alias' => ['dev-main' => '0.1.x-dev']],
        ]);
        $this->writeJson($root . '/packages/testing/composer.json', [
            'name' => 'waaseyaa/testing',
            'type' => 'library',
        ]);

        $skeleton = ['name' => 'waaseyaa/waaseyaa', 'type' => 'project', 'require-dev' => new \stdClass()];
        if ($skeletonConstraint !== null) {
            $skeleton['require-dev'] = [self::FIXTURE_PACKAGE => $skeletonConstraint];
        }
        $this->writeJson($root . '/skeleton/composer.json', $skeleton);

        $this->writeJson($root . '/support/skeleton-unpublished-packages.json', [
            'schema_version' => 1,
            'packages' => $roster ?? [[
                'package' => self::FIXTURE_PACKAGE,
                'path' => 'packages/ai-development',
            ]],
        ]);

        $this->git($root, ['init', '--quiet', '--initial-branch=main', '.']);
        $this->git($root, ['-c', 'user.email=ci@example.test', '-c', 'user.name=CI', 'add', '-A']);
        $this->git($root, ['-c', 'user.email=ci@example.test', '-c', 'user.name=CI', 'commit', '--quiet', '-m', 'fixture']);
        $head = $this->headSha($root);
        $this->git($root, ['checkout', '--quiet', '--detach', $head]);
        // No branch may point at HEAD: with one, Composer's guesser recovers
        // `dev-main`, the branch alias applies, and the defect hides.
        $this->git($root, ['branch', '-D', 'main']);

        return $root;
    }

    /**
     * Runs the real script and returns its real streams and exit code.
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runScript(string $root, ?string $cwd = null): array
    {
        return $this->execute(
            [PHP_BINARY, $this->repoRoot . '/' . self::SCRIPT, $root],
            $cwd ?? $this->repoRoot,
        );
    }

    /**
     * Runs the real Composer resolver over a consumer manifest built from the
     * generated repositories. Packagist is switched off, so anything that
     * resolves resolved from the configuration under test and nothing else.
     *
     * @param list<array<string, mixed>> $repositories
     *
     * @return array{exit_code: int, output: string, locked: array<string, string>}
     */
    private function resolve(string $fixture, string $package, string $constraint, array $repositories): array
    {
        $consumer = $fixture . '/consumer';
        mkdir($consumer, 0o777, true);

        $configured = ['packagist.org' => false];
        foreach ($repositories as $index => $repository) {
            $configured['generated-' . $index] = $repository;
        }

        $this->writeJson($consumer . '/composer.json', [
            'name' => 'waaseyaa/consumer-fixture',
            'type' => 'project',
            'require' => [$package => $constraint],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'repositories' => $configured,
        ]);

        $composerHome = $fixture . '/composer-home';
        mkdir($composerHome, 0o777, true);

        $result = $this->execute(
            [
                $this->composerBinary(),
                'update',
                '--no-interaction',
                '--no-install',
                '--no-scripts',
                '--no-plugins',
                '--no-audit',
                '--working-dir=' . $consumer,
            ],
            $consumer,
            ['COMPOSER_HOME' => $composerHome, 'COMPOSER_DISABLE_NETWORK' => '1'],
        );

        $locked = [];
        $lockPath = $consumer . '/composer.lock';
        if (is_file($lockPath)) {
            $lock = json_decode((string) file_get_contents($lockPath), true, flags: JSON_THROW_ON_ERROR);
            foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $entry) {
                $locked[(string) $entry['name']] = (string) $entry['version'];
            }
        }

        return [
            'exit_code' => $result['exit_code'],
            'output' => $result['stdout'] . $result['stderr'],
            'locked' => $locked,
        ];
    }

    /**
     * The one record the default fixture roster produces.
     *
     * @return array{package: string, path: string, pinned: string, repository: array<string, mixed>}
     */
    private function soleRecord(string $fixture): array
    {
        $result = $this->runScript($fixture);
        self::assertSame(0, $result['exit_code'], $result['stderr']);

        $records = $this->parse($result['stdout']);
        self::assertCount(1, $records);

        return $records[0];
    }

    /**
     * @return list<array{package: string, path: string, pinned: string, repository: array<string, mixed>}>
     */
    private function parse(string $stdout): array
    {
        $records = [];
        foreach (explode("\n", trim($stdout)) as $line) {
            if ($line === '') {
                continue;
            }

            $fields = explode("\t", $line);
            self::assertCount(5, $fields, "Each record is slug/package/path/version/json: {$line}");

            [$slug, $package, $path, $pinned, $json] = $fields;
            self::assertSame(str_replace('/', '-', $package), $slug);

            $repository = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($repository);

            $records[] = [
                'package' => $package,
                'path' => $path,
                'pinned' => $pinned,
                'repository' => $repository,
            ];
        }

        return $records;
    }

    /**
     * Fixture Git commands are setup, not subject matter: if one silently
     * fails, every behavioural claim below is made against a tree that is not
     * in the state it claims to be. So they are asserted, not merely issued.
     *
     * @param list<string> $args
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function git(string $root, array $args): array
    {
        $result = $this->execute(array_merge(['git', '-C', $root], $args), $root);

        self::assertSame(
            0,
            $result['exit_code'],
            sprintf("Fixture setup failed: git %s\n%s", implode(' ', $args), $result['stderr']),
        );

        return $result;
    }

    private function headSha(string $root): string
    {
        return trim($this->git($root, ['rev-parse', 'HEAD'])['stdout']);
    }

    /**
     * Composer is a hard requirement of every supported runner for this suite —
     * the suite cannot run at all without a `composer install` having happened —
     * so an absent binary is a broken environment to report, not a reason to
     * skip. `COMPOSER_BINARY`, which Composer itself exports to its scripts,
     * still wins so a runner can pin the exact binary it installed.
     */
    private function composerBinary(): string
    {
        $override = getenv('COMPOSER_BINARY');
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }

        $found = new ExecutableFinder()->find('composer');
        if (is_string($found) && $found !== '') {
            return $found;
        }

        self::fail('Composer must be available to prove that the generated repository configuration resolves.');
    }

    /**
     * Symfony Process drains stdout and stderr concurrently and enforces a
     * timeout, so a child that blocks or floods a pipe fails the test instead
     * of hanging the suite.
     *
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function execute(array $command, string $cwd, array $env = []): array
    {
        $process = new Process($command, $cwd, $env === [] ? null : $env, null, 120.0);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            self::fail('Timed out: ' . implode(' ', $command) . ' — ' . $e->getMessage());
        }

        return [
            'exit_code' => (int) $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
