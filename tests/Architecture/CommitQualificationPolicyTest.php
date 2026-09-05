<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins commit-qualification policy: checkpoint vs review candidate, squash
 * landings, and the documented release-cut non-squash boundary (#2903).
 */
#[CoversNothing]
final class CommitQualificationPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function cookbook_states_checkpoint_versus_review_candidate_and_squash(): void
    {
        $cookbook = (string) file_get_contents($this->root . '/docs/cookbook/commit-qualification.md');

        self::assertStringContainsString('recoverable checkpoints', $cookbook);
        self::assertStringContainsString('review-candidate head', $cookbook);
        self::assertStringContainsString('governed squash', $cookbook);
        self::assertStringContainsString('There are **no** per-commit full-suite', $cookbook);
        self::assertStringContainsString('Non-squash exception', $cookbook);
        self::assertStringContainsString('release-cut', $cookbook);
        self::assertStringContainsString('composer test` must pass before any commit', $cookbook);
    }

    #[Test]
    public function auto_merge_uses_squash_only(): void
    {
        $workflow = (string) file_get_contents($this->root . '/.github/workflows/auto-merge.yml');

        self::assertStringContainsString('--squash', $workflow);
        self::assertStringNotContainsString('--merge', $workflow);
        self::assertStringNotContainsString('--rebase', $workflow);
    }

    #[Test]
    public function release_cut_documents_exact_sha_non_squash_boundary(): void
    {
        $workflow = (string) file_get_contents($this->root . '/.github/workflows/release-cut.yml');

        self::assertStringContainsString('EXACT four-gate-tested SHA', $workflow);
        self::assertStringContainsString('squash, rebase, merge commit', $workflow);
    }

    #[Test]
    public function pre_commit_does_not_run_full_suites_or_preflight(): void
    {
        $script = (string) file_get_contents($this->root . '/bin/project-hooks');
        if (!preg_match('/^pre_commit\(\) \{(.*?)^\}/ms', $script, $matches)) {
            self::fail('pre_commit function not found in bin/project-hooks');
        }
        $body = $matches[1];

        self::assertStringContainsString('check-portable-paths', $body);
        self::assertStringContainsString('composer cs-check', $body);
        self::assertStringNotContainsString('check-pr-preflight', $body);
        self::assertStringNotContainsString('phpunit', $body);
        self::assertStringNotContainsString('composer test', $body);
    }

    #[Test]
    public function codex_patch_removes_every_commit_composer_test_claim(): void
    {
        $patch = (string) file_get_contents(
            $this->root . '/docs/change-records/FW-DELIVERY-COMMIT-POLICY-01-codex-patch.md',
        );

        self::assertStringContainsString('composer test` must pass before any commit', $patch);
        self::assertStringContainsString('**Delete** this bullet:', $patch);
        self::assertStringContainsString('recoverable checkpoints', $patch);
        self::assertStringContainsString('Do **not** add per-commit CI jobs', $patch);
    }
}
