<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub;

class GitHubClient
{
    private string $baseUrl = 'https://api.github.com';

    public function __construct(
        private readonly string $token,
        private readonly string $owner,
        private readonly string $repo,
    ) {}

    public function getIssue(int $number): Issue
    {
        $data = $this->request('GET', "/repos/{$this->owner}/{$this->repo}/issues/{$number}");
        return new Issue(
            number: $data['number'],
            title: $data['title'],
            body: $data['body'] ?? '',
            state: $data['state'],
            milestone: $data['milestone']['title'] ?? null,
            labels: array_map(fn(array $l) => $l['name'], $data['labels'] ?? []),
            assignees: array_map(fn(array $a) => $a['login'], $data['assignees'] ?? []),
        );
    }

    /** @return Issue[] */
    public function listIssues(array $filters = []): array
    {
        $query = http_build_query($filters);
        $path = "/repos/{$this->owner}/{$this->repo}/issues";
        if ($query !== '') {
            $path .= '?' . $query;
        }
        $data = $this->request('GET', $path);
        return array_map(fn(array $item) => new Issue(
            number: $item['number'],
            title: $item['title'],
            body: $item['body'] ?? '',
            state: $item['state'],
            milestone: $item['milestone']['title'] ?? null,
            labels: array_map(fn(array $l) => $l['name'], $item['labels'] ?? []),
            assignees: array_map(fn(array $a) => $a['login'], $item['assignees'] ?? []),
        ), $data);
    }

    public function getMilestone(int $number): Milestone
    {
        $data = $this->request('GET', "/repos/{$this->owner}/{$this->repo}/milestones/{$number}");
        return new Milestone(
            number: $data['number'],
            title: $data['title'],
            description: $data['description'] ?? '',
            state: $data['state'],
            openIssues: $data['open_issues'],
            closedIssues: $data['closed_issues'],
        );
    }

    /** @return Milestone[] */
    public function listMilestones(string $state = 'open'): array
    {
        $data = $this->request('GET', "/repos/{$this->owner}/{$this->repo}/milestones?state={$state}");
        return array_map(fn(array $item) => new Milestone(
            number: $item['number'],
            title: $item['title'],
            description: $item['description'] ?? '',
            state: $item['state'],
            openIssues: $item['open_issues'],
            closedIssues: $item['closed_issues'],
        ), $data);
    }

    public function createComment(int $issueNumber, string $body): void
    {
        $this->request('POST', "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/comments", [
            'body' => $body,
        ]);
    }

    public function updateIssueState(int $issueNumber, string $state): void
    {
        $this->request('PATCH', "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}", [
            'state' => $state,
        ]);
    }

    public function createPullRequest(string $title, string $head, string $base, string $body): PullRequest
    {
        $data = $this->request('POST', "/repos/{$this->owner}/{$this->repo}/pulls", [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
        ]);
        return new PullRequest(
            number: $data['number'],
            url: $data['html_url'],
            title: $data['title'],
            state: $data['state'],
        );
    }

    /** @return array<string, mixed> */
    protected function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Waaseyaa-GitHub-Client',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null,
                'ignore_errors' => true,
            ],
        ]);
        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            throw GitHubException::apiError(0, 'Request failed: ' . $url);
        }
        $statusCode = 200;
        if (isset($http_response_header[0])) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $matches);
            $statusCode = (int) ($matches[1] ?? 200);
        }
        if ($statusCode === 404) {
            throw GitHubException::notFound('resource', $path);
        }
        if ($statusCode >= 400) {
            throw GitHubException::apiError($statusCode, $response);
        }
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }
}
