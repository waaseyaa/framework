<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DependencyWorkflowTest extends TestCase
{
    #[Test]
    public function scheduled_workflow_audits_locked_composer_and_admin_npm_dependencies(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../../.github/workflows/dependency-audit.yml');
        self::assertIsString($workflow);
        self::assertStringContainsString('schedule:', $workflow);
        self::assertStringContainsString('composer audit --locked', $workflow);
        self::assertStringContainsString('npm audit --audit-level=high', $workflow);
    }

    #[Test]
    public function dependabot_admin_rebuild_is_actor_scoped_and_never_persists_checkout_credentials(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../../.github/workflows/dependabot-admin-dist.yml');
        self::assertIsString($workflow);
        self::assertStringContainsString("github.actor == 'dependabot[bot]'", $workflow);
        self::assertStringContainsString('persist-credentials: false', $workflow);
        self::assertStringContainsString('bin/build-admin-dist', $workflow);
    }

    #[Test]
    public function blocking_admin_jobs_use_lockfile_reproducible_installs(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../../.github/workflows/ci.yml');
        self::assertIsString($workflow);
        self::assertStringNotContainsString('npm install --no-audit --no-fund', $workflow);
        self::assertStringContainsString('npm ci --no-audit --no-fund', $workflow);
    }

    #[Test]
    public function bimaaji_declares_its_live_cli_command_integration_as_optional(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../../packages/bimaaji/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'Enables the graph:dump and bimaaji:install command integrations.',
            $manifest['suggest']['waaseyaa/cli'] ?? null,
        );
    }
}
