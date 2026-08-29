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

    #[Test]
    public function reRunRefreshesTheManagedRegionAndPreservesHandAuthoredContent(): void
    {
        $this->seedTwoSkillFixtures();

        $first = $this->makeTester();
        $first->execute(['--client=cursor', '--force']);
        self::assertSame(0, $first->getExitCode(), $first->getOutput());

        // A consumer edits the file around the framework block.
        $target = $this->tempDir . '/.cursorrules';
        $installed = (string) file_get_contents($target);
        $edited = "# House rules\n\nAlways run the linter.\n\n"
            . $installed
            . "\n## Team addendum\n\nDeploy on Thursdays.\n";
        file_put_contents($target, $edited);

        // The skill set changes upstream, then the install is re-run.
        $this->writeSkillFixture('alpha', <<<MD
            ---
            name: Skill Alpha
            description: First fixture
            ---

            # Alpha

            Body for alpha, revised upstream.
            MD);

        $second = $this->makeTester();
        $second->execute(['--client=cursor']);
        self::assertSame(0, $second->getExitCode(), $second->getOutput());

        $merged = (string) file_get_contents($target);
        self::assertStringContainsString('# House rules', $merged);
        self::assertStringContainsString('Always run the linter.', $merged);
        self::assertStringContainsString('## Team addendum', $merged);
        self::assertStringContainsString('Deploy on Thursdays.', $merged);
        self::assertStringContainsString('Body for alpha, revised upstream.', $merged);
        self::assertStringNotContainsString('Body for alpha.', $merged);
        self::assertStringContainsString('Client cursor: 1 written, 0 unchanged, 0 skipped.', $second->getOutput());
    }

    #[Test]
    public function aMarkerBoundedRefreshDoesNotRequireForce(): void
    {
        // Marker-bounded refresh never touches hand-authored bytes, so it
        // must not demand --force the way an unrecognised file does.
        $this->seedTwoSkillFixtures();

        $first = $this->makeTester();
        $first->execute(['--client=cursor', '--force']);
        self::assertSame(0, $first->getExitCode());

        $target = $this->tempDir . '/.cursorrules';
        file_put_contents($target, "Preface I wrote.\n\n" . (string) file_get_contents($target));

        $second = $this->makeTester();
        $second->execute(['--client=cursor']);

        self::assertSame(0, $second->getExitCode(), $second->getOutput());
        self::assertStringContainsString('Preface I wrote.', (string) file_get_contents($target));
    }

    #[Test]
    public function aFileWithNoMarkersStillRequiresForce(): void
    {
        $this->seedTwoSkillFixtures();
        file_put_contents($this->tempDir . '/.cursorrules', "Entirely hand written. No markers here.\n");

        $tester = $this->makeTester();
        $tester->execute(['--client=cursor']);

        self::assertSame(1, $tester->getExitCode());
        self::assertSame(
            "Entirely hand written. No markers here.\n",
            file_get_contents($this->tempDir . '/.cursorrules'),
        );
        self::assertStringContainsString('carries no', $tester->getOutput());
        self::assertStringContainsString('pass --force to overwrite', $tester->getOutput());
    }

    #[Test]
    public function claudeSkillFilesAreMarkerBoundedBelowTheirFrontmatter(): void
    {
        $this->seedTwoSkillFixtures();

        $tester = $this->makeTester();
        $tester->execute(['--client=claude', '--force']);
        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());

        $skillFile = $this->tempDir . '/.claude/skills/waaseyaa-alpha.md';
        self::assertFileExists($skillFile);
        $contents = (string) file_get_contents($skillFile);

        // Claude Code requires frontmatter at byte 0, so the marker pair
        // opens after it — the frontmatter stays consumer-editable.
        self::assertStringStartsWith("---\n", $contents);
        self::assertLessThan(
            strpos($contents, '<!-- waaseyaa:bimaaji:install BEGIN -->'),
            strpos($contents, "\n---\n\n"),
        );
        self::assertStringContainsString('<!-- waaseyaa:bimaaji:install END -->', $contents);
    }
}
