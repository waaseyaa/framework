<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\Client\CopilotClientTransformer;
use Waaseyaa\Bimaaji\Tests\Fixture\InstallSkillFixtures;

#[CoversClass(CopilotClientTransformer::class)]
final class CopilotClientTransformerTest extends TestCase
{
    #[Test]
    public function returnsCorrectClientId(): void
    {
        self::assertSame('copilot', (new CopilotClientTransformer())->clientId());
    }

    #[Test]
    public function producesExactlyOneTargetFile(): void
    {
        $files = (new CopilotClientTransformer())->targetFiles(InstallSkillFixtures::all());
        self::assertCount(1, $files);
        self::assertSame('.github/copilot-instructions.md', $files[0]->path);
    }

    #[Test]
    public function producesNonEmptyContent(): void
    {
        $files = (new CopilotClientTransformer())->targetFiles(InstallSkillFixtures::all());
        self::assertNotSame('', $files[0]->content);
    }

    #[Test]
    public function fileHasPreludeAndAtLeastOneSkillBody(): void
    {
        $content = (new CopilotClientTransformer())->targetFiles(InstallSkillFixtures::all())[0]->content;
        self::assertStringContainsString('Waaseyaa framework conventions', $content);
        self::assertStringContainsString('## skill-alpha', $content);
    }

    #[Test]
    public function respectsFrontmatterStripping(): void // FR-005
    {
        $content = (new CopilotClientTransformer())->targetFiles(InstallSkillFixtures::all())[0]->content;
        self::assertStringContainsString('Body text under the subsection.', $content);
        self::assertStringNotContainsString("\nname: skill-alpha\n", $content);
    }

    #[Test]
    public function handlesEmptySkillSetGracefully(): void
    {
        $files = (new CopilotClientTransformer())->targetFiles([]);
        self::assertCount(1, $files);
        self::assertStringContainsString('No skills to install', $files[0]->content);
    }
}
