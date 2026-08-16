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
    public function dependabot_admin_rebuild_is_actor_scoped_and_separates_untrusted_build_from_write_token(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../../.github/workflows/dependabot-admin-dist.yml');
        self::assertIsString($workflow);
        self::assertStringContainsString("github.actor == 'dependabot[bot]'", $workflow);
        self::assertStringContainsString('persist-credentials: false', $workflow);
        self::assertStringContainsString('bin/build-admin-dist', $workflow);
        self::assertStringContainsString('upload-artifact', $workflow);
        self::assertStringContainsString('download-artifact', $workflow);
        self::assertMatchesRegularExpression('/^  publish:.*?^    needs: build$/ms', $workflow);

        $buildJob = strstr($workflow, '  build:');
        self::assertIsString($buildJob);
        $buildJob = strstr($buildJob, '  publish:', true);
        self::assertIsString($buildJob);
        self::assertStringContainsString('contents: read', $buildJob);
        self::assertStringNotContainsString('GH_TOKEN:', $buildJob);
        self::assertStringNotContainsString('contents: write', $buildJob);
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
    public function admin_dist_workflows_bootstrap_the_trusted_php_build_runtime_without_scripts_or_plugins(): void
    {
        foreach (['admin-dist.yml', 'dependabot-admin-dist.yml'] as $filename) {
            $workflow = file_get_contents(__DIR__ . '/../../.github/workflows/' . $filename);
            self::assertIsString($workflow);

            $install = 'composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins';
            self::assertStringContainsString($install, $workflow, $filename);

            $installOffset = strpos($workflow, $install);
            $buildOffset = strpos($workflow, 'bin/build-admin-dist');
            self::assertIsInt($installOffset);
            self::assertIsInt($buildOffset);
            self::assertLessThan($buildOffset, $installOffset, $filename);
        }
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
