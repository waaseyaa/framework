# Manual cross-provider reviews

The implementation author and reviewer should be different providers:

| Code author | Reviewer | New PR conversation comment |
| --- | --- | --- |
| Codex / ChatGPT | Claude | `@claude review` |
| Claude | Codex | `@codex review` |

Reviews are requested by the maintainer when the change is ready. Agents must
not request each other's reviews automatically. Do not infer the authoring
provider from the GitHub author: both tools can commit as the same person.
For mixed-provider work, the maintainer chooses which review to request.

The Claude workflow accepts only a newly created PR conversation comment from
`jonesrussell` whose entire body is `@claude review`. Opening a PR, pushing a
commit, editing a comment, adding a label, or receiving a bot comment does not
start Claude. A changed revision needs a fresh manual request. Ordinary GitHub
CI checks are unaffected.

Claude posts a summary labeled **Claude review** under `github-actions[bot]`.
The job token can read repository contents and write PR feedback, but cannot
push repository code. The reviewer does not run project code or tests. Findings
include the head and base SHAs; a changed revision invalidates the review. Agent
feedback is advisory and never substitutes for required checks or human approval.

## Account setup

Claude uses `CLAUDE_CODE_OAUTH_TOKEN`, a GitHub Actions repository secret generated
with `claude setup-token` while signed into the maintainer's Claude Max plan.
Store it through `gh secret set CLAUDE_CODE_OAUTH_TOKEN --repo OWNER/REPO` using
the hidden interactive prompt. Never paste credentials into issues, PRs, source
files, chat, or command-line arguments. Use a separate repository secret for
each authorized repository, not an organization-wide subscription secret.

This workflow does not use `ANTHROPIC_API_KEY` and does not use the separately
billed managed Claude Code Review or ultrareview services. There is no API-key
fallback. Missing or expired subscription credentials fail the run. Max usage
limits still apply. Keep Claude extra usage disabled to avoid paid overflow.

GitHub-hosted execution consumes Actions minutes. Private repositories must stay
within the included GitHub allowance; keep the applicable Actions spending
budget set to stop usage at the included allowance to prevent overage charges.
Each job has a 20-minute timeout and a 16-turn limit. These are bounds, not a
guarantee of zero cost if paid overflow has been enabled elsewhere.

For Codex, connect this repository through the ChatGPT GitHub app. Keep personal
**Auto review**, repository automatic review options, and **Enable credits use**
off. Then request `@codex review` manually. Reviews use the ChatGPT Pro review
allowance; wait for the allowance to reset rather than buying extra credits.

The workflow must be merged to the default branch before GitHub processes its
`issue_comment` trigger. The presence of this file alone does not establish that
account connections and secrets have been configured or a live review has passed.

## References

- [Claude Code GitHub Actions](https://code.claude.com/docs/en/github-actions)
- [Codex GitHub reviews](https://learn.chatgpt.com/docs/third-party/github)
- [Codex pricing and limits](https://learn.chatgpt.com/docs/pricing)
