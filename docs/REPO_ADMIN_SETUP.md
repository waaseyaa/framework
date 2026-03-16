# Repository Admin Setup

Instructions for configuring branch protection, environments, and secrets.

## 1. Branch Protection on `main`

### Required Status Checks

| Check | Description |
|---|---|
| `ci/lint` | PHP syntax, CS Fixer, PHPStan |
| `ci/unit-tests` | PHPUnit unit + integration tests |
| `ci/playwright-smoke` | Playwright smoke tests against running app |

### Protection Rules

- Require pull request before merging
- Require at least **1 approving review**
- Dismiss stale reviews on new pushes
- Require status checks to pass before merging
- Require branches to be up to date before merging
- Do not allow force pushes
- Do not allow branch deletion
- Include administrators

### Configure via CLI

```bash
gh api -X PUT repos/OWNER/REPO/branches/main/protection \
  --input - <<'JSON'
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["ci/lint", "ci/unit-tests", "ci/playwright-smoke"]
  },
  "enforce_admins": true,
  "required_pull_request_reviews": {
    "required_approving_review_count": 1,
    "dismiss_stale_reviews": true
  },
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false
}
JSON
```

### Verify

```bash
gh api repos/OWNER/REPO/branches/main/protection \
  --jq '{checks: .required_status_checks.contexts, reviews: .required_pull_request_reviews.required_approving_review_count, force_push: .allow_force_pushes.enabled}'
```

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

Create `.github/CODEOWNERS` if it doesn't exist:

```
# Default reviewer
* @jonesrussell
```

## 5. Local Development Setup

```bash
# Install git hooks (pre-push lint + static analysis)
bash scripts/install-git-hooks.sh

# Run quick local checks
composer validate
composer phpstan
./vendor/bin/phpunit --testsuite Unit
```

## 6. Timing Note

The `ci/lint`, `ci/unit-tests`, and `ci/playwright-smoke` checks must run at least once before adding them as required status checks. Merge the CI workflow PR first, then configure branch protection.
