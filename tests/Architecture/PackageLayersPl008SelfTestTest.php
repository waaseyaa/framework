<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Executes bin/check-package-layers-pl008-self-test on the host running the
 * suite (#3096). The self-test must launch its child gate without a host
 * shell: a POSIX `VAR=value cmd 2>/dev/null` string never starts under
 * cmd.exe, and a child that never starts reads as "gate did NOT fire".
 *
 * The stub-gate cases copy the real self-test beside a recording stub so they
 * prove the self-test's own discrimination: it must reject a detection-dead
 * gate after exactly one child run, and reject an allowlist-blind gate after
 * exactly two, with the three environment overrides reaching the child.
 */
#[CoversNothing]
final class PackageLayersPl008SelfTestTest extends TestCase
{
    private const string SELF_TEST = 'bin/check-package-layers-pl008-self-test';

    private string $repoRoot;
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->fixtureRoot = sys_get_temp_dir() . '/waaseyaa_pl008_self_test_' . uniqid('', true);
        mkdir($this->fixtureRoot . '/bin', 0o755, true);
        copy($this->repoRoot . '/' . self::SELF_TEST, $this->fixtureRoot . '/' . self::SELF_TEST);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->fixtureRoot);
    }

    #[Test]
    public function self_test_passes_against_the_real_gate(): void
    {
        [$exit, $output] = $this->runSelfTest($this->repoRoot);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('OK - PL008 hidden-FQCN layer gate self-test passed', $output);
        self::assertStringNotContainsString('FAIL [PL008-self-test]', $output);
    }

    #[Test]
    public function self_test_fails_when_the_gate_never_reports_a_violation(): void
    {
        $this->writeStubGate('{"packages_scanned":1,"violations":[]}', 0);

        [$exit, $output] = $this->runSelfTest($this->fixtureRoot);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('gate did NOT fire on quoted string-literal violator (sub-pattern a).', $output);
        $invocations = $this->invocations();
        self::assertCount(1, $invocations, 'The self-test must launch the gate exactly once before rejecting it.');
        self::assertSame(
            ['output' => 'json', 'root_has_violator' => true, 'baseline_is_file' => true],
            $invocations[0],
            'The three environment overrides must reach the child gate.',
        );
    }

    #[Test]
    public function self_test_fails_when_the_gate_ignores_the_allowlist(): void
    {
        $this->writeStubGate(
            '{"packages_scanned":1,"violations":[{"source":"foundation","target":"api","edge":"string-literal"}]}',
            1,
        );

        [$exit, $output] = $this->runSelfTest($this->fixtureRoot);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('gate still fired after allowlisting the quoted-literal violator.', $output);
        self::assertStringNotContainsString('did NOT fire', $output);
        self::assertCount(2, $this->invocations(), 'The self-test must reach its allowlist step before rejecting the gate.');
    }

    private function writeStubGate(string $json, int $exit): void
    {
        file_put_contents($this->fixtureRoot . '/bin/check-package-layers', sprintf(<<<'PHP'
            <?php
            $root = (string) getenv('WAASEYAA_LAYER_ROOT');
            file_put_contents(__DIR__ . '/invocations.log', json_encode([
                'output' => getenv('WAASEYAA_OUTPUT'),
                'root_has_violator' => is_file($root . '/packages/foundation/src/Discovery/SyntheticViolator.php'),
                'baseline_is_file' => is_file((string) getenv('WAASEYAA_LAYER_STRING_LITERAL_BASELINE')),
            ]) . "\n", FILE_APPEND);
            echo %s;
            exit(%d);
            PHP, var_export($json, true), $exit));
    }

    /** @return list<array<string, mixed>> */
    private function invocations(): array
    {
        $log = $this->fixtureRoot . '/bin/invocations.log';
        if (!is_file($log)) {
            return [];
        }

        return array_map(
            static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents($log)))),
        );
    }

    /** @return array{int, string} */
    private function runSelfTest(string $root): array
    {
        $process = new Process([PHP_BINARY, $root . '/' . self::SELF_TEST], $root);
        $exit = $process->run();

        return [$exit, $process->getOutput() . $process->getErrorOutput()];
    }
}
