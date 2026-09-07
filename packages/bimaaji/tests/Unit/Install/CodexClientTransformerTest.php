<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\Client\ClaudeClientTransformer;
use Waaseyaa\Bimaaji\Install\Client\CodexClientTransformer;
use Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry;
use Waaseyaa\Bimaaji\Install\SkillDeliveryMode;
use Waaseyaa\Bimaaji\Install\SkillInventory;
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
    public function registryDeclaresPerSkillDeliveryForCodex(): void // #2660 Part B
    {
        $capabilities = ClientCapabilityRegistry::default()->for('codex');
        self::assertNotNull($capabilities);
        self::assertSame(SkillDeliveryMode::PerSkillFile, $capabilities->skillDelivery);
        self::assertTrue($capabilities->requiresFrontmatterAtByteZero);
        self::assertSame('.agents/skills', $capabilities->skillDirectory);
        self::assertSame('waaseyaa-', $capabilities->skillIdPrefix);
    }

    #[Test]
    public function producesOnePerSkillFilePlusOneConciseAgentsFile(): void
    {
        $files = (new CodexClientTransformer())->targetFiles(InstallSkillFixtures::all());
        self::assertCount(4, $files, 'Codex emits one file per skill plus one shared AGENTS.md — 3 + 1 = 4.');

        $paths = array_map(static fn($f): string => $f->path, $files);
        self::assertContains('.agents/skills/waaseyaa-skill-alpha/SKILL.md', $paths);
        self::assertContains('.agents/skills/waaseyaa-skill-beta/SKILL.md', $paths);
        self::assertContains('.agents/skills/waaseyaa-skill-gamma/SKILL.md', $paths);
        self::assertContains('AGENTS.md', $paths);
    }

    #[Test]
    public function perSkillFileOpensWithFrontmatterAndCarriesTheBodyPlusProvenanceFooter(): void
    {
        $files = (new CodexClientTransformer())->targetFiles([InstallSkillFixtures::alpha()]);
        $alpha = $this->findByPath($files, '.agents/skills/waaseyaa-skill-alpha/SKILL.md');

        self::assertNotNull($alpha);
        self::assertStringStartsWith("---\nname: waaseyaa-skill-alpha\n", $alpha->content);
        self::assertStringContainsString('# Skill Alpha', $alpha->content);
        self::assertStringContainsString('waaseyaa:bimaaji:source-inventory sha256=', $alpha->content);
    }

    #[Test]
    public function guidanceDoesNotEmbedFullSkillBodiesButListsEachSkillWithItsSourceHash(): void
    {
        $guidance = $this->guidanceContent();

        self::assertStringNotContainsString('# Skill Alpha', $guidance);
        self::assertStringNotContainsString('Closing paragraph confirms', $guidance);
        self::assertStringContainsString('## Available skills', $guidance);
        self::assertStringContainsString('.agents/skills/waaseyaa-skill-alpha/SKILL.md', $guidance);
        self::assertStringContainsString(InstallSkillFixtures::alpha()->sourceSha256, $guidance);
    }

    #[Test]
    public function handlesEmptySkillSetGracefully(): void
    {
        $files = (new CodexClientTransformer())->targetFiles([]);
        self::assertCount(1, $files, 'Empty skill set still emits the AGENTS.md guidance file.');
        self::assertStringContainsString('_No skills installed._', $files[0]->content);
    }

    #[Test]
    public function claudeAndCodexEmitByteIdenticalPerSkillContentForTheSameInventory(): void // #2660 Part B parity
    {
        $inventory = SkillInventory::fromSkills(InstallSkillFixtures::all())->all();
        $claudeFiles = $this->skillFilesById((new ClaudeClientTransformer())->targetFiles($inventory));
        $codexFiles = $this->skillFilesById((new CodexClientTransformer())->targetFiles($inventory));

        self::assertSame(array_keys($claudeFiles), array_keys($codexFiles));
        foreach ($claudeFiles as $skillId => $claudeFile) {
            self::assertSame(
                $claudeFile->content,
                $codexFiles[$skillId]->content,
                sprintf('Claude and Codex per-skill bytes must match for "%s".', $skillId),
            );
        }
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

    /**
     * @param list<\Waaseyaa\Bimaaji\Install\TargetFile> $files
     * @return array<string, \Waaseyaa\Bimaaji\Install\TargetFile>
     */
    private function skillFilesById(array $files): array
    {
        $byId = [];
        foreach ($files as $file) {
            if ($file->sourceSkill !== null) {
                $byId[$file->sourceSkill] = $file;
            }
        }
        ksort($byId);

        return $byId;
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
