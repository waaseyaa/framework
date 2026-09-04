<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Fixture-driven proof that `bin/check-support-contract` treats
 * `support/s1-v1.json` as the sole support-policy authority (#2852):
 *
 *   - the checker validates the contract's closed schema, value types, and
 *     safety invariants without carrying a second copy of the policy values;
 *   - every runtime/distribution surface it compares is derived from the
 *     PARSED contract, so a representative policy change with matching
 *     surfaces passes while drift, malformed contracts, and widening fail;
 *   - every declared support-reducing transition goes through one shared
 *     notice-window computation (#2862), and a window that has already been
 *     entered fails closed unless the contract records an acknowledgement.
 *
 * The gate accepts `--root=DIR` and `--contract=PATH` (the idiom shared with
 * `bin/check-s1-sqlite-contract`), so each case builds a throwaway repository
 * tree whose surfaces are generated FROM the fixture contract and runs the
 * real script against it.
 */
#[CoversNothing]
final class CheckSupportContractGateTest extends TestCase
{
    private string $repoRoot;
    private string $gate;
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->gate = $this->repoRoot . '/bin/check-support-contract';
        self::assertFileExists($this->gate);
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa_supportgate_' . uniqid('', true);
        mkdir($this->tmpRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmpRoot);
    }

    #[Test]
    public function matching_surfaces_pass_for_a_conforming_fixture(): void
    {
        $this->writeFixture($this->baseContract());

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, "A conforming fixture tree must pass.\n{$out}");
        self::assertStringContainsString('OK: S1 support contract', $out);
    }

    #[Test]
    public function node_maintenance_start_inside_an_entered_notice_window_fails_without_acknowledgement(): void
    {
        // The #2862 defect shape: Node maintenance begins 30 days from now, so
        // its 90-day notice window opened 60 days ago; the contract was last
        // reviewed inside that window and the next review is 20 days out.
        // Today this exits 0 because maintenance_start is date-validated and
        // then dropped from the notice set.
        $contract = $this->baseContract();
        $contract['last_reviewed'] = $this->day(-10);
        $contract['review_by'] = $this->day(20);
        $contract['platform']['node']['maintenance_start'] = $this->day(30);
        $this->writeFixture($contract);

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "An entered notice window without acknowledgement must fail.\n{$out}");
        self::assertStringContainsString('platform.node.maintenance_start', $out);
    }

    #[Test]
    public function node_eol_inside_the_notice_window_fails(): void
    {
        // The window has not opened yet (it opens in 10 days) but the next
        // scheduled review already falls inside it.
        $contract = $this->baseContract();
        $contract['review_by'] = $this->day(20);
        $contract['platform']['node']['eol'] = $this->day(100);
        $this->writeFixture($contract);

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "A review scheduled inside the eol notice window must fail.\n{$out}");
        self::assertStringContainsString('platform.node.eol', $out);
        self::assertStringContainsString('enters its 90-day notice window', $out);
    }

    #[Test]
    public function php_active_support_end_inside_the_notice_window_fails(): void
    {
        $contract = $this->baseContract();
        $contract['review_by'] = $this->day(20);
        $contract['platform']['php']['active_support_end'] = $this->day(100);
        $this->writeFixture($contract);

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "A review scheduled inside the PHP notice window must fail.\n{$out}");
        self::assertStringContainsString('platform.php.active_support_end', $out);
    }

    #[Test]
    public function a_transition_whose_window_was_already_entered_fails_without_acknowledgement(): void
    {
        $contract = $this->baseContract();
        $contract['last_reviewed'] = $this->day(-10);
        $contract['review_by'] = $this->day(20);
        $contract['platform']['node']['eol'] = $this->day(30);
        $this->writeFixture($contract);

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "An entered window without acknowledgement must fail.\n{$out}");
        self::assertStringContainsString('platform.node.eol', $out);
        self::assertStringContainsString('entered its 90-day notice window', $out);
        self::assertStringContainsString('transition_acknowledgements', $out);
    }

    #[Test]
    public function an_entered_window_passes_with_an_explicit_acknowledgement_and_pre_transition_review(): void
    {
        $contract = $this->baseContract();
        $contract['last_reviewed'] = $this->day(-10);
        $contract['review_by'] = $this->day(20);
        $contract['platform']['node']['maintenance_start'] = $this->day(30);
        $contract['transition_acknowledgements'] = [$this->acknowledgement('platform.node.maintenance_start', $this->day(30), $this->day(20))];
        $this->writeFixture($contract);

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, "An acknowledged window with a pre-transition review must pass.\n{$out}");
    }

    #[Test]
    public function an_acknowledgement_is_satisfied_by_a_recorded_review_on_or_after_its_review_date(): void
    {
        // The pre-transition review happened (last_reviewed >= the acknowledged
        // review_by); the next routine review may now land after the transition.
        $contract = $this->baseContract();
        $contract['last_reviewed'] = $this->day(-2);
        $contract['review_by'] = $this->day(60);
        $contract['platform']['node']['maintenance_start'] = $this->day(5);
        $contract['transition_acknowledgements'] = [$this->acknowledgement('platform.node.maintenance_start', $this->day(5), $this->day(-3), acknowledgedOn: $this->day(-20))];
        $this->writeFixture($contract);

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, "A held pre-transition review must satisfy the acknowledgement.\n{$out}");
    }

    #[Test]
    public function an_acknowledgement_whose_review_date_is_not_before_the_transition_fails(): void
    {
        $contract = $this->baseContract();
        $contract['last_reviewed'] = $this->day(-10);
        $contract['review_by'] = $this->day(20);
        $contract['platform']['node']['maintenance_start'] = $this->day(30);
        $contract['transition_acknowledgements'] = [$this->acknowledgement('platform.node.maintenance_start', $this->day(30), $this->day(30))];
        $this->writeFixture($contract);

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "An acknowledgement must commit to a review BEFORE the transition.\n{$out}");
        self::assertStringContainsString('pre-transition review date', $out);
    }

    #[Test]
    public function an_acknowledgement_that_the_contract_does_not_honour_fails(): void
    {
        // Acknowledged review_by is in 20 days, but the contract schedules its
        // review 25 days out and records no review since — a faked acknowledgement.
        $contract = $this->baseContract();
        $contract['last_reviewed'] = $this->day(-10);
        $contract['review_by'] = $this->day(25);
        $contract['platform']['node']['maintenance_start'] = $this->day(30);
        $contract['transition_acknowledgements'] = [$this->acknowledgement('platform.node.maintenance_start', $this->day(30), $this->day(20))];
        $this->writeFixture($contract);

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "The contract must honour the acknowledged review date.\n{$out}");
        self::assertStringContainsString('acknowledgement commits to a pre-transition review', $out);
    }

    #[Test]
    public function an_acknowledgement_for_a_moved_or_unknown_transition_fails(): void
    {
        $contract = $this->baseContract();
        $contract['last_reviewed'] = $this->day(-10);
        $contract['review_by'] = $this->day(20);
        $contract['platform']['node']['maintenance_start'] = $this->day(30);
        $contract['transition_acknowledgements'] = [
            $this->acknowledgement('platform.node.maintenance_start', $this->day(31), $this->day(20)),
            $this->acknowledgement('platform.composer.eol', $this->day(30), $this->day(20)),
        ];
        $this->writeFixture($contract);

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "Stale or unknown acknowledgements must fail.\n{$out}");
        self::assertStringContainsString('transition_acknowledgements[0].date must equal the declared platform.node.maintenance_start date', $out);
        self::assertStringContainsString('transition_acknowledgements[1].transition must name a declared support-reducing transition', $out);
    }

    #[Test]
    public function a_terminal_transition_that_has_passed_fails_closed(): void
    {
        $contract = $this->baseContract();
        $contract['platform']['php']['security_support_end'] = $this->day(-1);
        $this->writeFixture($contract);

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "A passed end-of-security-support date must fail closed.\n{$out}");
        self::assertStringContainsString('platform.php.security_support_end', $out);
        self::assertStringContainsString('has passed', $out);
    }

    /**
     * One case per invariant class the checker owns instead of a policy literal:
     * schema/closed keys, value types, relationships, and safety invariants.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[Test]
    #[DataProvider('contractInvariantViolations')]
    public function a_contract_that_breaks_an_invariant_fails_closed(callable $mutate, string $expectedError): void
    {
        $this->writeFixture($mutate($this->baseContract()), surfaceSource: $this->baseContract());

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "Invariant violation must fail: {$expectedError}\n{$out}");
        self::assertStringContainsString($expectedError, $out);
    }

    /** @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}> */
    public static function contractInvariantViolations(): iterable
    {
        yield 'unknown top-level key (closed schema)' => [
            static function (array $c): array {
                $c['extra'] = true;
                return $c;
            },
            'contract keys differ',
        ];
        yield 'schema_version the checker does not validate' => [
            static function (array $c): array {
                $c['schema_version'] = 2;
                return $c;
            },
            'schema_version must be 1',
        ];
        yield 'contract_version grammar' => [
            static function (array $c): array {
                $c['contract_version'] = 'v1';
                return $c;
            },
            'contract_version must be <profile>-v<revision>',
        ];
        yield 'profile.name must be the versioned profile' => [
            static function (array $c): array {
                $c['profile']['name'] = 'H1';
                return $c;
            },
            'profile.name H1 must be the profile that contract_version s1-v1 versions',
        ];
        yield 'profile.status outside the enum' => [
            static function (array $c): array {
                $c['profile']['status'] = 'production';
                return $c;
            },
            'profile.status must be one of [candidate, supported]',
        ];
        yield 'profile.status supported without verified evidence' => [
            static function (array $c): array {
                $c['profile']['status'] = 'supported';
                return $c;
            },
            'profile.status cannot be supported until both evidence.framework and evidence.consumer are verified',
        ];
        yield 'unbounded php constraint' => [
            static function (array $c): array {
                $c['platform']['php']['constraint'] = '>=8.5.0';
                return $c;
            },
            'platform.php.constraint must be a bounded range',
        ];
        yield 'inverted node constraint' => [
            static function (array $c): array {
                $c['platform']['node']['constraint'] = '>=25.0.0 <24.0.0';
                return $c;
            },
            'platform.node.constraint must be a bounded range',
        ];
        yield 'php active support ending after security support' => [
            static function (array $c): array {
                $c['platform']['php']['active_support_end'] = $c['platform']['php']['security_support_end'];
                return $c;
            },
            'platform.php.active_support_end must precede platform.php.security_support_end',
        ];
        yield 'node maintenance starting after eol' => [
            static function (array $c): array {
                $c['platform']['node']['maintenance_start'] = '2099-01-01';
                return $c;
            },
            'platform.node.maintenance_start must precede platform.node.eol',
        ];
        yield 'impossible calendar date' => [
            static function (array $c): array {
                $c['platform']['node']['eol'] = '2028-02-30';
                return $c;
            },
            'platform.node.eol is not a real calendar date',
        ];
        yield 'composer feature line off its constraint' => [
            static function (array $c): array {
                $c['platform']['composer']['feature_line'] = '2.9';
                return $c;
            },
            'platform.composer.feature_line 2.9 must be the MAJOR.MINOR line of the constraint lower bound 2.10.0',
        ];
        yield 'invented composer eol' => [
            static function (array $c): array {
                $c['platform']['composer']['dated_eol'] = true;
                return $c;
            },
            'platform.composer.dated_eol must be false',
        ];
        yield 'floating runner label' => [
            static function (array $c): array {
                $c['platform']['framework_os']['runner'] = 'ubuntu-latest';
                return $c;
            },
            'platform.framework_os.runner ubuntu-latest is a floating label',
        ];
        yield 'runner not naming the declared version' => [
            static function (array $c): array {
                $c['platform']['framework_os']['version'] = '22.04';
                return $c;
            },
            'platform.framework_os.runner ubuntu-24.04 must end with the declared version 22.04',
        ];
        yield 'non-https source' => [
            static function (array $c): array {
                $c['platform']['sqlite']['source'] = 'http://packages.ubuntu.com/noble/sqlite3';
                return $c;
            },
            'platform.sqlite.source must be an https URL',
        ];
        yield 'notice window out of bounds' => [
            static function (array $c): array {
                $c['lifecycle']['next_transition_notice_days'] = 0;
                return $c;
            },
            'lifecycle.next_transition_notice_days must be an integer between 1 and 365',
        ];
        yield 'backports must be boolean' => [
            static function (array $c): array {
                $c['lifecycle']['backports'] = 'no';
                return $c;
            },
            'lifecycle.backports must be a boolean',
        ];
        yield 'framework test point off the declared runner' => [
            static function (array $c): array {
                $c['evidence']['framework']['test_point'] = 'ubuntu-22.04-x86_64';
                return $c;
            },
            'evidence.framework.test_point ubuntu-22.04-x86_64 must be the declared platform.framework_os runner and architecture ubuntu-24.04-x86_64',
        ];
        yield 'consumer certification presented as complete without proof' => [
            static function (array $c): array {
                $c['evidence']['consumer']['status'] = 'verified';
                return $c;
            },
            'evidence.consumer.status cannot be verified before evidence.consumer.proof is populated',
        ];
        yield 'own profile listed as unsupported' => [
            static function (array $c): array {
                $c['unsupported']['profiles'][] = 'S1';
                return $c;
            },
            "unsupported.profiles cannot list the contract's own profile S1",
        ];
        yield 'declared runtime listed as unsupported database' => [
            static function (array $c): array {
                $c['unsupported']['databases'][] = 'sqlite';
                return $c;
            },
            'unsupported.databases cannot list sqlite, which platform declares as a supported runtime',
        ];
        yield 'browser both supported and unsupported' => [
            static function (array $c): array {
                $c['platform']['browsers']['projects'][] = 'webkit';
                return $c;
            },
            'platform.browsers.projects and unsupported.browsers both list webkit',
        ];
        yield 'empty unsupported list' => [
            static function (array $c): array {
                $c['unsupported']['databases'] = [];
                return $c;
            },
            'unsupported.databases must be a non-empty list',
        ];
        yield 'duplicate unsupported entry' => [
            static function (array $c): array {
                $c['unsupported']['filesystems'][] = 'nfs';
                return $c;
            },
            'unsupported.filesystems repeats nfs',
        ];
    }

    /** @return array<string, string> */
    private function acknowledgement(string $transition, string $date, string $reviewBy, ?string $acknowledgedOn = null): array
    {
        return [
            'transition' => $transition,
            'date' => $date,
            'acknowledged_on' => $acknowledgedOn ?? $this->day(-1),
            'review_by' => $reviewBy,
            'record' => '#2862 fixture acknowledgement',
        ];
    }

    /**
     * A conforming contract whose transition dates sit far outside every
     * notice window, expressed relative to today so the fixture never expires.
     *
     * @return array<string, mixed>
     */
    private function baseContract(): array
    {
        return [
            'schema_version' => 1,
            'contract_version' => 's1-v1',
            'last_reviewed' => $this->day(-1),
            'review_by' => $this->day(30),
            'transition_acknowledgements' => [],
            'profile' => ['name' => 'S1', 'status' => 'candidate', 'scope' => 'single-node SQLite'],
            'platform' => [
                'php' => [
                    'constraint' => '>=8.5.0 <8.6.0',
                    'role' => 'serving-and-cli-runtime',
                    'active_support_end' => $this->day(400),
                    'security_support_end' => $this->day(800),
                    'source' => 'https://www.php.net/supported-versions.php',
                ],
                'composer' => [
                    'constraint' => '>=2.10.0 <3.0.0',
                    'feature_line' => '2.10',
                    'role' => 'dependency-resolution-and-build-tool',
                    'support_model' => 'bug-and-security-fixes-until-next-minor-release',
                    'dated_eol' => false,
                    'source' => 'https://getcomposer.org/download/',
                ],
                'node' => [
                    'constraint' => '>=24.0.0 <25.0.0',
                    'role' => 'admin-build-and-test-tool',
                    'maintenance_start' => $this->day(400),
                    'eol' => $this->day(800),
                    'source' => 'https://github.com/nodejs/Release#release-schedule',
                ],
                'sqlite' => [
                    'constraint' => '>=3.40.0 <4.0.0',
                    'role' => 's1-database-runtime',
                    'security_source' => 'ubuntu-24.04-package-source',
                    'source' => 'https://packages.ubuntu.com/noble/sqlite3',
                ],
                'framework_os' => [
                    'runner' => 'ubuntu-24.04',
                    'version' => '24.04',
                    'architecture' => 'x86_64',
                    'standard_security_maintenance_end' => '2029-05',
                    'source' => 'https://ubuntu.com/about/release-cycle',
                ],
                'browsers' => [
                    'playwright_version' => '1.60.0',
                    'projects' => ['chromium', 'firefox'],
                    'revision_authority' => 'packages/admin/package-lock.json',
                    'source' => 'https://playwright.dev/docs/browsers',
                ],
            ],
            'lifecycle' => [
                'release_model' => 'forward-only-immutable-alpha-tags',
                'fixed_train' => 'latest-tagged-alpha',
                'upgrade_treatment_trains' => 3,
                'backports' => false,
                'security_fix_delivery' => 'newest-tagged-alpha',
                'response_time_sla' => false,
                'review_frequency' => 'quarterly-and-every-tagged-release',
                'next_transition_notice_days' => 90,
            ],
            'evidence' => [
                'framework' => [
                    'status' => 'verified',
                    'owner' => 'waaseyaa/framework',
                    'test_point' => 'ubuntu-24.04-x86_64',
                    'command' => 'php bin/check-support-contract --ci',
                ],
                'consumer' => [
                    'status' => 'pending',
                    'owner' => 'jonesrussell/sheguiandah-waaseyaa',
                    'work_package' => 'S0-SHEG-02',
                    'test_point' => 'ubuntu-24.04-x86_64-ext4-apache-2.4-php-fpm-8.5',
                    'proof' => null,
                ],
            ],
            'unsupported' => [
                'profiles' => ['H1'],
                'databases' => ['mysql', 'postgresql'],
                'filesystems' => ['nfs', 'smb', 'object-mounted', 'clustered'],
                'web_runtimes' => ['non-apache-2.4', 'non-php-fpm-8.5'],
                'browsers' => ['webkit', 'safari', 'branded-browser-specific-behavior'],
            ],
        ];
    }

    private function day(int $offset): string
    {
        return new DateTimeImmutable('today', new DateTimeZone('UTC'))
            ->modify(sprintf('%+d days', $offset))
            ->format('Y-m-d');
    }

    /**
     * Write the fixture contract plus every surface the gate binds, each one
     * DERIVED from the contract so the tree conforms by construction. Callers
     * then drift a single surface (or the contract) to prove fail-closed.
     *
     * @param array<string, mixed> $contract the contract file to write
     * @param array<string, string> $surfaceOverrides relative path => verbatim content
     * @param array<string, mixed>|null $surfaceSource contract to derive surfaces from when $contract is deliberately malformed
     */
    private function writeFixture(array $contract, array $surfaceOverrides = [], ?array $surfaceSource = null): void
    {
        $source = $surfaceSource ?? $contract;
        $platform = $source['platform'];
        $php = $platform['php'];
        $node = $platform['node'];
        $composer = $platform['composer'];
        $browsers = $platform['browsers'];
        $os = $platform['framework_os'];
        $unsupported = $source['unsupported'];
        $lifecycle = $source['lifecycle'];
        $evidence = $source['evidence'];

        [$phpMin] = $this->bounds($php['constraint']);
        [$nodeMin] = $this->bounds($node['constraint']);
        [$composerMin] = $this->bounds($composer['constraint']);
        $phpMinor = implode('.', array_slice(explode('.', $phpMin), 0, 2));
        $nodeMajor = explode('.', $nodeMin)[0];
        $adminDir = dirname($browsers['revision_authority']);

        $files = [
            'support/s1-v1.json' => $this->json($contract),
            'composer.json' => $this->json([
                'name' => 'waaseyaa/framework',
                'require' => [
                    'php' => $this->shortRange($php['constraint']),
                    'ext-pdo_sqlite' => '*',
                    'ext-sqlite3' => '*',
                ],
            ]),
            'composer.lock' => $this->json(['platform' => ['php' => $this->shortRange($php['constraint'])]]),
            '.nvmrc' => $nodeMajor . "\n",
            $adminDir . '/package.json' => $this->json([
                'name' => 'admin',
                'engines' => ['node' => $this->shortRange($node['constraint'])],
            ]),
            $browsers['revision_authority'] => $this->json([
                'packages' => [
                    'node_modules/@playwright/test' => ['version' => $browsers['playwright_version']],
                    'node_modules/playwright-core' => ['version' => $browsers['playwright_version']],
                ],
            ]),
            $adminDir . '/playwright.config.ts' => $this->playwrightConfig($browsers['projects']),
            'tools/dev-runtime-manifest.json' => $this->devRuntimeManifest($node['constraint'], $composer['constraint']),
            'tools/frankenphp-runtime-pin.json' => (string) file_get_contents($this->repoRoot . '/tools/frankenphp-runtime-pin.json'),
            'docs/specs/s1-support-lifecycle.md' => "# S1 support and lifecycle\n\nFixture policy document.\n",
            'SECURITY.md' => $this->securityPolicy($lifecycle['response_time_sla']),
            'README.md' => $this->readme($phpMinor, $unsupported, $evidence['consumer']['status']),
            '.github/workflows/ci.yml' => $this->workflow($os['runner'], $phpMinor, $evidence['framework']['command']),
        ];

        foreach ($surfaceOverrides + $files as $relative => $content) {
            $target = $this->tmpRoot . '/' . $relative;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0o755, true);
            }
            file_put_contents($target, $content);
        }
    }

    /** @return array{0: string, 1: string} */
    private function bounds(string $constraint): array
    {
        self::assertSame(1, preg_match('/^>=(\d+(?:\.\d+){0,2}) <(\d+(?:\.\d+){0,2})$/', $constraint, $m), $constraint);

        return [$m[1], $m[2]];
    }

    /** `>=8.5.0 <8.6.0` -> `>=8.5 <8.6`, the form composer.json and engines.node use. */
    private function shortRange(string $constraint): string
    {
        [$min, $max] = $this->bounds($constraint);
        $trim = static fn(string $v): string => (string) preg_replace('/(\.0)+$/', '', $v);

        return sprintf('>=%s <%s', $trim($min), $trim($max));
    }

    /** @param list<string> $projects */
    private function playwrightConfig(array $projects): string
    {
        $blocks = array_map(
            static fn(string $name): string => "    {\n      name: '{$name}',\n      use: { ...devices['Desktop'] },\n    },",
            $projects,
        );

        return "import { defineConfig, devices } from '@playwright/test';\n\nexport default defineConfig({\n  projects: [\n"
            . implode("\n", $blocks) . "\n  ],\n});\n";
    }

    /**
     * The managed toolchain must sit inside the contract's constraints. Keep the
     * real pins when they already do (so the fixture mirrors the repository);
     * synthesise in-range pins when a fixture moves the constraint.
     */
    private function devRuntimeManifest(string $nodeConstraint, string $composerConstraint): string
    {
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($this->repoRoot . '/tools/dev-runtime-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        [$nodeMin, $nodeMax] = $this->bounds($nodeConstraint);
        [$composerMin, $composerMax] = $this->bounds($composerConstraint);
        $realNode = ltrim((string) $manifest['tools']['node']['version'], 'v');
        $realComposer = (string) $manifest['tools']['composer']['version'];
        $nodeVersion = 'v' . (version_compare($realNode, $nodeMin, '>=') && version_compare($realNode, $nodeMax, '<') ? $realNode : $nodeMin);
        $composerVersion = version_compare($realComposer, $composerMin, '>=') && version_compare($realComposer, $composerMax, '<') ? $realComposer : $composerMin;
        $manifest['tools']['node']['version'] = $nodeVersion;
        $manifest['tools']['node']['root'] = "node-{$nodeVersion}-linux-x64";
        $manifest['tools']['node']['archive'] = "node-{$nodeVersion}-linux-x64.tar.xz";
        $manifest['tools']['node']['url'] = "https://nodejs.org/dist/{$nodeVersion}/node-{$nodeVersion}-linux-x64.tar.xz";
        $manifest['tools']['composer']['version'] = $composerVersion;
        $manifest['tools']['composer']['url'] = "https://getcomposer.org/download/{$composerVersion}/composer.phar";

        return $this->json($manifest);
    }

    private function securityPolicy(bool $responseTimeSla): string
    {
        $sla = $responseTimeSla ? 'a best-effort response-time SLA applies' : 'There is no response-time SLA.';

        return "# Security policy\n\nUse GitHub private vulnerability reporting. {$sla}\n"
            . "Authentication is not authorization. No additional accepted risk is recorded.\n";
    }

    /** @param array<string, list<string>> $unsupported */
    private function readme(string $phpMinor, array $unsupported, string $consumerStatus): string
    {
        $pending = $consumerStatus === 'pending' ? 'S1 consumer certification is still pending its named downstream evidence.' : 'S1 consumer certification is verified.';
        $display = static fn(string $token): string => ['mysql' => 'MySQL', 'postgresql' => 'PostgreSQL', 'webkit' => 'WebKit', 'safari' => 'Safari'][$token] ?? $token;
        $plain = static fn(array $tokens): array => array_values(array_filter($tokens, static fn(string $t): bool => preg_match('/^[a-z0-9]+$/i', $t) === 1));
        $boundary = implode(', ', [
            implode(', ', $unsupported['profiles']),
            implode('/', array_map($display, $plain($unsupported['databases']))),
            'remote/shared filesystems',
            implode('/', array_map($display, $plain($unsupported['browsers']))),
        ]);

        return "# Fixture\n\nRequires PHP `{$phpMinor}` and SQLite for the S1 profile.\n\n{$pending} {$boundary},\n"
            . "and unlisted web runtimes are not supported claims.\n";
    }

    private function workflow(string $runner, string $phpMinor, string $command): string
    {
        $pinned = static fn(string $name): string => "  {$name}:\n    runs-on: {$runner}\n    steps:\n      - run: true\n";

        return "name: CI\non: [push]\njobs:\n"
            . "  support-contract:\n"
            . "    name: support/s1-contract\n"
            . "    runs-on: {$runner}\n"
            . "    steps:\n"
            . "      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1\n"
            . "      - name: Set up PHP\n"
            . "        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2.37.2\n"
            . "        with:\n"
            . "          php-version: '{$phpMinor}'\n"
            . "      - name: Set up Node.js\n"
            . "        uses: actions/setup-node@820762786026740c76f36085b0efc47a31fe5020 # v7.0.0\n"
            . "        with:\n"
            . "          node-version-file: '.nvmrc'\n"
            . "      - name: Verify bounded S1 support contract\n"
            . "        run: {$command} --evidence=support-contract-evidence.json\n"
            . "      - uses: actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a # v7.0.1\n"
            . "        with:\n"
            . "          path: support-contract-evidence.json\n"
            . "          retention-days: 30\n"
            . $pinned('ci-unit-tests')
            . $pinned('ci-playwright-smoke')
            . $pinned('verify-gates');
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param list<string> $extraArguments
     * @return array{0: int, 1: string}
     */
    private function runGate(array $extraArguments = []): array
    {
        $process = new Process(
            [PHP_BINARY, $this->gate, '--root=' . $this->tmpRoot, ...$extraArguments],
            $this->tmpRoot,
            self::replacingEnv(['PATH' => getenv('PATH') ?: '/usr/bin:/bin']),
            null,
            null,
        );
        $exit = $process->run();

        return [$exit, $process->getOutput() . $process->getErrorOutput()];
    }

    /**
     * @param array<string, string> $explicit
     * @return array<string, string|false>
     */
    private static function replacingEnv(array $explicit): array
    {
        $env = $explicit;
        foreach (array_keys($_ENV + getenv()) as $name) {
            if (!array_key_exists((string) $name, $env)) {
                $env[(string) $name] = false;
            }
        }

        return $env;
    }
}
