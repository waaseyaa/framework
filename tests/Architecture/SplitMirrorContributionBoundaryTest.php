<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SplitMirrorContributionBoundaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function mirror_overlay_is_deterministic_and_preserves_the_exact_split_parent(): void
    {
        $output = [];
        $exitCode = 0;
        exec(
            'bash ' . escapeshellarg($this->root . '/tests/Release/SplitMirrorContributionBoundaryTest.sh') . ' 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('deterministic and preserves the exact split parent', implode("\n", $output));
    }

    #[Test]
    public function both_split_paths_install_the_contribution_boundary(): void
    {
        foreach (['split.yml', 'split-main.yml'] as $workflow) {
            $contents = (string) file_get_contents($this->root . '/.github/workflows/' . $workflow);
            self::assertStringContainsString(
                'bash bin/build-split-contribution-boundary',
                $contents,
                sprintf('%s must install the mirror contribution redirect after updating main.', $workflow),
            );
            self::assertStringContainsString('mirror_main_sha', $contents);
        }
    }

    #[Test]
    public function release_updates_are_atomic_and_selected_main_pushes_only_the_overlay(): void
    {
        $release = (string) file_get_contents($this->root . '/.github/workflows/split.yml');
        self::assertStringContainsString('git push --atomic split', $release);
        self::assertStringContainsString('"+${mirror_main_sha}:refs/heads/main"', $release);
        self::assertStringContainsString('"${SHA}:refs/tags/${TAG_NAME}"', $release);
        self::assertStringNotContainsString('"${SHA}:refs/heads/main"', $release);

        $selectedMain = (string) file_get_contents($this->root . '/.github/workflows/split-main.yml');
        self::assertStringContainsString('"${mirror_main_sha}:refs/heads/main"', $selectedMain);
        self::assertStringNotContainsString('"${split_sha}:refs/heads/main"', $selectedMain);
        self::assertStringContainsString('if [ "${actual}" != "${mirror_main_sha}" ]', $selectedMain);
    }

    #[Test]
    public function templates_redirect_issues_and_pull_requests_to_the_monorepo(): void
    {
        $issueConfig = (string) file_get_contents(
            $this->root . '/resources/split-mirror/.github/ISSUE_TEMPLATE/config.yml',
        );
        $pullRequest = (string) file_get_contents(
            $this->root . '/resources/split-mirror/.github/PULL_REQUEST_TEMPLATE.md',
        );

        self::assertStringContainsString('blank_issues_enabled: false', $issueConfig);
        self::assertStringContainsString('https://github.com/waaseyaa/framework/issues/new/choose', $issueConfig);
        self::assertStringContainsString('Changes merged here would be overwritten', $pullRequest);
        self::assertStringContainsString('https://github.com/waaseyaa/framework', $pullRequest);
    }
}
