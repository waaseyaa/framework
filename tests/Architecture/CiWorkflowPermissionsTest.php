<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Holds the least-privilege GitHub Actions token contract from #3092.
 */
#[CoversNothing]
final class CiWorkflowPermissionsTest extends TestCase
{
    #[Test]
    public function every_workflow_declares_its_top_level_token_permissions(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/.github/workflows/*.yml');
        self::assertNotFalse($files);
        self::assertNotSame([], $files);

        foreach ($files as $file) {
            $workflow = Yaml::parseFile($file);
            self::assertIsArray($workflow, $file);
            self::assertArrayHasKey(
                'permissions',
                $workflow,
                basename($file) . ' must never inherit the repository-default GITHUB_TOKEN grant.',
            );
            self::assertIsArray($workflow['permissions'], $file);
        }
    }

    #[Test]
    public function admin_dist_executes_dependencies_without_write_credentials(): void
    {
        $workflow = $this->workflow('admin-dist.yml');
        self::assertSame(['contents' => 'read'], $workflow['permissions']);

        $build = $workflow['jobs']['build'];
        self::assertSame(['contents' => 'read'], $build['permissions']);
        self::assertSame('${{ steps.diff.outputs.changed }}', $build['outputs']['changed'] ?? null);

        $publish = $workflow['jobs']['build-and-publish'];
        self::assertSame(
            ['contents' => 'write', 'pull-requests' => 'write', 'actions' => 'write'],
            $publish['permissions'],
        );
        self::assertSame('build', $publish['needs'] ?? null);
        self::assertSame("needs.build.outputs.changed == 'true'", $publish['if'] ?? null);

        self::assertTrue($this->usesAction($build['steps'], 'actions/upload-artifact'));
        self::assertTrue($this->usesAction($publish['steps'], 'actions/download-artifact'));
        self::assertFalse($this->usesAction($publish['steps'], 'actions/setup-node'));
        self::assertFalse($this->usesAction($publish['steps'], 'shivammathur/setup-php'));
    }

    #[Test]
    public function auto_merge_scopes_write_permissions_to_its_trusted_job(): void
    {
        $workflow = $this->workflow('auto-merge.yml');
        self::assertSame(['contents' => 'read'], $workflow['permissions']);
        self::assertSame(
            ['contents' => 'write', 'pull-requests' => 'write', 'actions' => 'write'],
            $workflow['jobs']['enable-auto-merge']['permissions'],
        );
    }

    #[Test]
    public function skeleton_publication_is_atomic_and_never_force_moves_a_release_tag(): void
    {
        $workflow = $this->workflow('sync-skeleton.yml');
        self::assertSame(['contents' => 'read'], $workflow['permissions']);

        $script = '';
        foreach ($workflow['jobs']['sync']['steps'] as $step) {
            if (str_contains((string) ($step['name'] ?? ''), 'Commit, tag, and push')) {
                $script = (string) ($step['run'] ?? '');
            }
        }

        self::assertNotSame('', $script);
        self::assertStringContainsString('RETAG_BLOCKED', $script);
        self::assertStringContainsString('git push --atomic origin HEAD:refs/heads/main', $script);
        self::assertStringNotContainsString('git tag "${TAG_NAME}" -f', $script);
        self::assertStringNotContainsString('git push origin main --tags --force', $script);
    }

    /** @return array<string, mixed> */
    private function workflow(string $name): array
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/' . $name);
        self::assertIsArray($workflow);

        return $workflow;
    }

    /** @param list<array<string, mixed>> $steps */
    private function usesAction(array $steps, string $action): bool
    {
        foreach ($steps as $step) {
            if (str_starts_with((string) ($step['uses'] ?? ''), $action . '@')) {
                return true;
            }
        }

        return false;
    }
}
