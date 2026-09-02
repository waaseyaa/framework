<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\ClientCapabilities;
use Waaseyaa\Bimaaji\Install\SkillDeliveryMode;

#[CoversClass(ClientCapabilities::class)]
final class ClientCapabilitiesTest extends TestCase
{
    #[Test]
    public function skillDirectoryNameAppliesThePrefix(): void
    {
        $capabilities = new ClientCapabilities(
            clientId: 'claude',
            skillDelivery: SkillDeliveryMode::PerSkillFile,
            requiresFrontmatterAtByteZero: true,
            guidancePath: '.claude/CLAUDE-WAASEYAA.md',
            skillDirectory: '.claude/skills',
            skillIdPrefix: 'waaseyaa-',
        );

        self::assertSame('waaseyaa-entity-system', $capabilities->skillDirectoryName('entity-system'));
    }

    #[Test]
    public function skillDirectoryNameWithNoPrefixReturnsTheBareId(): void
    {
        $capabilities = new ClientCapabilities(
            clientId: 'claude',
            skillDelivery: SkillDeliveryMode::PerSkillFile,
            requiresFrontmatterAtByteZero: true,
            guidancePath: '.claude/CLAUDE-WAASEYAA.md',
            skillDirectory: '.claude/skills',
        );

        self::assertSame('entity-system', $capabilities->skillDirectoryName('entity-system'));
    }

    #[Test]
    public function skillFilePathComposesTheDirectoryAndFileName(): void
    {
        $capabilities = new ClientCapabilities(
            clientId: 'claude',
            skillDelivery: SkillDeliveryMode::PerSkillFile,
            requiresFrontmatterAtByteZero: true,
            guidancePath: '.claude/CLAUDE-WAASEYAA.md',
            skillDirectory: '.claude/skills',
            skillIdPrefix: 'waaseyaa-',
        );

        self::assertSame(
            '.claude/skills/waaseyaa-entity-system/SKILL.md',
            $capabilities->skillFilePath('entity-system'),
        );
    }

    #[Test]
    public function skillFilePathThrowsForASingleConsolidatedFileClient(): void
    {
        $capabilities = new ClientCapabilities(
            clientId: 'cursor',
            skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
            requiresFrontmatterAtByteZero: false,
            guidancePath: '.cursorrules',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/does not support per-skill file delivery/');

        $capabilities->skillFilePath('entity-system');
    }

    #[Test]
    public function skillFilePathThrowsWhenNoSkillDirectoryIsDeclaredEvenIfDeliveryClaimsPerSkill(): void
    {
        // Defensive: a PerSkillFile capability with a null skillDirectory is
        // a construction error, not a reachable shape for the registered
        // clients — but skillFilePath() must still fail loudly rather than
        // emit a malformed path with an empty directory segment.
        $capabilities = new ClientCapabilities(
            clientId: 'broken',
            skillDelivery: SkillDeliveryMode::PerSkillFile,
            requiresFrontmatterAtByteZero: true,
            guidancePath: 'GUIDE.md',
        );

        $this->expectException(\LogicException::class);

        $capabilities->skillFilePath('entity-system');
    }
}
