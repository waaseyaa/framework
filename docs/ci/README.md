# CI and Release Automation

## CI Workflow (`.github/workflows/ci.yml`)

Runs on every PR and push to `main`.

### Required Status Checks

These three checks must pass before a PR can be merged:

| Check | What it does | Typical runtime |
|---|---|---|
| `ci/lint` | PHP syntax, CS Fixer (dry-run), PHPStan static analysis | ~1 min |
| `ci/unit-tests` | PHPUnit unit + integration test suites | ~2 min |
| `ci/playwright-smoke` | Starts PHP + Nuxt servers, runs Playwright e2e tests | ~3 min |

### Additional Checks (informational)

| Check | What it does |
|---|---|
| `Frontend build` | Builds Admin SPA (Nuxt 3), runs Vitest unit tests |
| `Manifest conformance` | Validates `defaults/*.yaml` have `project_versioning` |
| `Ingestion defaults` | Validates ingestion schema metadata |
| `Security defaults` | Scans for secrets in `defaults/`, runs structural secrets tests |

### Artifacts

| Artifact | Location | Retention |
|---|---|---|
| `test-results` | `build/logs/junit-unit.xml`, `build/logs/junit-integration.xml`, `build/logs/clover.xml` | 30 days |
| `frontend-build` | `packages/admin/.output/` | 14 days |
| `playwright-smoke-results` | `packages/admin/test-results/`, `packages/admin/playwright-report/` | 30 days |
| `server-logs` | `/tmp/php-server.log`, `/tmp/nuxt-server.log` (on failure only) | 7 days |

### Running Tests Locally

```bash
# PHP lint + static analysis (matches ci/lint)
composer validate
find packages/*/src -name '*.php' -print0 | xargs -0 -n1 php -l
composer cs-check
composer phpstan

# Unit + integration tests (matches ci/unit-tests)
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration

# Frontend build + tests
cd packages/admin && npm ci && npm run build && npm test

# Playwright smoke tests (matches ci/playwright-smoke)
# Terminal 1: PHP backend
php -S 127.0.0.1:8080 -t public/
# Terminal 2: Nuxt dev server
cd packages/admin && npm run dev
# Terminal 3: Run tests
cd packages/admin && npx playwright test --grep @smoke
```

## Release Pipeline (`.github/workflows/release.yml`)

Triggers on every push to `main`:

```
main push → Deploy staging → Full Playwright sweep → [Approval gate] → Deploy production → Post-deploy smoke
```

### Stages

1. **Deploy to staging** — runs `scripts/deploy.sh staging`, uploads metadata artifact
2. **Full Playwright sweep** — runs entire Playwright suite (not just @smoke)
3. **Promote to production** — requires approval via GitHub environment gate, runs `scripts/deploy.sh production`
4. **Post-deploy smoke** — runs @smoke tests; on failure: attempts rollback via `scripts/rollback.sh`, creates incident issue

### Release Artifacts (90-day retention)

- `staging-deploy-metadata` / `production-deploy-metadata` — JSON with SHA, timestamp, actor
- `playwright-full-sweep` — full test results + HTML report
- `post-deploy-smoke` — smoke test results after production deploy

## Auto-merge (`.github/workflows/auto-merge.yml`)

Label a PR with `auto-merge-when-green` to enable automatic squash merge when:

1. All three required status checks pass (`ci/lint`, `ci/unit-tests`, `ci/playwright-smoke`)
2. PR is open with no merge conflicts
3. PR has a milestone assigned

The bot posts a summary comment after merging.

## Interpreting Playwright Artifacts

Playwright artifacts are uploaded to every CI run. To review:

1. Go to the Actions run → **Artifacts** section
2. Download `playwright-smoke-results` (or `playwright-full-sweep` for release runs)
3. Inside: `playwright-report/index.html` — open in a browser for the full HTML report
4. `test-results/` contains per-test screenshots and traces

To replay a trace locally:

```bash
npx playwright show-trace test-results/<test-name>/trace.zip
```

## Git Hooks

Install with:

```bash
bash scripts/install-git-hooks.sh
```

### pre-push

Runs automatically before `git push`:
- PHP syntax check on changed files
- `composer validate`
- PHPStan (if configured)

## Release Scripts

| Script | Usage | Purpose |
|---|---|---|
| `scripts/release.sh v1.0.1` | Create release | Changelog, annotated tag, push, GitHub release |
| `scripts/deploy.sh staging` | Deploy | Build + deploy to environment |
| `scripts/rollback.sh v1.0.0` | Rollback | Checkout tag + redeploy to production |
