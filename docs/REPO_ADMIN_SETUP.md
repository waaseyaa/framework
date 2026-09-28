# Repository Admin Setup

Instructions for configuring branch protection, environments, and secrets.

## 1. Branch Protection on `main`

### Solo-maintainer protection rules

Waaseyaa Framework is intentionally maintained by `@jonesrussell`. Do not
configure a required human approval until a distinct qualified maintainer
exists: a one-approval rule would make the repository inoperable and a
self-approval would not be independent. The accepted bus-factor-one risk is
tracked in Framework issue #2387 and reviewed quarterly.

- Pull requests are optional during the solo-maintainer sprint.
- Do not require status checks for ordinary updates to `main`; hosted CI is
  post-landing feedback and a red head must be recorded with a repair owner.
- Do not allow force pushes or branch deletion.
- Retain an independent agent-review comment for high-risk changes where useful.
  Agent review is evidence, not a GitHub human approval or maintainer authority.
- Preserve the release-cut exact-SHA gates. No tag or package publication may
  proceed from a red candidate.

The active control is repository ruleset `main-protection` (currently id
`15181711`), not classic branch protection. During the sprint it retains only
`non_fast_forward` and `deletion` rules for `refs/heads/main`. The release tag
ruleset and release-cut workflow carry the publication boundary.

### Verify

```bash
gh api repos/OWNER/REPO/rulesets/15181711 \
  --jq '{enforcement, rules, bypass_actors, current_user_can_bypass}'
```

Verify that the branch rules are exactly `non_fast_forward` and `deletion`, and
that no `pull_request` or `required_status_checks` rule is present.

## 2. GitHub Environments

Create two environments with approval gates:

### Staging

```bash
gh api -X PUT repos/OWNER/REPO/environments/staging
```

No approval required — deploys automatically after CI passes.

### Production

```bash
gh api -X PUT repos/OWNER/REPO/environments/production \
  --input - <<'JSON'
{
  "reviewers": [
    {"type": "User", "id": YOUR_GITHUB_USER_ID}
  ],
  "deployment_branch_policy": {
    "protected_branches": true,
    "custom_branch_policies": false
  }
}
JSON
```

Get your user ID: `gh api user --jq .id`

## 3. Required Secrets

| Secret | Scope | Purpose |
|---|---|---|
| `SPLIT_TOKEN` | Repository | Personal access token for monorepo split (push to sub-repos) |

GitHub Actions `GITHUB_TOKEN` is used for all other operations (PR comments, issue creation, merges).

## 4. CODEOWNERS

The tracked `.github/CODEOWNERS` names the sole real human owner and repeats the
critical orchestration paths for review routing. While the repository has only
one eligible human, leave `require_code_owner_review:false`; turning it on would
not create independence and can only create a self-review deadlock.

```
* @jonesrussell
```

## 5. Local Development Setup

```bash
# Install and verify the tracked project hooks
composer hooks:install
composer hooks:doctor

# Run quick local checks
composer validate
composer phpstan
./vendor/bin/phpunit --testsuite Unit --no-coverage
```

Hook installation is explicit because linked worktrees share the repository's
Git hook directory. The installer is idempotent, upgrades generated Lefthook
shims, and refuses to overwrite an unknown hook.

## 6. Restoring a pre-merge gate

If the maintainer later restores required checks, first run the selected
contexts successfully on a current head, then update the tracked operating
policy and live ruleset together. Do not restore a stale historical roster by
copying this document.
