<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tools\PHPStan\LevelAudit;

#[CoversNothing]
final class PhpstanLevelAuditTest extends TestCase
{
    #[Test]
    public function level_selection_is_closed_and_unambiguous(): void
    {
        self::assertSame([5, 6, 7, 8, 9, 10], LevelAudit::parseLevels('5-10'));
        self::assertSame([5, 7, 8], LevelAudit::parseLevels('8,5,7,5'));

        foreach (['', '5,', 'five', '8-5', '5-11'] as $invalid) {
            try {
                LevelAudit::parseLevels($invalid);
                self::fail(sprintf('Invalid level selection %s was accepted.', var_export($invalid, true)));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function input_identity_is_checkout_independent(): void
    {
        $first = LevelAudit::inputIdentity(
            levels: [5, 6, 7, 8, 9, 10],
            phpstanVersion: 'PHPStan 2.1.54',
            expandedParameters: "paths:\n\t- /one/checkout/packages\nexcludePaths:\n\t- /one/checkout/packages/admin/*\n",
            root: '/one/checkout',
            configurationFiles: ['phpstan.neon' => 'aaa', 'phpstan-baseline.neon' => 'bbb'],
        );
        $second = LevelAudit::inputIdentity(
            levels: [5, 6, 7, 8, 9, 10],
            phpstanVersion: 'PHPStan 2.1.54',
            expandedParameters: "paths:\n\t- /elsewhere/packages\nexcludePaths:\n\t- /elsewhere/packages/admin/*\n",
            root: '/elsewhere',
            configurationFiles: ['phpstan.neon' => 'aaa', 'phpstan-baseline.neon' => 'bbb'],
        );

        self::assertSame($first, $second);
    }

    #[Test]
    public function every_measurement_input_changes_the_identity(): void
    {
        $base = $this->identity();

        self::assertNotSame($base, $this->identity(levels: [5, 6, 7, 8]));
        self::assertNotSame($base, $this->identity(parameters: "paths:\n\t- /repo/other\n"));
        self::assertNotSame($base, $this->identity(parameters: "excludePaths:\n\t- /repo/packages/admin/*\n"));
        self::assertNotSame($base, $this->identity(configurationFiles: ['phpstan.neon' => 'changed']));
        self::assertNotSame($base, $this->identity(version: 'PHPStan 2.2.0'));
    }

    #[Test]
    public function comparison_refuses_silent_input_drift(): void
    {
        $expected = ['input_identity_sha256' => $this->identity()];
        $actual = ['input_identity_sha256' => $this->identity(parameters: "paths:\n\t- /repo/other\n")];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('measurement inputs changed');
        LevelAudit::assertComparable($expected, $actual);
    }

    #[Test]
    public function findings_are_reconciled_by_level_identifier_and_package(): void
    {
        $summary = LevelAudit::summarize([
            ['file' => '/repo/packages/entity-storage/src/A.php', 'identifier' => 'method.nonObject'],
            ['file' => '/repo/packages/entity-storage/src/B.php', 'identifier' => 'argument.type'],
            ['file' => '/repo/packages/site-contract/src/C.php', 'identifier' => 'argument.type'],
            ['file' => '/repo/bin/tool', 'identifier' => null],
        ], '/repo');

        self::assertSame(4, $summary['total']);
        self::assertSame([
            'argument.type' => 2,
            'method.nonObject' => 1,
            'unknown' => 1,
        ], $summary['by_identifier']);
        self::assertSame([
            '(root)' => 1,
            'entity-storage' => 2,
            'site-contract' => 1,
        ], $summary['by_package']);
        self::assertSame($summary['total'], array_sum($summary['by_identifier']));
        self::assertSame($summary['total'], array_sum($summary['by_package']));
    }

    #[Test]
    public function executable_self_test_proves_input_drift_is_rejected(): void
    {
        exec(
            sprintf('php %s --self-test 2>&1', escapeshellarg(dirname(__DIR__, 2) . '/bin/phpstan-level-audit')),
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('detects level, source-path, exclusion, and include drift', implode("\n", $output));
    }

    /**
     * @param list<int> $levels
     * @param array<string, string> $configurationFiles
     */
    private function identity(
        array $levels = [5, 6, 7, 8, 9, 10],
        string $parameters = "paths:\n\t- /repo/packages\n",
        array $configurationFiles = ['phpstan.neon' => 'aaa'],
        string $version = 'PHPStan 2.1.54',
    ): string {
        return LevelAudit::inputIdentity(
            levels: $levels,
            phpstanVersion: $version,
            expandedParameters: $parameters,
            root: '/repo',
            configurationFiles: $configurationFiles,
        );
    }
}
