# Repository Admin Setup

Instructions for configuring branch protection, environments, and secrets.

## 1. Branch Protection on `main`

### Required Status Checks

| Check | Description |
|---|---|
| `ci/lint` | PHP syntax, CS Fixer, PHPStan |
| `ci/unit-tests` | PHPUnit unit + integration tests |
| `ci/playwright-smoke` | Playwright smoke tests against running app |

### Solo-maintainer protection rules

Waaseyaa Framework is intentionally maintained by `@jonesrussell`. Do not
configure a required human approval until a distinct qualified maintainer
exists: a one-approval rule would make the repository inoperable and a
self-approval would not be independent. The accepted bus-factor-one risk is
tracked in Framework issue #2387 and reviewed quarterly.

- Require a pull request before merging.
- Require **0 human approvals** while only one eligible human exists.
- Require all canonical status checks and require the branch to be up to date.
- Require review threads to be resolved.
- Do not allow force pushes or branch deletion.
- Restrict administrator bypass to **pull-request mode**. An emergency override
  must retain a PR, reason, exact checks, and GitHub audit history; never restore
  an always-on direct bypass.
- Retain an independent agent-review comment for high-risk changes where useful.
  Agent review is evidence, not a GitHub human approval or maintainer authority.

The active control is repository ruleset `main-protection` (currently id
`15181711`), not classic branch protection. Read the full ruleset, preserve its
complete required-check roster, and update it through the GitHub ruleset API or
repository settings. Never replace it with the abbreviated historical
three-check example.

### Verify

```bash
gh api repos/OWNER/REPO/rulesets/15181711 \
  --jq '{enforcement, rules, bypass_actors, current_user_can_bypass}'
```

Verify that the pull-request rule reports zero approvals and resolved threads,
required status checks report `strict_required_status_checks_policy:true`, and
every bypass actor reports `bypass_mode:"pull_request"`.

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

## 6. Timing Note

The `ci/lint`, `ci/unit-tests`, and `ci/playwright-smoke` checks must run at least once before adding them as required status checks. Merge the CI workflow PR first, then configure branch protection.
