<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
        self::assertContains('.claude/skills/waaseyaa-skill-alpha.md', $paths);
        self::assertContains('.claude/skills/waaseyaa-skill-beta.md', $paths);
        self::assertContains('.claude/skills/waaseyaa-skill-gamma.md', $paths);
        self::assertContains('.claude/CLAUDE-WAASEYAA.md', $paths);
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
        $alpha = $this->findByPath($files, '.claude/skills/waaseyaa-skill-alpha.md');

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
        $alpha = $this->findByPath($files, '.claude/skills/waaseyaa-skill-alpha.md');

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
        $alpha = $this->findByPath($files, '.claude/skills/waaseyaa-skill-alpha.md');
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
