<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\BimaajiInstall;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * T011 — positive path. Drives `bimaaji:install --client=cursor --force`
 * against a fresh project tree with two seed skills; asserts a target file
 * appears at `.cursorrules` with the framework prelude + both skill
 * bodies framed by the begin/end markers, exit 0, and the summary
 * announces 1 write.
 *
 * @api
 */
#[CoversNothing]
final class InstallCommandTest extends BimaajiInstallTestCase
{
    #[Test]
    public function installsCursorRulesFileFromSeedSkills(): void
    {
        $this->seedTwoSkillFixtures();

        $tester = $this->makeTester();
        $tester->execute(['--client=cursor', '--force']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());

        $cursorRules = $this->tempDir . '/.cursorrules';
        self::assertFileExists($cursorRules);

        $contents = file_get_contents($cursorRules);
        self::assertIsString($contents);
        self::assertStringContainsString('<!-- waaseyaa:bimaaji:install BEGIN -->', $contents);
        self::assertStringContainsString('<!-- waaseyaa:bimaaji:install END -->', $contents);
        self::assertStringContainsString('## Skill Alpha', $contents);
        self::assertStringContainsString('## Skill Beta', $contents);
        self::assertStringContainsString('Body for alpha.', $contents);

        self::assertStringContainsString('Client cursor: 1 written, 0 unchanged, 0 skipped.', $tester->getOutput());
    }

    #[Test]
    public function secondRunIsIdempotentAndCountsUnchanged(): void
    {
        $this->seedTwoSkillFixtures();

        $first = $this->makeTester();
        $first->execute(['--client=cursor', '--force']);
        self::assertSame(0, $first->getExitCode());
        $contentsAfterFirst = file_get_contents($this->tempDir . '/.cursorrules');

        $second = $this->makeTester();
        $second->execute(['--client=cursor', '--force']);
        self::assertSame(0, $second->getExitCode());

        // File contents unchanged; second run reports `unchanged` not `written`.
        self::assertSame($contentsAfterFirst, file_get_contents($this->tempDir . '/.cursorrules'));
        self::assertStringContainsString('Client cursor: 0 written, 1 unchanged, 0 skipped.', $second->getOutput());
    }
}
