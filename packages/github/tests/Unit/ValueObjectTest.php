<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\GitHub\Issue;
use Waaseyaa\GitHub\Milestone;
use Waaseyaa\GitHub\PullRequest;

#[CoversClass(Issue::class)]
#[CoversClass(Milestone::class)]
#[CoversClass(PullRequest::class)]
final class ValueObjectTest extends TestCase
{
    #[Test]
    public function issueHoldsAllFields(): void
    {
        $issue = new Issue(
            number: 42,
            title: 'Test issue',
            body: 'Issue body',
            state: 'open',
            milestone: 'v1.0',
            labels: ['bug', 'priority'],
            assignees: ['alice', 'bob'],
        );

        self::assertSame(42, $issue->number);
        self::assertSame('Test issue', $issue->title);
        self::assertSame('Issue body', $issue->body);
        self::assertSame('open', $issue->state);
        self::assertSame('v1.0', $issue->milestone);
        self::assertSame(['bug', 'priority'], $issue->labels);
        self::assertSame(['alice', 'bob'], $issue->assignees);
    }

    #[Test]
    public function issueWithNullMilestone(): void
    {
        $issue = new Issue(
            number: 1,
            title: 'No milestone',
            body: '',
            state: 'open',
            milestone: null,
            labels: [],
            assignees: [],
        );

        self::assertNull($issue->milestone);
        self::assertSame([], $issue->labels);
        self::assertSame([], $issue->assignees);
    }

    #[Test]
    public function milestoneHoldsAllFields(): void
    {
        $milestone = new Milestone(
            number: 3,
            title: 'v1.5',
            description: 'Next release',
            state: 'open',
            openIssues: 10,
            closedIssues: 5,
        );

        self::assertSame(3, $milestone->number);
        self::assertSame('v1.5', $milestone->title);
        self::assertSame('Next release', $milestone->description);
        self::assertSame('open', $milestone->state);
        self::assertSame(10, $milestone->openIssues);
        self::assertSame(5, $milestone->closedIssues);
    }

    #[Test]
    public function pullRequestHoldsAllFields(): void
    {
        $pr = new PullRequest(
            number: 99,
            url: 'https://github.com/owner/repo/pull/99',
            title: 'feat: new feature',
            state: 'open',
        );

        self::assertSame(99, $pr->number);
        self::assertSame('https://github.com/owner/repo/pull/99', $pr->url);
        self::assertSame('feat: new feature', $pr->title);
        self::assertSame('open', $pr->state);
    }
}
