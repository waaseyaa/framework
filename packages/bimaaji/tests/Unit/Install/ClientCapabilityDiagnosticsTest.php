<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\ClientCapabilityDiagnostics;
use Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry;
use Waaseyaa\Bimaaji\Install\ClientCapabilitySurface;

#[CoversClass(ClientCapabilityDiagnostics::class)]
final class ClientCapabilityDiagnosticsTest extends TestCase
{
    #[Test]
    public function aSingleFileClientWarnsWhenSkillsAreRequested(): void
    {
        $cursor = ClientCapabilityRegistry::default()->for('cursor');
        self::assertNotNull($cursor);

        $warnings = ClientCapabilityDiagnostics::warnings($cursor, ['guidelines', 'skills']);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('skills for client "cursor" are folded into .cursorrules', $warnings[0]);
    }

    #[Test]
    public function aPerSkillFileClientDoesNotWarnWhenSkillsAreRequested(): void
    {
        $codex = ClientCapabilityRegistry::default()->for('codex');
        self::assertNotNull($codex);

        self::assertSame([], ClientCapabilityDiagnostics::warnings($codex, ['guidelines', 'skills']));
    }

    #[Test]
    public function noWarningIsProducedWhenSkillsWereNotRequestedAtAll(): void
    {
        $cursor = ClientCapabilityRegistry::default()->for('cursor');
        self::assertNotNull($cursor);

        self::assertSame([], ClientCapabilityDiagnostics::warnings($cursor, ['guidelines']));
    }

    #[Test]
    public function requestingMcpConfigurationWarnsForEveryClient(): void
    {
        $claude = ClientCapabilityRegistry::default()->for('claude');
        self::assertNotNull($claude);

        $warnings = ClientCapabilityDiagnostics::warnings($claude, ['guidelines', 'skills', 'mcp_configuration']);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('MCP configuration for client', $warnings[0]);
    }

    #[Test]
    public function theShorthandMcpAliasIsRecognised(): void
    {
        $cursor = ClientCapabilityRegistry::default()->for('cursor');
        self::assertNotNull($cursor);

        $warnings = ClientCapabilityDiagnostics::warnings($cursor, ['mcp']);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('MCP configuration for client', $warnings[0]);
    }

    #[Test]
    public function aPerSkillClientExposesTheSkillsSurface(): void
    {
        $claude = ClientCapabilityRegistry::default()->for('claude');
        self::assertNotNull($claude);
        self::assertSame(
            [ClientCapabilitySurface::Guidelines, ClientCapabilitySurface::Skills],
            $claude->supportedSurfaces(),
        );
    }

    #[Test]
    public function aSingleFileClientExposesOnlyGuidelines(): void
    {
        $cursor = ClientCapabilityRegistry::default()->for('cursor');
        self::assertNotNull($cursor);
        self::assertSame([ClientCapabilitySurface::Guidelines], $cursor->supportedSurfaces());
    }
}
