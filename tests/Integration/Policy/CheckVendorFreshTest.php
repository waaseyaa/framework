<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Policy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\Tests\Support\ReplacesProcessEnvironment;

/**
 * Integration tests for bin/check-vendor-fresh — the pre-push fast guard that
 * catches a stale local vendor/ before the full-Unit phpunit job, so a
 * developer sees one actionable line instead of hundreds of cryptic
 * "Class ... not found" errors.
 *
 * Each test builds a minimal ephemeral fixture tree under sys_get_temp_dir()
 * (composer.json + composer.lock + vendor/composer/{installed.json,
 * autoload_psr4.php} + vendor/autoload.php), runs the live script via
 * Symfony Process with ROOT_DIR injected, and asserts the exit code + message.
 *
 * Failing-first: against the pre-guard state (no bin/check-vendor-fresh) these
 * tests error because the script does not exist; they pass only once the guard
 * ships. The stale-state cases encode the guard's contract (one actionable line
 * naming the exact fix) that did not exist before.
 */
#[CoversNothing]
final class CheckVendorFreshTest extends TestCase
{
    use ReplacesProcessEnvironment;

    private const BIN = __DIR__ . '/../../../bin/check-vendor-fresh';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                new Filesystem()->remove($dir);
            }
        }
        $this->tempDirs = [];
    }

    // ── correctly-wired states must pass cleanly (no false positives) ─────────

    #[Test]
    public function a_fresh_in_sync_checkout_passes(): void
    {
        $root = $this->makeFixture(
            lockedPackages: ['waaseyaa/frankenphp', 'waaseyaa/wayfinding'],
            installedPackages: ['waaseyaa/frankenphp', 'waaseyaa/wayfinding'],
            declaredNamespaces: ['Waaseyaa\\CLI\\Testing\\', 'Waaseyaa\\Workspace\\'],
            dumpedNamespaces: ['Waaseyaa\\CLI\\Testing\\', 'Waaseyaa\\Workspace\\'],
        );

        [$exit, $stdout] = $this->runScript($root);

        self::assertSame(0, $exit, 'A fresh, in-sync vendor/ must pass.');
        self::assertStringContainsString('OK', $stdout);
    }

    #[Test]
    public function a_path_repo_wired_checkout_with_composer_local_json_passes(): void
    {
        // Mirrors the documented local-dev wiring: path-repo waaseyaa/* packages
        // installed + symlinked, plus a gitignored composer.local.json present.
        // The guard reads the root composer.json/lock — composer.local.json must
        // not produce a false positive.
        $root = $this->makeFixture(
            lockedPackages: ['waaseyaa/frankenphp'],
            installedPackages: ['waaseyaa/frankenphp'],
            declaredNamespaces: ['Waaseyaa\\CLI\\Testing\\'],
            dumpedNamespaces: ['Waaseyaa\\CLI\\Testing\\'],
        );
        file_put_contents(
            $root . '/composer.local.json',
            json_encode(['repositories' => [['type' => 'path', 'url' => '../waaseyaa/packages/*']]]) . "\n",
        );

        [$exit] = $this->runScript($root);

        self::assertSame(0, $exit, 'A path-repo-wired checkout (with composer.local.json) must pass cleanly.');
    }

    // ── stale states must fail fast with the exact fix ───────────────────────

    #[Test]
    public function an_uninstalled_locked_package_is_flagged_with_the_install_fix(): void
    {
        // composer.lock has wayfinding, vendor/ does not — the historical
        // un-installed-path-repo condition.
        $root = $this->makeFixture(
            lockedPackages: ['waaseyaa/frankenphp', 'waaseyaa/wayfinding'],
            installedPackages: ['waaseyaa/frankenphp'],
            declaredNamespaces: ['Waaseyaa\\CLI\\Testing\\'],
            dumpedNamespaces: ['Waaseyaa\\CLI\\Testing\\'],
        );

        [$exit, , $stderr] = $this->runScript($root);

        self::assertSame(1, $exit, 'An uninstalled locked package must fail fast.');
        self::assertStringContainsString('composer install', $stderr);
        self::assertStringContainsString('wayfinding', $stderr);
    }

    #[Test]
    public function a_stale_autoload_dump_is_flagged_with_the_dump_fix(): void
    {
        // composer.json declares Waaseyaa\CLI\Testing\ but the dumped autoloader
        // does not have it — the historical edited-but-not-re-dumped condition.
        $root = $this->makeFixture(
            lockedPackages: ['waaseyaa/frankenphp'],
            installedPackages: ['waaseyaa/frankenphp'],
            declaredNamespaces: ['Waaseyaa\\CLI\\Testing\\'],
            dumpedNamespaces: [],
        );

        [$exit, , $stderr] = $this->runScript($root);

        self::assertSame(1, $exit, 'A stale autoload dump must fail fast.');
        self::assertStringContainsString('composer dump-autoload', $stderr);
        self::assertStringContainsString('Waaseyaa\\CLI\\Testing', $stderr);
    }

    #[Test]
    public function a_missing_vendor_directory_is_flagged_with_the_install_fix(): void
    {
        $root = $this->makeFixture(
            lockedPackages: ['waaseyaa/frankenphp'],
            installedPackages: ['waaseyaa/frankenphp'],
            declaredNamespaces: [],
            dumpedNamespaces: [],
            withVendor: false,
        );

        [$exit, , $stderr] = $this->runScript($root);

        self::assertSame(1, $exit, 'A missing vendor/ must fail fast.');
        self::assertStringContainsString('composer install', $stderr);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param list<string> $lockedPackages
     * @param list<string> $installedPackages
     * @param list<string> $declaredNamespaces
     * @param list<string> $dumpedNamespaces
     */
    private function makeFixture(
        array $lockedPackages,
        array $installedPackages,
        array $declaredNamespaces,
        array $dumpedNamespaces,
        bool $withVendor = true,
    ): string {
        $base = sys_get_temp_dir() . '/waaseyaa_vendorfresh_test_' . uniqid('', true);
        mkdir($base, 0o755, true);
        $this->tempDirs[] = $base;

        file_put_contents(
            $base . '/composer.json',
            json_encode([
                'name' => 'waaseyaa/framework',
                'autoload-dev' => ['psr-4' => array_fill_keys($declaredNamespaces, 'x')],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        file_put_contents(
            $base . '/composer.lock',
            json_encode([
                'packages' => array_map(static fn(string $n): array => ['name' => $n], $lockedPackages),
                'packages-dev' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        if ($withVendor) {
            mkdir($base . '/vendor/composer', 0o755, true);
            file_put_contents($base . '/vendor/autoload.php', "<?php\nreturn null;\n");
            file_put_contents(
                $base . '/vendor/composer/installed.json',
                json_encode([
                    'packages' => array_map(static fn(string $n): array => ['name' => $n], $installedPackages),
                    'dev' => true,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
            $entries = '';
            foreach ($dumpedNamespaces as $ns) {
                $entries .= '    ' . var_export($ns, true) . " => array('x'),\n";
            }
            file_put_contents(
                $base . '/vendor/composer/autoload_psr4.php',
                "<?php\n\nreturn array(\n{$entries});\n",
            );
        }

        return $base;
    }

    /**
     * Run the live bin/check-vendor-fresh with ROOT_DIR pointed at the fixture.
     *
     * @return array{int, string, string} [exitCode, stdout, stderr]
     */
    private function runScript(string $fixtureRoot): array
    {
        // proc_open drained stdout to EOF before touching stderr, so a script
        // that filled the ~64KB stderr buffer wedged both sides (#2491).
        // proc_open received an EXPLICIT env array, which REPLACES the child
        // environment; Symfony Process merges instead, so replacingEnv() pins
        // every inherited name the caller did not set. stdin was opened and
        // immediately closed without a write, so $input null is equivalent.
        // timeout null preserves the previous absence of any time bound.
        $env = array_merge(getenv(), ['ROOT_DIR' => $fixtureRoot]);

        $process = new Process(
            [PHP_BINARY, self::BIN],
            $fixtureRoot,
            self::replacingEnv($env),
            null,
            null,
        );
        $exitCode = $process->run();

        return [$exitCode, $process->getOutput(), $process->getErrorOutput()];
    }

}
