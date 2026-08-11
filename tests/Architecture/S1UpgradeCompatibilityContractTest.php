<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class S1UpgradeCompatibilityContractTest extends TestCase
{
    #[Test]
    public function the_upgrade_contract_is_versioned_executable_and_fail_closed(): void
    {
        $root = dirname(__DIR__, 2);
        $checker = $root . '/bin/check-upgrade-contract';
        self::assertFileExists($checker);
        self::assertTrue(is_executable($checker), 'Upgrade contract checker must retain mode 100755.');

        $contractPath = $root . '/support/upgrade/s1-v1.json';
        self::assertFileExists($contractPath);
        $contract = json_decode((string) file_get_contents($contractPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($contract);
        self::assertSame('s1-upgrade-v1', $contract['contract_id'] ?? null);
        self::assertSame('alpha293-to-s1-v1', $contract['transition_id'] ?? null);
        self::assertFalse($contract['failure_policy']['preflight_writes'] ?? true);
        self::assertSame(
            'separate-recovery-gate',
            $contract['failure_policy']['destructive_restore_authority'] ?? null,
        );

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($checker) . ' 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
        self::assertSame(
            'OK: S1 upgrade compatibility contract is ordered and fail-closed.',
            implode(PHP_EOL, $output),
        );
    }
}
