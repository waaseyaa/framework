<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase24;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Integration test for bin/check-getquery-bindings.
 *
 * Verifies that the gate correctly rejects unbound getQuery()->execute() callsites
 * and passes bound ones. Uses --scan-dir and --baseline flags for test isolation.
 *
 * FR-005 SC-003: gate must exit non-zero when fed a synthetic unbound callsite.
 */
#[CoversNothing]
final class GetQueryBindingsGateTest extends TestCase
{
    private string $tempDir = '';
    private string $tempBaseline = '';
    private string $scriptPath = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_gqbtest_' . uniqid('', true);
        mkdir($this->tempDir . '/src', 0o755, true);

        $this->tempBaseline = sys_get_temp_dir() . '/waaseyaa_gqb_baseline_' . uniqid('', true) . '.txt';

        $this->scriptPath = dirname(__DIR__, 3) . '/bin/check-getquery-bindings';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tempDir);
        if (file_exists($this->tempBaseline)) {
            unlink($this->tempBaseline);
        }
    }

    #[Test]
    public function unboundCallsiteFailsGate(): void
    {
        // Synthetic offender: getQuery()->condition(...)->execute() with no setAccount or accessCheck(false)
        file_put_contents(
            $this->tempDir . '/src/OffenderClass.php',
            '<?php' . "\n" . '$storage->getQuery()->condition("status", 1)->execute();' . "\n",
        );

        // Write an empty baseline (no exemptions)
        file_put_contents($this->tempBaseline, "# empty baseline\n");

        $output = [];
        $exitCode = 0;
        exec(
            sprintf(
                'php %s --scan-dir %s --baseline %s 2>&1',
                escapeshellarg($this->scriptPath),
                escapeshellarg($this->tempDir . '/src'),
                escapeshellarg($this->tempBaseline),
            ),
            $output,
            $exitCode,
        );

        self::assertNotSame(0, $exitCode, 'Expected non-zero exit for unbound callsite. Output: ' . implode("\n", $output));
        self::assertStringContainsString('NEW unbound getQuery()->execute() callsite', implode("\n", $output));
    }

    #[Test]
    public function boundWithSetAccountPassesGate(): void
    {
        // Bound via setAccount() — should pass
        file_put_contents(
            $this->tempDir . '/src/BoundAccountClass.php',
            '<?php' . "\n" . '$storage->getQuery()->setAccount($account)->condition("status", 1)->execute();' . "\n",
        );

        file_put_contents($this->tempBaseline, "# empty baseline\n");

        $output = [];
        $exitCode = 0;
        exec(
            sprintf(
                'php %s --scan-dir %s --baseline %s 2>&1',
                escapeshellarg($this->scriptPath),
                escapeshellarg($this->tempDir . '/src'),
                escapeshellarg($this->tempBaseline),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, 'Expected zero exit for bound callsite (setAccount). Output: ' . implode("\n", $output));
    }

    #[Test]
    public function boundWithAccessCheckFalsePassesGate(): void
    {
        // Bound via accessCheck(false) — explicit opt-out, should pass
        file_put_contents(
            $this->tempDir . '/src/BoundAccessCheckClass.php',
            '<?php' . "\n" . '$storage->getQuery()->accessCheck(false)->condition("status", 1)->execute();' . "\n",
        );

        file_put_contents($this->tempBaseline, "# empty baseline\n");

        $output = [];
        $exitCode = 0;
        exec(
            sprintf(
                'php %s --scan-dir %s --baseline %s 2>&1',
                escapeshellarg($this->scriptPath),
                escapeshellarg($this->tempDir . '/src'),
                escapeshellarg($this->tempBaseline),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, 'Expected zero exit for accessCheck(false). Output: ' . implode("\n", $output));
    }

    #[Test]
    public function offenderInBaselinePassesGate(): void
    {
        // An unbound callsite that IS in the baseline (with inline comment) should pass
        file_put_contents(
            $this->tempDir . '/src/ExemptClass.php',
            '<?php' . "\n" . '$storage->getQuery()->condition("status", 1)->execute();' . "\n",
        );

        // Determine the relative path the scanner will emit
        $scanSrc = $this->tempDir . '/src';
        // The script computes relative to the repo root (dirname of script dir),
        // but with --scan-dir the path will include the full temp dir path.
        // To handle this, we generate the baseline first and inspect the entry.
        $output = [];
        $exitCode = 0;
        exec(
            sprintf(
                'php %s --scan-dir %s --baseline %s --generate-baseline 2>&1',
                escapeshellarg($this->scriptPath),
                escapeshellarg($scanSrc),
                escapeshellarg($this->tempBaseline),
            ),
            $output,
            $exitCode,
        );

        // Now add inline comments to the generated baseline entries (required by gate)
        $baselineContent = file_get_contents($this->tempBaseline);
        assert(is_string($baselineContent));
        $baselineContent = str_replace('  # TODO: add exemption reason', '  # system-context: test exemption', $baselineContent);
        file_put_contents($this->tempBaseline, $baselineContent);

        // Re-run verify — should now pass since the offender is baselined
        $output = [];
        $exitCode = 0;
        exec(
            sprintf(
                'php %s --scan-dir %s --baseline %s --verify 2>&1',
                escapeshellarg($this->scriptPath),
                escapeshellarg($scanSrc),
                escapeshellarg($this->tempBaseline),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, 'Expected zero exit when offender is in baseline. Output: ' . implode("\n", $output));
    }

    #[Test]
    public function missingInlineCommentFailsGate(): void
    {
        // An unbound callsite baselined WITHOUT an inline comment must fail CI
        file_put_contents(
            $this->tempDir . '/src/NoCommentClass.php',
            '<?php' . "\n" . '$storage->getQuery()->condition("status", 1)->execute();' . "\n",
        );

        // Generate baseline first to get the actual path:line
        $output = [];
        exec(
            sprintf(
                'php %s --scan-dir %s --baseline %s --generate-baseline 2>&1',
                escapeshellarg($this->scriptPath),
                escapeshellarg($this->tempDir . '/src'),
                escapeshellarg($this->tempBaseline),
            ),
            $output,
        );

        // Strip the inline comment stubs to simulate a hand-edited baseline without comments
        $baselineContent = file_get_contents($this->tempBaseline);
        assert(is_string($baselineContent));
        // Remove the "  # TODO: add exemption reason" part from data lines
        $baselineContent = preg_replace('/^([^#\s][^#\n]+)  # TODO: add exemption reason$/m', '$1', $baselineContent);
        assert(is_string($baselineContent));
        file_put_contents($this->tempBaseline, $baselineContent);

        $output = [];
        $exitCode = 0;
        exec(
            sprintf(
                'php %s --scan-dir %s --baseline %s --verify 2>&1',
                escapeshellarg($this->scriptPath),
                escapeshellarg($this->tempDir . '/src'),
                escapeshellarg($this->tempBaseline),
            ),
            $output,
            $exitCode,
        );

        self::assertNotSame(0, $exitCode, 'Expected non-zero exit for baseline entry missing inline comment. Output: ' . implode("\n", $output));
        self::assertStringContainsString('Incomplete baseline entries', implode("\n", $output));
    }

}
