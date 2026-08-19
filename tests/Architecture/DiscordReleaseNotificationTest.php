<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A release announcement is a claim that the release is consumable, so it must
 * be gated on proof that it is.
 *
 * v0.1.0-alpha.296 was announced while its Skeleton Smoke was failing: the
 * workflow triggered on tag creation and waited only for a run named "CI", so
 * it fired before Split Monorepo had pushed the package repos, before Packagist
 * had crawled them, and before the published artifact had been installed by
 * anyone. These assertions pin the ordering that makes the announcement mean
 * something.
 */
#[CoversNothing]
final class DiscordReleaseNotificationTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/.github/workflows/discord-release.yml';
        self::assertFileExists($path);
        $this->workflow = (string) file_get_contents($path);
    }

    #[Test]
    public function it_is_chained_behind_split_publication_rather_than_tag_creation(): void
    {
        self::assertStringContainsString('workflow_run:', $this->workflow);
        self::assertStringContainsString("workflows: ['Split Monorepo']", $this->workflow);
        self::assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $this->workflow);

        // Tag creation is precisely the signal that proved insufficient.
        self::assertStringNotContainsString("tags: ['v*']", $this->workflow);
        self::assertDoesNotMatchRegularExpression('/^on:\s*\n\s+push:/m', $this->workflow);
    }

    #[Test]
    public function it_requires_an_exact_sha_skeleton_smoke_success(): void
    {
        self::assertStringContainsString('Skeleton Smoke (Packaged-form CI)', $this->workflow);
        self::assertStringContainsString('head_sha: releaseSha', $this->workflow);
        self::assertStringContainsString('github.event.workflow_run.head_sha', $this->workflow);
        self::assertStringContainsString("run.conclusion === 'success'", $this->workflow);
    }

    #[Test]
    public function it_fails_closed_on_every_non_success_proof(): void
    {
        // Any terminal conclusion other than success, and the absent-run case,
        // must refuse — never fall through to announcing.
        self::assertStringContainsString('refusing to announce', $this->workflow);
        self::assertSame(
            2,
            substr_count($this->workflow, 'refusing to announce'),
            'Both the non-success conclusion and the missing/timed-out run must refuse.',
        );
        self::assertStringContainsString('core.setFailed', $this->workflow);

        // A rejected webhook must not be a warning: a silent non-delivery reads
        // identically to a successful announcement.
        self::assertStringNotContainsString('core.warning', $this->workflow);
    }

    #[Test]
    public function it_publishes_no_milestone_progress(): void
    {
        foreach (['listMilestones', 'milestoneText', 'open_issues', 'closed_issues'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $this->workflow,
                'Release notifications must not carry milestone progress.',
            );
        }
    }

    #[Test]
    public function the_proof_gate_precedes_the_announcement(): void
    {
        // Anchor on the step marker: "name: Send Discord notification" also
        // names the job, which necessarily precedes every step in it.
        $gate = strpos($this->workflow, '- name: Require exact-SHA Skeleton Smoke success');
        $send = strpos($this->workflow, '- name: Send Discord notification');
        $checkout = strpos($this->workflow, '- uses: actions/checkout');

        self::assertIsInt($gate);
        self::assertIsInt($send);
        self::assertIsInt($checkout);
        self::assertLessThan($send, $gate, 'The Skeleton Smoke gate must run before anything is sent.');
        self::assertLessThan(
            $checkout,
            $gate,
            'The gate must be the first step, so no work happens before the proof is established.',
        );
    }
}
