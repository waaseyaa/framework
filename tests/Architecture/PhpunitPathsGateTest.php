<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNothing]
final class PhpunitPathsGateTest extends TestCase
{
    private const BIN = __DIR__ . '/../../bin/check-phpunit-paths';

    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/waaseyaa_phpunit_paths_' . uniqid('', true);
        mkdir($this->fixture . '/packages/example/tests/Feature', 0o755, true);
        file_put_contents($this->fixture . '/packages/example/tests/Feature/ExampleTest.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->fixture);
    }

    #[Test]
    public function it_fails_when_a_package_test_directory_is_outside_every_suite_glob(): void
    {
        $this->writeConfig('<testsuite name="Unit"><directory>packages/*/tests/Unit</directory></testsuite>');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('packages/example/tests/Feature/ExampleTest.php', $output);
    }

    #[Test]
    public function it_passes_when_a_package_test_directory_is_covered_by_a_suite_glob(): void
    {
        $this->writeConfig('<testsuite name="Feature"><directory>packages/*/tests/Feature</directory></testsuite>');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * A golden `*Test.php` under a package's `tests/Fixtures/` tree is recorded
     * generator OUTPUT compared byte-for-byte by a real test (#2788: the
     * blueprint compiler's emitted companion tests), not a repository test
     * that any suite should execute. The gate must classify it as data while a
     * real unselected test beside it still fails closed.
     */
    #[Test]
    public function it_ignores_golden_fixture_tests_but_still_reports_a_real_unselected_test(): void
    {
        mkdir($this->fixture . '/packages/example/tests/Fixtures/expected/tests/Blueprint', 0o755, true);
        file_put_contents($this->fixture . '/packages/example/tests/Fixtures/expected/tests/Blueprint/GoldenTest.php', "<?php\n");
        $this->writeConfig('<testsuite name="Unit"><directory>packages/*/tests/Unit</directory></testsuite>');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('packages/example/tests/Feature/ExampleTest.php', $output);
        self::assertStringNotContainsString('GoldenTest.php', $output);

        $this->writeConfig('<testsuite name="Feature"><directory>packages/*/tests/Feature</directory></testsuite>');
        [$exitCode, $output] = $this->runGate();

        self::assertSame(0, $exitCode, $output);
    }

    private function writeConfig(string $suite): void
    {
        file_put_contents(
            $this->fixture . '/phpunit.xml.dist',
            "<?xml version=\"1.0\"?><phpunit><testsuites>{$suite}</testsuites></phpunit>\n",
        );
    }

    /** @return array{int, string} */
    private function runGate(): array
    {
        $command = sprintf(
            'ROOT_DIR=%s bash %s 2>&1',
            escapeshellarg($this->fixture),
            escapeshellarg(self::BIN),
        );
        exec($command, $lines, $exitCode);

        return [$exitCode, implode("\n", $lines)];
    }
}
