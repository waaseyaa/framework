# waaseyaa/github

**Layer 3 — Services**

GitHub API client for issues, milestones, and pull requests.

`GitHubClient` wraps the REST v3 endpoints used by repo automation tooling. Reads: `getIssue`, `listIssues`, `getMilestone`, `listMilestones`. Writes: `createComment` (comment on an issue or PR), `updateIssueState` (close/reopen), `createPullRequest` (open a PR). Milestones are **read-only** — there is no create/edit-milestone method. `Issue`, `Milestone`, and `PullRequest` are typed value objects rather than raw arrays. Authentication is via personal access token or GitHub App installation token passed at construction.

Key classes: `GitHubClient`, `Issue`, `Milestone`, `PullRequest`, `GitHubException`.
