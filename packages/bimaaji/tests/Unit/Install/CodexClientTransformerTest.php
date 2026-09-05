<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\Client\CodexClientTransformer;
use Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry;
use Waaseyaa\Bimaaji\Install\SkillDeliveryMode;
use Waaseyaa\Bimaaji\Tests\Fixture\InstallSkillFixtures;

#[CoversClass(CodexClientTransformer::class)]
final class CodexClientTransformerTest extends TestCase
{
    #[Test]
    public function returnsCorrectClientId(): void
    {
        self::assertSame('codex', (new CodexClientTransformer())->clientId());
    }

    #[Test]
    public function producesOnePerSkillFilePlusConciseAgentsGuidance(): void
    {
        $files = (new CodexClientTransformer())->targetFiles(InstallSkillFixtures::all());
        self::assertCount(4, $files);

        $paths = array_map(static fn($f): string => $f->path, $files);
        self::assertContains('.agents/skills/waaseyaa-skill-alpha/SKILL.md', $paths);
        self::assertContains('AGENTS.md', $paths);
    }

    #[Test]
    public function guidanceDoesNotEmbedFullSkillBodies(): void
    {
        $guidance = $this->guidanceContent();
        self::assertStringNotContainsString('# Skill Alpha', $guidance);
        self::assertStringNotContainsString('Closing paragraph confirms', $guidance);
        self::assertStringContainsString('## Available skills', $guidance);
        self::assertStringContainsString('.agents/skills/waaseyaa-skill-alpha/SKILL.md', $guidance);
    }

    #[Test]
    public function perSkillFilesCarryFrontmatterAndManagedBodies(): void
    {
        $files = (new CodexClientTransformer())->targetFiles([InstallSkillFixtures::alpha()]);
        $alpha = $files[0];
        self::assertSame('.agents/skills/waaseyaa-skill-alpha/SKILL.md', $alpha->path);
        self::assertStringStartsWith("---\nname: waaseyaa-skill-alpha\n", $alpha->content);
        self::assertStringContainsString('# Skill Alpha', $alpha->content);
        self::assertStringContainsString('waaseyaa:bimaaji:source-inventory sha256=', $alpha->content);
    }

    #[Test]
    public function registryDeclaresPerSkillDeliveryForCodex(): void
    {
        $capabilities = ClientCapabilityRegistry::default()->for('codex');
        self::assertNotNull($capabilities);
        self::assertSame(SkillDeliveryMode::PerSkillFile, $capabilities->skillDelivery);
        self::assertSame('.agents/skills', $capabilities->skillDirectory);
    }

    private function guidanceContent(): string
    {
        foreach ((new CodexClientTransformer())->targetFiles(InstallSkillFixtures::all()) as $file) {
            if ($file->path === 'AGENTS.md') {
                return $file->content;
            }
        }

        self::fail('No AGENTS.md guidance target was produced.');
    }
}
