<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Executes bin/check-admin-coercion-patterns against a hermetic copy of the
 * admin app tree (#3096). On Windows it runs under Git for Windows Bash, the
 * shell a Git pre-push hook uses; that grep rejects -P in its default locale,
 * so detection must not depend on PCRE. The self-test must count only a real
 * detection of its planted violator, never a scan error, as a pass.
 */
#[CoversNothing]
final class AdminCoercionPatternsGateTest extends TestCase
{
    private const string GATE = 'bin/check-admin-coercion-patterns';
    private const string APP = 'packages/admin/app';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_admin_coercion_' . uniqid('', true);
        mkdir($this->root . '/bin', 0o755, true);
        mkdir($this->root . '/' . self::APP, 0o755, true);
        copy(dirname(__DIR__, 2) . '/' . self::GATE, $this->root . '/' . self::GATE);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function reports_every_raw_coercion_form_with_its_location(): void
    {
        $this->writeCleanFixtures();
        $this->write('composables/useFlag.ts', "const a = cfg === '1'\nconst unrelated = 1\nconst b = cfg === \"0\"\n");
        $this->write('pages/page.vue', "<script setup lang=\"ts\">\nconst c = String(v) === '0'\n</script>\n");
        $this->write('deep/nested/flag.ts', "// \u{2014} non-ASCII comment\nconst d = x === \"1\"\n");

        [$exit, $output] = $this->runGate();

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('check-admin-coercion-patterns: FAILED', $output);
        self::assertStringContainsString("packages/admin/app/composables/useFlag.ts:1:const a = cfg === '1'", $output);
        self::assertStringContainsString('packages/admin/app/composables/useFlag.ts:3:const b = cfg === "0"', $output);
        self::assertStringContainsString("packages/admin/app/pages/page.vue:2:const c = String(v) === '0'", $output);
        self::assertStringContainsString('packages/admin/app/deep/nested/flag.ts:2:const d = x === "1"', $output);
        self::assertSame(4, substr_count($output, 'packages/admin/app/'), 'Exempted, excluded and near-miss lines must not be reported.');
    }

    #[Test]
    public function passes_exempt_excluded_and_near_miss_lines(): void
    {
        $this->writeCleanFixtures();

        [$exit, $output] = $this->runGate();

        self::assertSame(0, $exit, $output);
        self::assertSame('check-admin-coercion-patterns: OK', $output);
    }

    #[Test]
    public function self_test_passes_through_a_genuine_detection_and_cleans_up(): void
    {
        $this->writeCleanFixtures();

        [$exit, $output] = $this->runGate('--self-test');

        self::assertSame(0, $exit, $output);
        self::assertSame('check-admin-coercion-patterns --self-test: PASSED (gate correctly exits 1 on synthetic violation)', $output);
        self::assertSame([], glob($this->root . '/' . self::APP . '/_selftest_violator_*') ?: []);
    }

    #[Test]
    public function self_test_fails_when_the_scan_errors_instead_of_detecting(): void
    {
        $this->writeCleanFixtures();
        mkdir($this->root . '/fakebin');
        file_put_contents($this->root . '/fakebin/grep', "#!/usr/bin/env bash\necho 'fake grep: simulated scan failure' >&2\nexit 2\n");
        chmod($this->root . '/fakebin/grep', 0o755);

        $process = new Process(
            [$this->bash(), '-c', 'PATH="$PWD/fakebin:$PATH" exec bash ' . self::GATE . ' --self-test'],
            $this->root,
        );
        $exit = $process->run();
        $output = trim($process->getOutput() . $process->getErrorOutput());

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('self-test: FAILED', $output);
        self::assertStringNotContainsString('PASSED', $output);
        self::assertStringContainsString('fake grep: simulated scan failure', $output, 'The failure must come from the injected scan error.');
    }

    private function writeCleanFixtures(): void
    {
        $this->write('pages/login.vue', "// \u{2014} non-ASCII comment\nif (value === '1') { // allow-coercion: CSS custom property, not runtime config\n");
        $this->write('composables/configCoercion.ts', "export const asFlag = (v: string) => v === '1'\n");
        $this->write('utils/flag.test.ts', "expect(x === '1')\n");
        $this->write('utils/flag.spec.ts', "expect(x === '0')\n");
        $this->write('utils/not-scanned.js', "const n = x === '1'\n");
        $this->write('utils/near-miss.ts', "x == '1'\nx !== '0'\nx === '10'\nx === 1\n");
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . self::APP . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, $contents);
    }

    /** @return array{int, string} */
    private function runGate(string ...$arguments): array
    {
        $process = new Process([$this->bash(), self::GATE, ...$arguments], $this->root);
        $exit = $process->run();

        return [$exit, trim($process->getOutput() . $process->getErrorOutput())];
    }

    private function bash(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'bash';
        }

        // A bare "bash" is the System32 WSL launcher under CreateProcess; the
        // gate must be proven under Git for Windows Bash, which hooks use.
        foreach (preg_split('/\R/', (string) shell_exec('where git 2>NUL')) ?: [] as $git) {
            $candidate = dirname($git, 2) . '/bin/bash.exe';
            if ($git !== '' && is_file($candidate)) {
                return $candidate;
            }
        }
        self::fail('Tests on Windows must use Git for Windows Bash, not WSL Bash.');
    }
}
