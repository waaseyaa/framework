<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * #2641: live spec prose that still defers current capability to a closed
 * issue must be visible as a warn-only nightly finding. The PR-diff drift
 * detector cannot see a dependency that closed elsewhere.
 *
 * Architecture proofs stay offline: every case injects an issue-state
 * snapshot. The hosted nightly job is the only surface that resolves live
 * GitHub state.
 */
#[CoversNothing]
final class StaleSpecDeferralsGateTest extends TestCase
{
    private string $repoRoot;

    private string $gate;

    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->gate = $this->repoRoot . '/bin/check-stale-spec-deferrals';
        self::assertFileExists($this->gate);
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa_stale_deferral_' . uniqid('', true);
        mkdir($this->tmpRoot . '/docs/specs', 0o755, true);
        mkdir($this->tmpRoot . '/docs/history/plans', 0o755, true);
        mkdir($this->tmpRoot . '/kitty-specs', 0o755, true);
        mkdir($this->tmpRoot . '/tools', 0o755, true);
        $this->writeBaseline("# empty\n");
        $this->writeSnapshot([
            '9001' => ['state' => 'closed', 'pull_request' => false],
            '9002' => ['state' => 'open', 'pull_request' => false],
            '9003' => ['state' => 'closed', 'pull_request' => true],
        ]);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmpRoot);
    }

    #[Test]
    public function live_present_tense_deferral_to_a_closed_issue_warns_and_stays_non_blocking(): void
    {
        $this->writeSpec("Pagination waits for #9001.\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, "Warn-only findings must not fail the job.\n{$out}");
        self::assertStringContainsString('STALE-DEFERRAL', $out);
        self::assertStringContainsString('docs/specs/live.md:1', $out);
        self::assertStringContainsString('#9001', $out);
        self::assertStringContainsString('ISSUE-CLOSED', $out);
        self::assertStringContainsString('::warning', $out);
    }

    #[Test]
    public function tracked_by_a_closed_issue_warns(): void
    {
        $this->writeSpec("The generic console adapter is tracked by #9001.\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('STALE-DEFERRAL', $out);
        self::assertStringContainsString('tracked by', $out);
    }

    #[Test]
    public function strong_phrase_can_cross_a_soft_line_wrap(): void
    {
        $this->writeSpec(
            "This capability is tracked by\n"
            . "#9001.\n",
        );

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function strong_phrase_does_not_cross_a_paragraph_boundary(): void
    {
        $this->writeSpec("This capability is tracked by\n\nUnrelated reference #9001.\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function strong_phrase_does_not_cross_a_sentence_boundary(): void
    {
        $this->writeSpec(
            'This capability is tracked by the architecture registry. '
            . "Unrelated reference #9001.\n",
        );

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function adjacent_after_hash_to_a_closed_issue_warns(): void
    {
        $this->writeSpec("Pagination after #9001 remains a Future-column constraint.\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function deferred_to_hash_to_a_closed_issue_warns(): void
    {
        $this->writeSpec("The K4 notice is deferred to #9001.\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('STALE-DEFERRAL', $out);
        self::assertStringContainsString('deferred to', $out);
    }

    #[Test]
    public function deferred_to_a_follow_up_pr_with_a_mission_hash_is_not_a_deferral(): void
    {
        $this->writeSpec(
            "The K4 part A notice is deferred to a follow-up PR (mission #9001 WP07-A).\n",
        );

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function past_tense_until_with_query_string_question_mark_is_not_a_deferral(): void
    {
        $this->writeSpec(
            "The request page stayed absent. Until\n"
            . "#9001 no kernel supplied one, so `?page=` was never observed.\n",
        );

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function provenance_uses_after_hash_is_not_a_deferral(): void
    {
        $this->writeSpec(
            "The two-phase pattern EntityLifecycleAuditListener uses after #9001 inserts a row.\n",
        );

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function spec_reviewed_comment_deferrals_are_ignored(): void
    {
        $this->writeSpec(
            "<!-- Spec reviewed 2026-06-27 - deferred to #9001; leave dated-wrong. -->\n"
            . "Live body has no remaining deferral.\n",
        );

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
        self::assertStringContainsString('OK', $out);
    }

    #[Test]
    public function a_comment_hit_does_not_hide_a_later_live_hit_on_the_same_issue(): void
    {
        $this->writeSpec(
            "<!-- Spec reviewed: tracked by #9001 -->\n"
            . "The generic console adapter is tracked by #9001.\n",
        );

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('docs/specs/live.md:2', $out);
        self::assertStringContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function past_tense_narration_is_not_a_deferral(): void
    {
        $this->writeSpec(
            "Until #9001 the entity, vector and relationship tools in\n"
            . "`waaseyaa/ai-tools` emitted raw dumps.\n",
        );

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function historical_subclause_does_not_hide_a_present_deferral(): void
    {
        $this->writeSpec(
            "Although the legacy adapter was removed, pagination waits for #9001.\n",
        );

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function past_tense_did_until_is_not_a_deferral(): void
    {
        $this->writeSpec("`ping` did exactly this until #9001.\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function positional_after_is_not_a_temporal_deferral(): void
    {
        $this->writeSpec("JSON:API mounts after built-in routers through `McpRouter` (#9001).\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function positional_once_is_not_a_temporal_deferral(): void
    {
        $this->writeSpec("Recompute once its data has been inspected (G-026, #9001).\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function fenced_example_deferrals_are_ignored(): void
    {
        $this->writeSpec("```\nPagination waits for #9001.\n```\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function tilde_fenced_example_deferrals_are_ignored(): void
    {
        $this->writeSpec("~~~text\nPagination waits for #9001.\n~~~\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function shorter_fence_does_not_close_a_longer_fenced_example(): void
    {
        $this->writeSpec("````markdown\n```\nPagination waits for #9001.\n```\n````\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function unclosed_fenced_example_extends_to_end_of_document(): void
    {
        $this->writeSpec("```text\nPagination waits for #9001.\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function open_issues_and_merged_prs_are_never_flagged(): void
    {
        $this->writeSpec("Waits for #9002 and is tracked by #9003.\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function an_allowlisted_finding_is_silent(): void
    {
        $this->writeSpec("Pagination waits for #9001.\n");
        $this->writeBaseline("docs/specs/live.md:1  # known remaining Future-column wording until #2636 lands\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
        self::assertStringContainsString('1 allowlisted', $out);
    }

    #[Test]
    public function incomplete_allowlist_entries_fail_closed(): void
    {
        $this->writeSpec("Pagination waits for #9001.\n");
        $this->writeBaseline("docs/specs/live.md:1\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(2, $exit, $out);
        self::assertStringContainsString('Incomplete baseline entries', $out);
    }

    #[Test]
    public function unresolved_issue_state_fails_closed(): void
    {
        $this->writeSpec("Pagination waits for #9999.\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(2, $exit, $out);
        self::assertStringContainsString('UNRESOLVED', $out);
        self::assertStringContainsString('#9999', $out);
    }

    #[Test]
    public function history_plans_and_kitty_specs_are_never_scanned(): void
    {
        file_put_contents(
            $this->tmpRoot . '/docs/history/plans/old.md',
            "Pagination waits for #9001.\n",
        );
        file_put_contents(
            $this->tmpRoot . '/kitty-specs/old.md',
            "Pagination waits for #9001.\n",
        );
        $this->writeSpec("No deferral here.\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('STALE-DEFERRAL', $out);
        self::assertStringNotContainsString('history', $out);
        self::assertStringNotContainsString('kitty-specs', $out);
    }

    #[Test]
    public function fail_on_findings_is_opt_in_not_the_default(): void
    {
        $this->writeSpec("Pagination waits for #9001.\n");

        [$exit, $out] = $this->runGate(['--fail-on-findings']);

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('STALE-DEFERRAL', $out);
    }

    #[Test]
    public function nightly_hosts_the_warn_only_scan_and_preflight_does_not(): void
    {
        $nightly = (string) file_get_contents($this->repoRoot . '/.github/workflows/nightly.yml');
        $preflight = (string) file_get_contents($this->repoRoot . '/tools/preflight-gates.json');

        self::assertStringContainsString('nightly/stale-spec-deferrals', $nightly);
        self::assertStringContainsString('php bin/check-stale-spec-deferrals', $nightly);
        self::assertStringNotContainsString('--fail-on-findings', $nightly);
        self::assertStringNotContainsString('check-stale-spec-deferrals', $preflight);
        self::assertStringContainsString('composer test:random', $nightly);
    }

    /**
     * @param list<string> $extra
     * @return array{0: int|null, 1: string}
     */
    private function runGate(array $extra = []): array
    {
        $process = new Process(
            [
                PHP_BINARY,
                $this->gate,
                '--root',
                $this->tmpRoot,
                '--snapshot',
                $this->tmpRoot . '/snapshot.json',
                '--baseline',
                $this->tmpRoot . '/tools/stale-spec-deferrals-baseline.txt',
                ...$extra,
            ],
            $this->repoRoot,
        );
        $process->setTimeout(30.0);
        $process->run();

        return [$process->getExitCode(), $process->getOutput() . $process->getErrorOutput()];
    }

    private function writeSpec(string $body): void
    {
        file_put_contents($this->tmpRoot . '/docs/specs/live.md', $body);
    }

    private function writeBaseline(string $body): void
    {
        file_put_contents($this->tmpRoot . '/tools/stale-spec-deferrals-baseline.txt', $body);
    }

    /** @param array<string, array{state: string, pull_request: bool}> $states */
    private function writeSnapshot(array $states): void
    {
        file_put_contents(
            $this->tmpRoot . '/snapshot.json',
            json_encode($states, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
        );
    }
}
