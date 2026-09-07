<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry;
use Waaseyaa\Bimaaji\Install\SkillDeliveryMode;

#[CoversClass(ClientCapabilityRegistry::class)]
final class ClientCapabilityRegistryTest extends TestCase
{
    #[Test]
    public function clientIdsListsExactlyTheSevenLaunchClientsSorted(): void
    {
        self::assertSame(
            ['claude', 'codex', 'copilot', 'cursor', 'gemini', 'junie', 'windsurf'],
            ClientCapabilityRegistry::default()->clientIds(),
        );
    }

    #[Test]
    public function allReturnsTheSameCountAsClientIds(): void
    {
        $registry = ClientCapabilityRegistry::default();

        self::assertCount(7, $registry->all());
        self::assertCount(7, $registry->clientIds());
    }

    #[Test]
    public function forAnUnregisteredClientReturnsNull(): void
    {
        self::assertNull(ClientCapabilityRegistry::default()->for('does-not-exist'));
    }

    #[Test]
    public function claudeAndCodexAreTheOnlyPerSkillFileClients(): void // #2660 Part B
    {
        $registry = ClientCapabilityRegistry::default();
        $expectedSkillDirectory = ['claude' => '.claude/skills', 'codex' => '.agents/skills'];

        foreach ($registry->all() as $capabilities) {
            if (isset($expectedSkillDirectory[$capabilities->clientId])) {
                self::assertSame(SkillDeliveryMode::PerSkillFile, $capabilities->skillDelivery);
                self::assertTrue($capabilities->requiresFrontmatterAtByteZero);
                self::assertSame($expectedSkillDirectory[$capabilities->clientId], $capabilities->skillDirectory);
                self::assertSame('waaseyaa-', $capabilities->skillIdPrefix);
            } else {
                self::assertSame(
                    SkillDeliveryMode::SingleConsolidatedFile,
                    $capabilities->skillDelivery,
                    sprintf('"%s" was expected to be SingleConsolidatedFile.', $capabilities->clientId),
                );
                self::assertFalse($capabilities->requiresFrontmatterAtByteZero);
                self::assertNull($capabilities->skillDirectory);
                self::assertNull($capabilities->skillIdPrefix);
            }
        }
    }

    /**
     * Pins the registry's `guidancePath` against every path documented in
     * "Supported clients" (docs/specs/bimaaji-install.md) — the CURRENT
     * shipped convention, not the #2660-proposed one. A change here is a
     * change to what `bimaaji:install` writes to disk.
     */
    #[Test]
    public function guidancePathMatchesTheDocumentedConventionForEveryClient(): void
    {
        $expected = [
            'claude' => '.claude/CLAUDE-WAASEYAA.md',
            'codex' => 'AGENTS.md',
            'copilot' => '.github/copilot-instructions.md',
            'cursor' => '.cursorrules',
            'gemini' => 'GEMINI.md',
            'junie' => '.junie/guidelines.md',
            'windsurf' => '.windsurfrules',
        ];

        $registry = ClientCapabilityRegistry::default();
        foreach ($expected as $clientId => $guidancePath) {
            self::assertSame(
                $guidancePath,
                $registry->for($clientId)?->guidancePath,
                sprintf('Unexpected guidancePath for client "%s".', $clientId),
            );
        }
    }

    #[Test]
    public function twoCallsToDefaultProduceEquivalentButIndependentRegistries(): void
    {
        $first = ClientCapabilityRegistry::default();
        $second = ClientCapabilityRegistry::default();

        self::assertNotSame($first, $second);
        self::assertEquals($first->all(), $second->all());
    }
}
