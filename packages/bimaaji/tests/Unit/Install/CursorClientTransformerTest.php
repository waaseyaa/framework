<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\Client\AbstractSingleFileClientTransformer;
use Waaseyaa\Bimaaji\Install\Client\CursorClientTransformer;
use Waaseyaa\Bimaaji\Tests\Fixture\InstallSkillFixtures;

#[CoversClass(CursorClientTransformer::class)]
#[CoversClass(AbstractSingleFileClientTransformer::class)]
final class CursorClientTransformerTest extends TestCase
{
    #[Test]
    public function returnsCorrectClientId(): void
    {
        self::assertSame('cursor', (new CursorClientTransformer())->clientId());
    }

    #[Test]
    public function producesExactlyOneTargetFile(): void
    {
        $files = (new CursorClientTransformer())->targetFiles(InstallSkillFixtures::all());
        self::assertCount(1, $files);
        self::assertSame('.cursorrules', $files[0]->path);
        self::assertNull($files[0]->sourceSkill, 'Aggregated file must have sourceSkill=null.');
    }

    #[Test]
    public function producesNonEmptyContent(): void
    {
        $files = (new CursorClientTransformer())->targetFiles(InstallSkillFixtures::all());
        self::assertNotSame('', $files[0]->content);
    }

    #[Test]
    public function fileHasPreludeAndAtLeastOneSkillBody(): void
    {
        $files = (new CursorClientTransformer())->targetFiles(InstallSkillFixtures::all());
        $content = $files[0]->content;

        self::assertStringContainsString('Waaseyaa framework conventions', $content, 'Standardised prelude must be present.');
        self::assertStringContainsString('## skill-alpha', $content, 'At least one skill body must be folded in.');
        self::assertStringContainsString('Closing paragraph confirms', $content, 'Skill body content must be preserved.');
    }

    #[Test]
    public function respectsFrontmatterStripping(): void // FR-005
    {
        $files = (new CursorClientTransformer())->targetFiles(InstallSkillFixtures::all());
        $content = $files[0]->content;

        // Body strings are present.
        self::assertStringContainsString('Body text under the subsection.', $content);
        // No `name: skill-…` frontmatter lines from the source files.
        self::assertStringNotContainsString("\nname: skill-alpha\n", $content);
        self::assertStringNotContainsString('description: First fixture skill used by', $content);
    }

    #[Test]
    public function emitsBeginAndEndMarkersForMergeSupport(): void
    {
        $files = (new CursorClientTransformer())->targetFiles(InstallSkillFixtures::all());
        $content = $files[0]->content;

        self::assertStringContainsString('<!-- waaseyaa:bimaaji:install BEGIN -->', $content);
        self::assertStringContainsString('<!-- waaseyaa:bimaaji:install END -->', $content);
    }

    #[Test]
    public function handlesEmptySkillSetGracefully(): void
    {
        $files = (new CursorClientTransformer())->targetFiles([]);
        self::assertCount(1, $files);
        self::assertStringContainsString('No skills to install', $files[0]->content);
    }
}
