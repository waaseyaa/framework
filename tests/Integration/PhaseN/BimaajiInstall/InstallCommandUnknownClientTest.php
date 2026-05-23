<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\BimaajiInstall;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * T015 — unknown-client error surface (FR-008, NFR-004).
 *
 * Drives `bimaaji:install --client=<bad>` and asserts the error envelope
 * includes a Levenshtein suggestion for near-typos and falls back to a
 * plain available-client list for far-typos.
 *
 * @api
 */
#[CoversNothing]
final class InstallCommandUnknownClientTest extends BimaajiInstallTestCase
{
    #[Test]
    public function nearTypoSurfacesLevenshteinSuggestion(): void
    {
        $this->seedTwoSkillFixtures();

        $tester = $this->makeTester();
        $tester->execute(['--client=clade', '--force']);

        self::assertNotSame(0, $tester->getExitCode());

        $output = $tester->getOutput();
        self::assertStringContainsString('unknown client "clade"', $output);
        self::assertStringContainsString('Did you mean "claude"?', $output);
        self::assertStringContainsString('Available: ', $output);
    }

    #[Test]
    public function farTypoListsAllAvailableClientsWithoutSuggestion(): void
    {
        $this->seedTwoSkillFixtures();

        $tester = $this->makeTester();
        $tester->execute(['--client=zzzzzzzzzz', '--force']);

        self::assertNotSame(0, $tester->getExitCode());

        $output = $tester->getOutput();
        self::assertStringContainsString('unknown client "zzzzzzzzzz"', $output);
        self::assertStringNotContainsString('Did you mean', $output);
        // Every shipped client must be listed.
        foreach (['claude', 'cursor', 'codex', 'copilot', 'gemini', 'windsurf', 'junie'] as $client) {
            self::assertStringContainsString($client, $output);
        }
    }

    #[Test]
    public function unknownClientDoesNotAffectFollowingValidClient(): void
    {
        $this->seedTwoSkillFixtures();

        $tester = $this->makeTester();
        $tester->execute(['--client=clade', '--client=cursor', '--force']);

        // Exit code is non-zero because at least one client failed.
        self::assertNotSame(0, $tester->getExitCode());

        // But the valid client still installed.
        self::assertFileExists($this->tempDir . '/.cursorrules');
        self::assertStringContainsString('Client cursor: 1 written, 0 unchanged, 0 skipped.', $tester->getOutput());
    }
}
