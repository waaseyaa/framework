# DEV-MANUAL-CROSS-REVIEW-20260830

- Parent: `bd900d0183bdd4c36058a684c50f651177ff071e`
- Forge mirror: `waaseyaa/framework#2714`
- Scope: manual pull request review configuration and operating instructions.
- Authority: implement and prepare the requested setup; no release, production
  deployment, automatic review, or paid API use.

## Contract and decisions

Codex-authored changes are reviewed by Claude; Claude-authored changes are
reviewed by Codex. The maintainer explicitly requests each review. The provider
cannot reliably be inferred from the GitHub commit author, so there is no
automatic routing, label trigger, push trigger, PR-open trigger, or agent loop.

`.github/workflows/manual-claude-review.yml` accepts one exact review command
from the maintainer on an open PR. It uses a Claude Max subscription secret and
the job's short-lived GitHub token, with repository contents read-only. It does
not use the managed paid review service, an API key, a PAT, or a code-writing
GitHub App token. Review is static; no project scripts or tests execute.

The explicit prompt keeps the trusted default-branch checkout in place instead
of executing a PR-head checkout. Project hooks and extra MCP configurations are
disabled for the review session. The prompt and final guard check the reviewed
head/base pair. Findings remain advisory and do not satisfy human approval.

`.github/MANUAL_REVIEWS.md` is the operating contract for invocation, subscription
setup, quotas, GitHub Actions overage controls, and Codex manual preferences.
It does not change the framework runtime or the governed merge/release path.

## Verification and activation

Validate the workflow with actionlint and exercise its actual GitHub expression
against maintainer, bot, outsider, non-PR, and non-command comment fixtures.
Run the framework changelog-fragment validator and diff whitespace checks.

Activation requires the default-branch workflow, the repository subscription
secret, and Codex GitHub access. A setup PR alone is not evidence of activation.
No live review is claimed before a deliberate test against a selected PR.
Merge through ordinary framework governance only after required checks pass.
