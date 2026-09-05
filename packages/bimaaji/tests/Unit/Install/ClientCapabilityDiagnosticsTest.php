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
    public function singleFileClientsWarnWhenSkillsAreRequested(): void
    {
        $capabilities = ClientCapabilityRegistry::default()->for('cursor');
        self::assertNotNull($capabilities);

        $warnings = ClientCapabilityDiagnostics::warnings($capabilities, ['guidelines', 'skills']);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('skills for client "cursor" are folded into .cursorrules', $warnings[0]);
    }

    #[Test]
    public function perSkillClientsDoNotWarnForSkills(): void
    {
        $codex = ClientCapabilityRegistry::default()->for('codex');
        self::assertNotNull($codex);
        self::assertSame([], ClientCapabilityDiagnostics::warnings($codex, ['guidelines', 'skills']));
    }

    #[Test]
    public function mcpConfigurationRequestsProduceDeterministicWarnings(): void
    {
        $claude = ClientCapabilityRegistry::default()->for('claude');
        self::assertNotNull($claude);

        $warnings = ClientCapabilityDiagnostics::warnings($claude, ['guidelines', 'skills', 'mcp_configuration']);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('MCP configuration is not generated', $warnings[0]);
    }

    #[Test]
    public function perSkillClientsExposeSkillsSurface(): void
    {
        $claude = ClientCapabilityRegistry::default()->for('claude');
        self::assertNotNull($claude);
        self::assertContains(ClientCapabilitySurface::Skills, $claude->supportedSurfaces());
    }
}
