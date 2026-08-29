<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\BimaajiInstall;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Bimaaji\Install\InstalledManifest;

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

        $skillFile = $this->tempDir . '/.claude/skills/waaseyaa-alpha/SKILL.md';
        self::assertFileExists($skillFile);
        self::assertDirectoryExists($this->tempDir . '/.claude/skills/waaseyaa-alpha');
        self::assertFileDoesNotExist(
            $this->tempDir . '/.claude/skills/waaseyaa-alpha.md',
            'A flat skill file is not discovered by Claude Code and must not be written.',
        );
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

    #[Test]
    public function aSkillRemovedUpstreamIsPrunedOnTheNextRun(): void
    {
        $this->seedTwoSkillFixtures();

        $first = $this->makeTester();
        $first->execute(['--client=claude', '--force']);
        self::assertSame(0, $first->getExitCode(), $first->getOutput());

        $retired = $this->tempDir . '/.claude/skills/waaseyaa-beta/SKILL.md';
        self::assertFileExists($retired);
        self::assertFileExists($this->tempDir . '/' . InstalledManifest::RELATIVE_PATH);

        // beta is withdrawn from the framework skill set.
        new Filesystem()->remove($this->tempDir . '/skills/waaseyaa/beta');

        $second = $this->makeTester();
        $second->execute(['--client=claude', '--force']);
        self::assertSame(0, $second->getExitCode(), $second->getOutput());

        self::assertFileDoesNotExist($retired);
        self::assertDirectoryDoesNotExist($this->tempDir . '/.claude/skills/waaseyaa-beta');
        self::assertFileExists(
            $this->tempDir . '/.claude/skills/waaseyaa-alpha/SKILL.md',
            'Pruning a retired skill must not touch the surviving ones.',
        );
        self::assertStringContainsString('Removed retired target', $second->getOutput());
    }

    #[Test]
    public function aSkillRenamedUpstreamLeavesOnlyTheNewName(): void
    {
        $this->seedTwoSkillFixtures();

        $first = $this->makeTester();
        $first->execute(['--client=claude', '--force']);
        self::assertSame(0, $first->getExitCode(), $first->getOutput());
        self::assertFileExists($this->tempDir . '/.claude/skills/waaseyaa-beta/SKILL.md');

        // beta is renamed to gamma upstream.
        new Filesystem()->remove($this->tempDir . '/skills/waaseyaa/beta');
        $this->writeSkillFixture('gamma', <<<MD
            ---
            name: Skill Gamma
            description: Renamed from beta
            ---

            # Gamma

            Body for gamma.
            MD);

        $second = $this->makeTester();
        $second->execute(['--client=claude', '--force']);
        self::assertSame(0, $second->getExitCode(), $second->getOutput());

        self::assertFileExists($this->tempDir . '/.claude/skills/waaseyaa-gamma/SKILL.md');
        self::assertFileDoesNotExist($this->tempDir . '/.claude/skills/waaseyaa-beta/SKILL.md');
        self::assertDirectoryDoesNotExist($this->tempDir . '/.claude/skills/waaseyaa-beta');
    }

    #[Test]
    public function aRetiredSkillCarryingUserContentIsNeutralisedNotDeleted(): void
    {
        $this->seedTwoSkillFixtures();

        $first = $this->makeTester();
        $first->execute(['--client=claude', '--force']);
        self::assertSame(0, $first->getExitCode(), $first->getOutput());

        // The consumer adds their own notes below the managed region, and a
        // supporting file beside it.
        $retired = $this->tempDir . '/.claude/skills/waaseyaa-beta/SKILL.md';
        file_put_contents($retired, (string) file_get_contents($retired) . "\n## My notes\n\nKeep this.\n");
        file_put_contents($this->tempDir . '/.claude/skills/waaseyaa-beta/reference.md', "My reference.\n");

        new Filesystem()->remove($this->tempDir . '/skills/waaseyaa/beta');

        $second = $this->makeTester();
        $second->execute(['--client=claude', '--force']);
        self::assertSame(0, $second->getExitCode(), $second->getOutput());

        // Never deleted — that would take hand-authored bytes with it.
        self::assertFileExists($retired);
        $contents = (string) file_get_contents($retired);
        self::assertStringContainsString('## My notes', $contents);
        self::assertStringContainsString('Keep this.', $contents);
        self::assertStringContainsString('has been retired', $contents);
        self::assertStringNotContainsString('Body for beta.', $contents);
        // Supporting assets survive.
        self::assertFileExists($this->tempDir . '/.claude/skills/waaseyaa-beta/reference.md');
        self::assertStringContainsString('Retired the managed region', $second->getOutput());
    }

    #[Test]
    public function aFileTheManifestDoesNotClaimIsNeverPruned(): void
    {
        $this->seedTwoSkillFixtures();

        $first = $this->makeTester();
        $first->execute(['--client=claude', '--force']);
        self::assertSame(0, $first->getExitCode(), $first->getOutput());

        // A skill the consumer wrote themselves, named exactly like ours.
        // Filename is a guess about ownership; the manifest is the record.
        $theirs = $this->tempDir . '/.claude/skills/waaseyaa-their-own/SKILL.md';
        mkdir(dirname($theirs), 0o755, true);
        file_put_contents($theirs, "---\nname: waaseyaa-their-own\n---\n\nMine, not the framework's.\n");

        new Filesystem()->remove($this->tempDir . '/skills/waaseyaa/beta');

        $second = $this->makeTester();
        $second->execute(['--client=claude', '--force']);
        self::assertSame(0, $second->getExitCode(), $second->getOutput());

        self::assertFileExists($theirs);
        self::assertSame(
            "---\nname: waaseyaa-their-own\n---\n\nMine, not the framework's.\n",
            file_get_contents($theirs),
        );
    }

    #[Test]
    public function dryRunReportsPrunesWithoutPerformingThem(): void
    {
        $this->seedTwoSkillFixtures();

        $first = $this->makeTester();
        $first->execute(['--client=claude', '--force']);
        self::assertSame(0, $first->getExitCode(), $first->getOutput());

        new Filesystem()->remove($this->tempDir . '/skills/waaseyaa/beta');
        $manifestBefore = (string) file_get_contents($this->tempDir . '/' . InstalledManifest::RELATIVE_PATH);

        $second = $this->makeTester();
        $second->execute(['--client=claude', '--dry-run']);

        self::assertSame(0, $second->getExitCode(), $second->getOutput());
        self::assertStringContainsString('[DRY-RUN] would remove retired target', $second->getOutput());
        self::assertFileExists($this->tempDir . '/.claude/skills/waaseyaa-beta/SKILL.md');
        self::assertSame(
            $manifestBefore,
            file_get_contents($this->tempDir . '/' . InstalledManifest::RELATIVE_PATH),
            'A dry run must not rewrite the ownership manifest.',
        );
    }

    #[Test]
    public function installingASecondClientDoesNotForgetTheFirst(): void
    {
        $this->seedTwoSkillFixtures();

        $this->makeTester()->execute(['--client=claude', '--force']);
        $this->makeTester()->execute(['--client=cursor', '--force']);

        $manifest = InstalledManifest::load($this->tempDir);

        self::assertSame(['claude', 'cursor'], $manifest->clientIds());
        self::assertArrayHasKey('.cursorrules', $manifest->targetsFor('cursor'));
        self::assertArrayHasKey('.claude/skills/waaseyaa-alpha/SKILL.md', $manifest->targetsFor('claude'));
    }
}
