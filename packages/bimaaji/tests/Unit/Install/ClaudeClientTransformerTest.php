<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry;
use Waaseyaa\Bimaaji\Install\Client\ClaudeClientTransformer;
use Waaseyaa\Bimaaji\Tests\Fixture\InstallSkillFixtures;

#[CoversClass(ClaudeClientTransformer::class)]
final class ClaudeClientTransformerTest extends TestCase
{
    #[Test]
    public function returnsCorrectClientId(): void
    {
        self::assertSame('claude', (new ClaudeClientTransformer())->clientId());
    }

    #[Test]
    public function producesOnePerSkillPlusOneIndexFile(): void // FR-003
    {
        $skills = InstallSkillFixtures::all(); // 3 skills
        $files = (new ClaudeClientTransformer())->targetFiles($skills);

        self::assertCount(4, $files, 'Claude emits one file per skill plus one shared index — 3 + 1 = 4.');

        $paths = array_map(static fn($f): string => $f->path, $files);
        self::assertContains('.claude/skills/waaseyaa-skill-alpha/SKILL.md', $paths);
        self::assertContains('.claude/skills/waaseyaa-skill-beta/SKILL.md', $paths);
        self::assertContains('.claude/skills/waaseyaa-skill-gamma/SKILL.md', $paths);
        self::assertContains('.claude/CLAUDE-WAASEYAA.md', $paths);
    }

    #[Test]
    public function everySkillTargetIsADirectoryContainingSkillMd(): void
    {
        // Claude Code discovers a PROJECT skill only at
        // `.claude/skills/<skill-name>/SKILL.md`; the command name comes from
        // the directory. A flat `.claude/skills/<name>.md` is not a documented
        // layout and is never loaded — the shape this transformer emitted
        // before the #2656 review. Pin the exact structure, not mere presence.
        // <https://code.claude.com/docs/en/skills> (verified 2026-08-29).
        $files = (new ClaudeClientTransformer())->targetFiles(InstallSkillFixtures::all());

        $skillTargets = array_values(array_filter(
            $files,
            static fn($file): bool => $file->sourceSkill !== null,
        ));
        self::assertCount(3, $skillTargets);

        foreach ($skillTargets as $file) {
            self::assertMatchesRegularExpression(
                '#^\.claude/skills/waaseyaa-[a-z0-9]+(-[a-z0-9]+)*/SKILL\.md$#',
                $file->path,
                'A Claude project skill must be <directory>/SKILL.md, never a flat .md file.',
            );
            self::assertSame(
                'SKILL.md',
                basename($file->path),
                'The discovered file must be named exactly SKILL.md.',
            );
            self::assertSame(
                sprintf('.claude/skills/waaseyaa-%s', $file->sourceSkill),
                dirname($file->path),
                'The skill directory name is the command name, so it must carry the skill id.',
            );
        }
    }

    #[Test]
    public function theFrontmatterNameMatchesTheSkillDirectory(): void
    {
        $files = (new ClaudeClientTransformer())->targetFiles([InstallSkillFixtures::alpha()]);
        $alpha = $this->findByPath($files, '.claude/skills/waaseyaa-skill-alpha/SKILL.md');

        self::assertNotNull($alpha);
        // Frontmatter must start at byte 0 or Claude Code treats the whole
        // file, markers included, as skill content.
        self::assertStringStartsWith("---\nname: waaseyaa-skill-alpha\n", $alpha->content);
    }

    #[Test]
    public function producesNonEmptyContentForEachTarget(): void
    {
        $files = (new ClaudeClientTransformer())->targetFiles(InstallSkillFixtures::all());
        foreach ($files as $file) {
            self::assertNotSame('', $file->content, sprintf('Target file %s must have non-empty content.', $file->path));
        }
    }

    #[Test]
    public function perSkillFileEmbedsSkillBody(): void
    {
        $files = (new ClaudeClientTransformer())->targetFiles([InstallSkillFixtures::alpha()]);
        $alpha = $this->findByPath($files, '.claude/skills/waaseyaa-skill-alpha/SKILL.md');

        self::assertNotNull($alpha);
        self::assertStringContainsString('# Skill Alpha', $alpha->content);
        self::assertStringContainsString('Closing paragraph confirms', $alpha->content);
    }

    #[Test]
    public function respectsFrontmatterStripping(): void // FR-005
    {
        // The frontmatter passed in via ParsedSkill must not leak into the
        // emitted body verbatim. Claude re-emits its own minimal frontmatter
        // (`name` + `description`) but the *source* frontmatter keys are
        // not transcribed.
        $files = (new ClaudeClientTransformer())->targetFiles([InstallSkillFixtures::alpha()]);
        $alpha = $this->findByPath($files, '.claude/skills/waaseyaa-skill-alpha/SKILL.md');

        self::assertNotNull($alpha);
        // The body section must not include the original "---\nname: ..." line
        // count beyond the single re-emitted frontmatter block.
        self::assertSame(
            2,
            substr_count($alpha->content, '---'),
            'Exactly one re-emitted frontmatter block (open + close = 2 occurrences of `---`).',
        );
    }

    #[Test]
    public function sourceSkillIsRecordedForPerSkillFilesOnly(): void
    {
        $files = (new ClaudeClientTransformer())->targetFiles([InstallSkillFixtures::alpha()]);
        $alpha = $this->findByPath($files, '.claude/skills/waaseyaa-skill-alpha/SKILL.md');
        $index = $this->findByPath($files, '.claude/CLAUDE-WAASEYAA.md');

        self::assertSame('skill-alpha', $alpha?->sourceSkill);
        self::assertNull($index?->sourceSkill, 'The aggregated index file must have sourceSkill=null.');
    }

    #[Test]
    public function handlesEmptySkillSetGracefully(): void
    {
        $files = (new ClaudeClientTransformer())->targetFiles([]);
        self::assertCount(1, $files, 'Empty skill set still emits the index file.');
        self::assertStringContainsString('_No skills installed._', $files[0]->content);
    }

    #[Test]
    public function everyTargetPathIsDerivedFromTheRegisteredCapabilitiesNotAHardcodedConstant(): void // #2660 Part A
    {
        // Locks the capability-registry seam this transformer now reads
        // instead of its own (removed) DIRECTORY_PREFIX constant: every
        // path targetFiles() produces must be reproducible purely from
        // ClientCapabilityRegistry::default()->for('claude'), given the
        // same skill ids.
        $capabilities = ClientCapabilityRegistry::default()->for('claude');
        self::assertNotNull($capabilities, 'The "claude" client must be registered.');

        $files = (new ClaudeClientTransformer())->targetFiles(InstallSkillFixtures::all());

        $skillTargets = array_values(array_filter($files, static fn($file): bool => $file->sourceSkill !== null));
        self::assertCount(3, $skillTargets);
        foreach ($skillTargets as $file) {
            self::assertSame($capabilities->skillFilePath((string) $file->sourceSkill), $file->path);
        }

        $index = $this->findByPath($files, $capabilities->guidancePath);
        self::assertNotNull($index, 'The index file must be written at the registry-declared guidancePath.');
    }

    /**
     * @param list<\Waaseyaa\Bimaaji\Install\TargetFile> $files
     */
    private function findByPath(array $files, string $path): ?\Waaseyaa\Bimaaji\Install\TargetFile
    {
        foreach ($files as $file) {
            if ($file->path === $path) {
                return $file;
            }
        }

        return null;
    }
}
