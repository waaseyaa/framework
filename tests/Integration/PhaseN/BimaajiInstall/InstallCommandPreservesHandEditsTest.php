<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\BimaajiInstall;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * T013 — hand-edit safety for a target file the command does not
 * recognise. Since #2656 the install is marker-bounded: a file carrying a
 * `<!-- waaseyaa:bimaaji:install BEGIN -->` / `END` pair is refreshed
 * inside the markers only (proved in `InstallCommandTest`). A file with
 * NO markers is treated as wholly hand-authored, and the original
 * contract still holds — it is preserved when EITHER (a) its sha1 matches
 * the would-be content (idempotent no-op), OR (b) `--force` is absent and
 * stdin is non-interactive (the command errors out without touching it).
 *
 * @api
 */
#[CoversNothing]
final class InstallCommandPreservesHandEditsTest extends BimaajiInstallTestCase
{
    #[Test]
    public function refusesToOverwriteDivergingFileWithoutForceAndExitsNonZero(): void
    {
        $this->seedTwoSkillFixtures();
        $handEditedPath = $this->tempDir . '/.cursorrules';
        $handEdited = "# Hand-edited cursor rules\nReviewers should not lose this content.\n";
        file_put_contents($handEditedPath, $handEdited);

        $tester = $this->makeTester();
        // No --force; stdin is the default EmptyStdinSource (non-interactive).
        $tester->execute(['--client=cursor']);

        self::assertNotSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertSame(
            $handEdited,
            file_get_contents($handEditedPath),
            'Hand-edited file must not be touched when --force is absent and stdin is non-interactive.',
        );

        $output = $tester->getOutput();
        self::assertStringContainsString('carries no', $output);
        self::assertStringContainsString('and differs', $output);
        self::assertStringContainsString('Client cursor: 0 written, 0 unchanged, 1 skipped.', $output);
    }

    #[Test]
    public function preservesIdenticalExistingFileAsUnchanged(): void
    {
        $this->seedTwoSkillFixtures();

        $first = $this->makeTester();
        $first->execute(['--client=cursor', '--force']);
        self::assertSame(0, $first->getExitCode());
        $bytes = file_get_contents($this->tempDir . '/.cursorrules');

        $second = $this->makeTester();
        // No --force, but file already matches → idempotent no-op.
        $second->execute(['--client=cursor']);

        self::assertSame(0, $second->getExitCode());
        self::assertSame($bytes, file_get_contents($this->tempDir . '/.cursorrules'));
        self::assertStringContainsString('Client cursor: 0 written, 1 unchanged, 0 skipped.', $second->getOutput());
    }
}
