<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Regression proof for #2926: a stale local vendor/ must surface as an
 * actionable precondition failure with its own exit code, never as a PHP
 * fatal (exit 255 + stack trace) and never as a repository defect.
 *
 * Every case builds a deliberately stale or incomplete vendor fixture under a
 * fresh temp directory — a fake composer.lock / vendor/composer/installed.json
 * pair, a fake dumped PSR-4 map, and a fake vendor/autoload.php — and exercises
 * the shared precondition (bin/lib/vendor-freshness.php) directly, then through
 * the two gate scripts the issue named and through bin/check-pr-preflight.
 */
#[CoversNothing]
final class VendorFreshnessPreconditionTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/bin/lib/vendor-freshness.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            new Filesystem()->remove($fixture);
        }
        $this->fixtures = [];
    }

    // ── the shared precondition itself ───────────────────────────────────────

    #[Test]
    public function the_precondition_exit_code_is_distinct_from_defect_and_infrastructure_codes(): void
    {
        self::assertTrue(defined('VENDOR_FRESHNESS_EXIT_CODE'));
        self::assertNotContains(VENDOR_FRESHNESS_EXIT_CODE, [0, 1, 2, 255], 'The precondition must not collide with pass, defect, infrastructure, or PHP fatal exit codes.');
    }

    #[Test]
    public function a_fresh_vendor_reports_no_problem(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa'), self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            lockedDev: [self::pkg('phpunit/phpunit', '10.5.0', 'cccc')],
            installed: [self::pkg('opis/json-schema', '2.6.0', 'aaaa'), self::pkg('waaseyaa/cli', 'dev-main', 'bbbb'), self::pkg('phpunit/phpunit', '10.5.0', 'cccc')],
            declaredNamespaces: ['Waaseyaa\\CLI\\Io\\'],
            dumpedNamespaces: ['Waaseyaa\\CLI\\Io\\'],
        );

        self::assertNull(vendor_freshness_problem($root));
    }

    #[Test]
    public function a_locked_package_absent_from_installed_json_is_stale(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            lockedDev: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer install', $problem['fix']);
        self::assertStringContainsString('opis/json-schema', $problem['detail']);
    }

    #[Test]
    public function a_name_matched_but_version_mismatched_package_is_stale(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [self::pkg('opis/json-schema', '2.5.0', 'aaaa')],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer install', $problem['fix']);
        self::assertStringContainsString('opis/json-schema', $problem['detail']);
        self::assertStringContainsString('2.6.0', $problem['detail']);
        self::assertStringContainsString('2.5.0', $problem['detail']);
    }

    #[Test]
    public function a_name_and_version_matched_but_reference_mismatched_package_is_stale(): void
    {
        // The monorepo's own path-repo packages are always dev-main; only the
        // locked reference tells one checkout's package bytes from another's.
        $root = $this->fixture(
            locked: [self::pkg('waaseyaa/cli', 'dev-main', 'aaaa')],
            installed: [self::pkg('waaseyaa/cli', 'dev-main', 'ffff')],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer install', $problem['fix']);
        self::assertStringContainsString('waaseyaa/cli', $problem['detail']);
    }

    #[Test]
    public function an_installed_package_absent_from_the_lock_is_stale(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            installed: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb'), self::pkg('acme/leftover', '1.0.0', 'dddd')],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer install', $problem['fix']);
        self::assertStringContainsString('acme/leftover', $problem['detail']);
    }

    #[Test]
    public function a_declared_psr4_namespace_missing_from_the_dumped_map_is_stale(): void
    {
        // The #2926 StdinSource shape: root composer.json autoload-dev maps
        // Waaseyaa\CLI\Io\ but the dumped autoloader predates the mapping.
        $root = $this->fixture(
            locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            installed: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            declaredNamespaces: ['Waaseyaa\\CLI\\Io\\'],
            dumpedNamespaces: [],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer dump-autoload', $problem['fix']);
        self::assertStringContainsString('Waaseyaa\\CLI\\Io\\', $problem['detail']);
    }

    #[Test]
    public function a_locked_package_psr4_namespace_missing_from_the_dumped_map_is_stale(): void
    {
        // Composer dumps every LOCKED package's `autoload` PSR-4 roots (never
        // its autoload-dev), so a missing one means the map predates the
        // install — the same fix as a missing root namespace.
        $locked = self::pkg('waaseyaa/cli', 'dev-main', 'bbbb') + [
            'autoload' => ['psr-4' => ['Waaseyaa\\CLI\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Waaseyaa\\CLI\\Tests\\' => 'tests/']],
        ];
        $root = $this->fixture(locked: [$locked], installed: [$locked], dumpedNamespaces: []);

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer dump-autoload', $problem['fix']);
        self::assertStringContainsString('Waaseyaa\\CLI\\ (waaseyaa/cli)', $problem['detail']);
        self::assertStringNotContainsString('Waaseyaa\\CLI\\Tests\\', $problem['detail'], 'A dependency autoload-dev root is never dumped, so its absence is not staleness.');

        $fresh = $this->fixture(locked: [$locked], installed: [$locked], dumpedNamespaces: ['Waaseyaa\\CLI\\']);
        self::assertNull(vendor_freshness_problem($fresh));
    }

    #[Test]
    public function a_missing_vendor_directory_is_reported_not_fatal(): void
    {
        $root = $this->fixture(locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')], installed: [], withVendor: false);

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer install', $problem['fix']);
    }

    #[Test]
    public function a_missing_composer_lock_is_reported_not_fatal(): void
    {
        $root = $this->fixture(locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')], installed: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')]);
        unlink($root . '/composer.lock');

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertStringContainsString('composer.lock', $problem['detail']);
    }

    #[Test]
    public function the_rendered_message_is_actionable_and_names_the_calling_tool(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        $message = vendor_freshness_message($problem, 'some-gate');

        self::assertStringContainsString('some-gate:', $message);
        self::assertStringContainsString('vendor/ is stale relative to composer.lock', $message);
        self::assertStringContainsString('run `composer install`', $message);
        self::assertStringContainsString('opis/json-schema', $message);
    }

    // ── bin/check-delivery-agent-events ──────────────────────────────────────

    #[Test]
    public function delivery_agent_gate_reports_a_stale_vendor_instead_of_a_fatal_when_opis_is_missing(): void
    {
        // opis/json-schema is locked (root require-dev) but absent from
        // installed.json — the exact #2926 condition. Before the fix this was
        // `Error: Class "Opis\JsonSchema\Validator" not found`, exit 255.
        $root = $this->fixture(
            locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            lockedDev: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
        );
        $this->seedDeliveryGate($root);

        $result = $this->runProcess([PHP_BINARY, $root . '/bin/check-delivery-agent-events', '--self-test'], $root);

        self::assertSame(VENDOR_FRESHNESS_EXIT_CODE, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('vendor/ is stale relative to composer.lock', $result['stderr']);
        self::assertStringContainsString('composer install', $result['stderr']);
        self::assertStringContainsString('opis/json-schema', $result['stderr']);
        self::assertStringNotContainsString('Fatal error', $result['stderr'] . $result['stdout']);
        self::assertStringNotContainsString('Uncaught', $result['stderr'] . $result['stdout']);
        self::assertStringNotContainsString('PASS', $result['stdout']);
    }

    #[Test]
    public function delivery_agent_gate_reports_an_unloadable_validator_even_when_vendor_metadata_looks_fresh(): void
    {
        // Metadata agrees with the lock, but the autoloader cannot provide the
        // validator class (e.g. the package directory was deleted by hand).
        // The gate must still refuse with the precondition code, never fatal.
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
        );
        $this->seedDeliveryGate($root);

        $result = $this->runProcess([PHP_BINARY, $root . '/bin/check-delivery-agent-events', '--self-test'], $root);

        self::assertSame(VENDOR_FRESHNESS_EXIT_CODE, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('opis/json-schema', $result['stderr']);
        self::assertStringContainsString('composer install', $result['stderr']);
        self::assertStringNotContainsString('Fatal error', $result['stderr'] . $result['stdout']);
        self::assertStringNotContainsString('Uncaught', $result['stderr'] . $result['stdout']);
    }

    // ── bin/check-pr-preflight ───────────────────────────────────────────────

    #[Test]
    public function preflight_short_circuits_with_the_precondition_code_before_running_any_gate(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [],
        );
        $this->seedPreflight($root);
        $manifest = $this->writeManifest($root);

        $result = $this->runProcess([PHP_BINARY, $root . '/bin/check-pr-preflight', '--manifest=' . $manifest], $root);

        self::assertSame(VENDOR_FRESHNESS_EXIT_CODE, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('vendor/ is stale relative to composer.lock', $result['stderr']);
        self::assertStringContainsString('composer install', $result['stderr']);
        self::assertStringNotContainsString('gate-ran', $result['stdout'], 'No gate may run against a stale vendor/.');
        self::assertStringNotContainsString('FAIL', $result['stdout'], 'A stale vendor/ is not a gate failure.');
    }

    #[Test]
    public function preflight_runs_its_gates_when_vendor_is_fresh(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
        );
        $this->seedPreflight($root);
        $manifest = $this->writeManifest($root);

        $result = $this->runProcess([PHP_BINARY, $root . '/bin/check-pr-preflight', '--manifest=' . $manifest], $root);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        // Preflight prints a gate's captured output only on failure; a green
        // gate shows as its "ok" roster line.
        self::assertStringContainsString('ok   synthetic-gate', $result['stdout']);
        self::assertStringNotContainsString('vendor/ is stale', $result['stderr']);
    }

    #[Test]
    public function preflight_list_mode_does_not_require_a_fresh_vendor(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [],
        );
        $this->seedPreflight($root);
        $manifest = $this->writeManifest($root);

        $result = $this->runProcess([PHP_BINARY, $root . '/bin/check-pr-preflight', '--list', '--manifest=' . $manifest], $root);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('synthetic-gate', $result['stdout']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{name: string, version: string, dist: array{type: string, reference: string}} */
    private static function pkg(string $name, string $version, string $reference): array
    {
        return ['name' => $name, 'version' => $version, 'dist' => ['type' => 'zip', 'reference' => $reference]];
    }

    /**
     * @param list<array<string, mixed>> $locked
     * @param list<array<string, mixed>> $lockedDev
     * @param list<array<string, mixed>> $installed
     * @param list<string> $declaredNamespaces
     * @param list<string> $dumpedNamespaces
     */
    private function fixture(
        array $locked,
        array $installed,
        array $lockedDev = [],
        array $declaredNamespaces = [],
        array $dumpedNamespaces = [],
        bool $withVendor = true,
    ): string {
        $root = sys_get_temp_dir() . '/waaseyaa-vendor-fresh-' . bin2hex(random_bytes(6));
        $fs = new Filesystem();
        $fs->mkdir($root);
        $this->fixtures[] = $root;

        $fs->dumpFile($root . '/composer.json', json_encode([
            'name' => 'waaseyaa/framework',
            'autoload' => ['psr-4' => (object) []],
            'autoload-dev' => ['psr-4' => (object) array_fill_keys($declaredNamespaces, 'x/')],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $fs->dumpFile($root . '/composer.lock', json_encode([
            'packages' => $locked,
            'packages-dev' => $lockedDev,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        if (!$withVendor) {
            return $root;
        }

        $fs->mkdir($root . '/vendor/composer');
        // A fake autoloader: nothing is actually loadable from this vendor/.
        $fs->dumpFile($root . '/vendor/autoload.php', "<?php\nreturn null;\n");
        $fs->dumpFile($root . '/vendor/composer/installed.json', json_encode([
            'packages' => $installed,
            'dev' => true,
            'dev-package-names' => array_column($lockedDev, 'name'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $entries = '';
        foreach ($dumpedNamespaces as $namespace) {
            $entries .= '    ' . var_export($namespace, true) . " => array('x'),\n";
        }
        $fs->dumpFile($root . '/vendor/composer/autoload_psr4.php', "<?php\n\nreturn array(\n{$entries});\n");

        return $root;
    }

    private function seedDeliveryGate(string $root): void
    {
        $fs = new Filesystem();
        $fs->mkdir($root . '/bin/lib');
        $fs->mkdir($root . '/ops/observability');
        foreach ([
            'bin/check-delivery-agent-events',
            'bin/lib/delivery-agent-event-set.php',
            'bin/lib/vendor-freshness.php',
            'bin/git',
            'ops/observability/delivery-agent-event-v1.schema.json',
        ] as $path) {
            $fs->copy($this->root . '/' . $path, $root . '/' . $path);
        }
        chmod($root . '/bin/check-delivery-agent-events', 0o755);
        chmod($root . '/bin/git', 0o755);
    }

    private function seedPreflight(string $root): void
    {
        $fs = new Filesystem();
        $fs->mkdir($root . '/bin/lib');
        foreach (['bin/check-pr-preflight', 'bin/lib/vendor-freshness.php'] as $path) {
            $fs->copy($this->root . '/' . $path, $root . '/' . $path);
        }
        chmod($root . '/bin/check-pr-preflight', 0o755);
    }

    private function writeManifest(string $root): string
    {
        $manifest = $root . '/preflight-manifest.json';
        file_put_contents($manifest, json_encode([
            'schema_version' => 1,
            'gates' => [
                ['id' => 'synthetic-gate', 'run' => 'echo gate-ran', 'repair' => 'n/a', 'profile' => 'default', 'enforced_by' => 'workflow:ci.yml'],
            ],
        ], JSON_THROW_ON_ERROR));

        return $manifest;
    }

    /**
     * @param list<string> $command
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, string $cwd): array
    {
        $process = new Process($command, $cwd, null, null, 120);
        $exit = $process->run();

        return ['exit' => $exit, 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }
}
