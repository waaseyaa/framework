<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\BimaajiInstall;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * T012 — `--dry-run`. Asserts the command prints the would-be write set,
 * touches NO files on disk, and exits 0.
 *
 * @api
 */
#[CoversNothing]
final class InstallCommandDryRunTest extends BimaajiInstallTestCase
{
    #[Test]
    public function dryRunPrintsWouldWriteLineAndDoesNotTouchTheFilesystem(): void
    {
        $this->seedTwoSkillFixtures();

        $tester = $this->makeTester();
        $tester->execute(['--client=cursor', '--dry-run', '--force']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertFileDoesNotExist($this->tempDir . '/.cursorrules');

        $output = $tester->getOutput();
        self::assertStringContainsString('[DRY-RUN] would write .cursorrules', $output);
        self::assertStringContainsString('Client cursor: 1 written, 0 unchanged, 0 skipped.', $output);
    }

    #[Test]
    public function dryRunOverIdenticalExistingFileReportsUnchangedAndStillTouchesNothing(): void
    {
        $this->seedTwoSkillFixtures();

        $first = $this->makeTester();
        $first->execute(['--client=cursor', '--force']);
        self::assertSame(0, $first->getExitCode());
        $bytesBefore = filesize($this->tempDir . '/.cursorrules');

        $dry = $this->makeTester();
        $dry->execute(['--client=cursor', '--dry-run', '--force']);

        self::assertSame(0, $dry->getExitCode());
        self::assertSame($bytesBefore, filesize($this->tempDir . '/.cursorrules'));
        self::assertStringContainsString('Client cursor: 0 written, 1 unchanged, 0 skipped.', $dry->getOutput());
    }
}
