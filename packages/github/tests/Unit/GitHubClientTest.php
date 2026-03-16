<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\GitHub\GitHubClient;
use Waaseyaa\GitHub\Issue;
use Waaseyaa\GitHub\Milestone;
use Waaseyaa\GitHub\PullRequest;

#[CoversClass(GitHubClient::class)]
final class GitHubClientTest extends TestCase
{
    private function createClient(array $response): GitHubClient
    {
        return new class($response) extends GitHubClient {
            private array $cannedResponse;

            public function __construct(array $cannedResponse)
            {
                parent::__construct('fake-token', 'owner', 'repo');
                $this->cannedResponse = $cannedResponse;
            }

            protected function request(string $method, string $path, ?array $body = null): array
            {
                return $this->cannedResponse;
            }
        };
    }

    #[Test]
    public function getIssueReturnsIssue(): void
    {
        $client = $this->createClient([
            'number' => 42,
            'title' => 'Test issue',
            'body' => 'Body text',
            'state' => 'open',
            'milestone' => ['title' => 'v1.0'],
            'labels' => [['name' => 'bug']],
            'assignees' => [['login' => 'alice']],
        ]);

        $issue = $client->getIssue(42);

        self::assertInstanceOf(Issue::class, $issue);
        self::assertSame(42, $issue->number);
        self::assertSame('Test issue', $issue->title);
        self::assertSame('Body text', $issue->body);
        self::assertSame('v1.0', $issue->milestone);
        self::assertSame(['bug'], $issue->labels);
        self::assertSame(['alice'], $issue->assignees);
    }

    #[Test]
    public function getIssueWithNullMilestone(): void
    {
        $client = $this->createClient([
            'number' => 1,
            'title' => 'No milestone',
            'body' => null,
            'state' => 'open',
            'milestone' => null,
            'labels' => [],
            'assignees' => [],
        ]);

        $issue = $client->getIssue(1);

        self::assertNull($issue->milestone);
        self::assertSame('', $issue->body);
    }

    #[Test]
    public function listIssuesReturnsArray(): void
    {
        $client = new class() extends GitHubClient {
            public function __construct()
            {
                parent::__construct('fake-token', 'owner', 'repo');
            }

            protected function request(string $method, string $path, ?array $body = null): array
            {
                return [
                    [
                        'number' => 1,
                        'title' => 'First',
                        'body' => '',
                        'state' => 'open',
                        'milestone' => null,
                        'labels' => [],
                        'assignees' => [],
                    ],
                    [
                        'number' => 2,
                        'title' => 'Second',
                        'body' => '',
                        'state' => 'closed',
                        'milestone' => ['title' => 'v2.0'],
                        'labels' => [['name' => 'enhancement']],
                        'assignees' => [['login' => 'bob']],
                    ],
                ];
            }
        };

        $issues = $client->listIssues();

        self::assertCount(2, $issues);
        self::assertInstanceOf(Issue::class, $issues[0]);
        self::assertSame(1, $issues[0]->number);
        self::assertSame(2, $issues[1]->number);
        self::assertSame('v2.0', $issues[1]->milestone);
    }

    #[Test]
    public function getMilestoneReturnsMilestone(): void
    {
        $client = $this->createClient([
            'number' => 3,
            'title' => 'v1.5',
            'description' => 'Next release',
            'state' => 'open',
            'open_issues' => 10,
            'closed_issues' => 5,
        ]);

        $milestone = $client->getMilestone(3);

        self::assertInstanceOf(Milestone::class, $milestone);
        self::assertSame(3, $milestone->number);
        self::assertSame('v1.5', $milestone->title);
        self::assertSame('Next release', $milestone->description);
        self::assertSame(10, $milestone->openIssues);
        self::assertSame(5, $milestone->closedIssues);
    }

    #[Test]
    public function createPullRequestReturnsPullRequest(): void
    {
        $client = $this->createClient([
            'number' => 99,
            'html_url' => 'https://github.com/owner/repo/pull/99',
            'title' => 'feat: new feature',
            'state' => 'open',
        ]);

        $pr = $client->createPullRequest('feat: new feature', 'feature-branch', 'main', 'PR body');

        self::assertInstanceOf(PullRequest::class, $pr);
        self::assertSame(99, $pr->number);
        self::assertSame('https://github.com/owner/repo/pull/99', $pr->url);
        self::assertSame('feat: new feature', $pr->title);
        self::assertSame('open', $pr->state);
    }
}
