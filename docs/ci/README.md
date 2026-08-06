# CI and Release Automation

## CI Workflow (`.github/workflows/ci.yml`)

Runs on every PR and push to `main`.

### Required Status Checks

The repository ruleset is authoritative. Its required checks are:

| Check | What it does | Typical runtime |
|---|---|---|
| `Frontend build` | Builds Admin SPA, produces V8 coverage, and enforces changed-statement coverage | ~1.5 min |
| `Ingestion defaults` | Validates ingestion schema metadata | ~10 sec |
| `Manifest conformance` | Validates `defaults/*.yaml` project versioning | ~15 sec |
| `Release publish shape` | Verifies release workflow and publication invariants | ~10 sec |
| `Security defaults` | Scans default manifests and structural secret guards | ~20 sec |
| `check-dead-code` | Rejects new PHPStan dead-code findings | ~20 sec |
| `ci/core-only-boot` | Proves the minimal framework boot boundary | ~20 sec |
| `ci/coverage` | Produces PCOV reports and enforces baseline and changed-line ratchets | ~4.5 min |
| `ci/lint` | PHP syntax, CS Fixer (dry-run), and PHPStan | ~2.5 min |
| `ci/mutation-pilot` | Enforces the stable 84% Infection floors on bounded critical boundaries | ~1.25 min |
| `ci/package-isolation` | Clean-installs and runs declared split-package suites without root dev autoload | ~30 sec |
| `ci/playwright-smoke` | Starts PHP and Nuxt servers and exercises Chromium plus Firefox | ~2.5 min |
| `ci/random-order` | Replays each PHP suite in an isolated process with one logged random seed | ~3.5 min |
| `ci/skeleton-create-project` | Installs and boots the exact consumer skeleton | ~45 sec |
| `ci/unit-tests` | Runs PHPUnit unit, architecture, and integration suites | ~4.75 min |
| `ci/verify-gates` | Runs the fast repository invariant gates | ~40 sec |
| `composer-policy` | Enforces dependency and package-layer policy | ~10 sec |
| `packaged-form` | Verifies the distributable framework shape | ~15 sec |

### Additional Checks (informational)

| Check | What it does |
|---|---|
| `Changelog discipline check` | Requires an Unreleased changelog entry when production behavior changes |
| `Public-surface-map parity check` | Guards exported framework surface metadata |
| `Release pipeline fixtures` | Exercises publication-decision fixtures |
| `composer-deps-audit (warn-only)` | Reports dependency ownership debt without blocking |
| `admin/*` | Runs path-scoped admin contract, adapter, build, and integration checks |

### Artifacts

| Artifact | Location | Retention |
|---|---|---|
| `test-results` | `build/logs/junit-unit.xml`, `build/logs/junit-architecture.xml`, `build/logs/junit-integration.xml` | 30 days |
| `php-coverage` | Clover, text, and package-summary coverage reports | 30 days |
| `mutation-pilot` | Infection summary JSON and surviving-mutant text report | 30 days |
| `frontend-coverage` | V8/Istanbul JSON, JSON summary, text, and LCOV reports | 30 days |
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

# Coverage (PCOV or Xdebug is required for PHP)
php -d memory_limit=1G vendor/bin/phpunit --coverage-clover build/logs/clover.xml
cd packages/admin && npm run test:coverage

# Bounded mutation pilot (PCOV or Xdebug is required)
php bin/test-mutation-pilot

# Playwright smoke tests (matches ci/playwright-smoke)
# Terminal 1: PHP backend
php -S 127.0.0.1:8080 -t public/
# Terminal 2: Nuxt dev server
cd packages/admin && npm run dev
# Terminal 3: Run tests
cd packages/admin && npx playwright test --grep @smoke
```

## Static Analysis Policy (PHPStan 2)

- Canonical command: `composer phpstan`
- Config source of truth: [`phpstan.neon`](../../phpstan.neon)
- Rule level target: `5` (balanced static analysis bar; ratchet upward as debt drops)
- Strict rules: enabled via `phpstan/phpstan-strict-rules`
- Result cache path: `tmp/phpstan` (restored/saved in CI)

### Baseline governance

- Current baseline is transitional while `level: 5` is active across the monorepo.
- Do not regenerate baseline in feature PRs unless explicitly approved.
- Any baseline diff must be reviewed as a first-class code-review item and justified in the PR description.
- Preferred end-state remains a minimal baseline (or no baseline) as packages mature.

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

1. Every status check required by the repository ruleset passes, including the
   deterministic random-order replay, split-package isolation, and coverage ratchets
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
composer hooks:install
composer hooks:doctor
```

### pre-push

Runs sequential, fast architecture checks before `git push`: Composer policy,
Symfony import boundaries, package layers, and the advisory local spec-drift
check. CI remains authoritative. Run `composer verify` for the complete local
gate before publication.

## Release Scripts

| Script | Usage | Purpose |
|---|---|---|
| `scripts/release.sh v1.0.1` | Create release | Changelog, annotated tag, push, GitHub release |
| `scripts/deploy.sh staging` | Deploy | Build + deploy to environment |
| `scripts/rollback.sh v1.0.0` | Rollback | Checkout tag + redeploy to production |
