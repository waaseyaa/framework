<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\ClientCapabilities;
use Waaseyaa\Bimaaji\Install\ClientCapabilityException;
use Waaseyaa\Bimaaji\Install\SkillDeliveryMode;

#[CoversClass(ClientCapabilities::class)]
#[CoversClass(ClientCapabilityException::class)]
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

    // --- Closed construction invariants (#2660 Part A repair round) ---------
    //
    // Every rejected shape below was constructible before the repair round.
    // Because a transformer now DERIVES output from these fields (Claude's
    // per-skill frontmatter from `requiresFrontmatterAtByteZero`, every
    // client's paths from `guidancePath`/`skillDirectory`), a nonsensical
    // instance is no longer inert data — it is a silently wrong install.
    // Fail at construction, where the offending literal is in scope.

    #[Test]
    public function anEmptyClientIdIsRejected(): void
    {
        $this->expectException(ClientCapabilityException::class);
        $this->expectExceptionMessageMatches('/clientId must be a non-empty string/');

        new ClientCapabilities(
            clientId: '',
            skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
            requiresFrontmatterAtByteZero: false,
            guidancePath: 'AGENTS.md',
        );
    }

    #[Test]
    public function aBlankClientIdIsRejected(): void
    {
        $this->expectException(ClientCapabilityException::class);

        new ClientCapabilities(
            clientId: "  \t ",
            skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
            requiresFrontmatterAtByteZero: false,
            guidancePath: 'AGENTS.md',
        );
    }

    #[Test]
    public function anEmptyGuidancePathIsRejected(): void
    {
        $this->expectException(ClientCapabilityException::class);
        $this->expectExceptionMessageMatches('/guidancePath must be a non-empty string/');

        new ClientCapabilities(
            clientId: 'codex',
            skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
            requiresFrontmatterAtByteZero: false,
            guidancePath: '',
        );
    }

    #[Test]
    public function anEmptySkillDirectoryIsRejected(): void
    {
        $this->expectException(ClientCapabilityException::class);
        $this->expectExceptionMessageMatches('/skillDirectory must be a non-empty string/');

        new ClientCapabilities(
            clientId: 'claude',
            skillDelivery: SkillDeliveryMode::PerSkillFile,
            requiresFrontmatterAtByteZero: true,
            guidancePath: '.claude/CLAUDE-WAASEYAA.md',
            skillDirectory: '',
        );
    }

    #[Test]
    public function anEmptySkillIdPrefixIsRejected(): void
    {
        // A '' prefix is not "no prefix" — it is a caller that meant to set
        // one and produced a no-op. Null is how you say "no prefix".
        $this->expectException(ClientCapabilityException::class);
        $this->expectExceptionMessageMatches('/skillIdPrefix must be a non-empty string/');

        new ClientCapabilities(
            clientId: 'claude',
            skillDelivery: SkillDeliveryMode::PerSkillFile,
            requiresFrontmatterAtByteZero: true,
            guidancePath: '.claude/CLAUDE-WAASEYAA.md',
            skillDirectory: '.claude/skills',
            skillIdPrefix: '',
        );
    }

    #[Test]
    public function aPerSkillClientWithoutASkillDirectoryIsRejected(): void
    {
        // Previously constructible; skillFilePath() then threw at render time
        // (or, worse, a future caller emitted "/waaseyaa-x/SKILL.md").
        $this->expectException(ClientCapabilityException::class);
        $this->expectExceptionMessageMatches('/PerSkillFile delivery but declares no skillDirectory/');

        new ClientCapabilities(
            clientId: 'broken',
            skillDelivery: SkillDeliveryMode::PerSkillFile,
            requiresFrontmatterAtByteZero: true,
            guidancePath: 'GUIDE.md',
        );
    }

    #[Test]
    public function aConsolidatedClientCarryingASkillDirectoryIsRejected(): void
    {
        $this->expectException(ClientCapabilityException::class);
        $this->expectExceptionMessageMatches('/SingleConsolidatedFile delivery but declares a skillDirectory/');

        new ClientCapabilities(
            clientId: 'cursor',
            skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
            requiresFrontmatterAtByteZero: false,
            guidancePath: '.cursorrules',
            skillDirectory: '.cursor/skills',
        );
    }

    #[Test]
    public function aConsolidatedClientRequiringFrontmatterIsRejected(): void
    {
        // The contradiction that matters most now that ClaudeClientTransformer
        // derives its frontmatter from this flag: a consolidated client emits
        // no per-skill file at all, so "needs frontmatter at byte 0" can only
        // be a copy-paste error, and nothing downstream would ever read it.
        $this->expectException(ClientCapabilityException::class);
        $this->expectExceptionMessageMatches('/SingleConsolidatedFile delivery but requires frontmatter/');

        new ClientCapabilities(
            clientId: 'cursor',
            skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
            requiresFrontmatterAtByteZero: true,
            guidancePath: '.cursorrules',
        );
    }

    #[Test]
    public function aConsolidatedClientCarryingASkillIdPrefixIsRejected(): void
    {
        $this->expectException(ClientCapabilityException::class);

        new ClientCapabilities(
            clientId: 'cursor',
            skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
            requiresFrontmatterAtByteZero: false,
            guidancePath: '.cursorrules',
            skillIdPrefix: 'waaseyaa-',
        );
    }

    #[Test]
    public function theRejectionNamesTheOffendingClientAsTypedData(): void
    {
        // The id is exposed on the exception, not only interpolated into the
        // message, so a caller registering many clients can report which
        // entry it was without parsing prose.
        try {
            new ClientCapabilities(
                clientId: 'windsurf',
                skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
                requiresFrontmatterAtByteZero: true,
                guidancePath: '.windsurfrules',
            );
            self::fail('Expected a ClientCapabilityException.');
        } catch (ClientCapabilityException $exception) {
            self::assertSame('windsurf', $exception->clientId);
        }
    }

    #[Test]
    public function everyRegisteredClientSatisfiesTheInvariants(): void
    {
        // The registry builds its seven entries through the same constructor,
        // so this is really a statement that the shipped mapping is inside
        // the closed set — and that a future edit to default() cannot leave
        // it outside without a red test.
        $registry = \Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry::default();

        self::assertCount(7, $registry->all());

        foreach ($registry->all() as $capabilities) {
            self::assertNotSame('', trim($capabilities->clientId));
            self::assertNotSame('', trim($capabilities->guidancePath));

            if ($capabilities->skillDelivery === SkillDeliveryMode::PerSkillFile) {
                self::assertNotNull($capabilities->skillDirectory);
                continue;
            }

            self::assertNull($capabilities->skillDirectory);
            self::assertNull($capabilities->skillIdPrefix);
            self::assertFalse($capabilities->requiresFrontmatterAtByteZero);
        }
    }
}
