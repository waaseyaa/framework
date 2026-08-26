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
| `ci/coverage` | Merges the shared PHPUnit shard evidence and enforces baseline and changed-line ratchets | shard critical path + ~20 sec |
| `ci/lint` | PHP syntax, CS Fixer (dry-run), and PHPStan | ~2.5 min |
| `ci/mutation-pilot` | Enforces the stable 84% Infection floors on bounded critical boundaries | ~1.25 min |
| `ci/package-isolation` | Clean-installs and runs declared split-package suites without root dev autoload | ~30 sec |
| `ci/playwright-smoke` | Starts PHP and Nuxt servers and exercises Chromium plus Firefox | ~2.5 min |
| `ci/random-order` | Runs the **complete** configured PHPUnit inventory across 2 package-safe, timing-balanced shards, replayed in isolated per-suite processes with one logged, replayable random seed. There is no subset selection — a prior changed-package selector was measured, found to rest on an undeclared dependency graph, and removed. See [docs/specs/ci-test-selection.md](../specs/ci-test-selection.md) | shard critical path |
| `ci/skeleton-create-project` | Installs and boots the exact consumer skeleton | ~45 sec |
| `ci/unit-tests` | Attests that every timing-balanced PHPUnit package shard passed | shard critical path |
| `ci/verify-gates` | Runs the fast repository invariant gates | ~40 sec |
| `composer-policy` | Enforces dependency and package-layer policy | ~10 sec |
| `packaged-form` | Verifies the distributable framework shape | ~15 sec |

### Additional Checks (informational)

| Check | What it does |
|---|---|
| `Changelog discipline check` | Requires a validated per-PR changelog fragment when production behavior changes |
| `Public-surface-map parity check` | Guards exported framework surface metadata |
| `Release pipeline fixtures` | Exercises publication-decision fixtures |
| `composer-deps-audit (warn-only)` | Reports dependency ownership debt without blocking |
| `admin/*` | Runs path-scoped admin contract, adapter, build, and integration checks |

### Artifacts

| Artifact | Location | Retention |
|---|---|---|
| `php-test-shard-*` | Per-shard JUnit and Clover evidence from one PHPUnit execution | 30 days |
| `phpunit-shard-plan` | Exact timing-balanced package assignment used by the run | 30 days |
| `vendor-archive` | `prepare-test-plan`'s single run-scoped Composer install: tarred `vendor/` plus its own and `vendor/composer/installed.php`'s SHA-256 sidecars — the one dependency authority both `ci-test-shards` and the random-order shard matrix verify (`bin/verify-random-order-vendor-archive`) and consume, falling back to a locked install on any integrity-gate failure (see [docs/specs/ci-test-selection.md](../specs/ci-test-selection.md) §7.3) | 30 days |
| `random-order-plan` | `prepare-random-order-plan`'s 2-shard, timing-balanced plan document with its run-derived random-order seed (JSON plan only — the dependency archive is the separate `vendor-archive` artifact above) | 30 days |
| `nightly-random-order-evidence` | `nightly.yml`'s complete-suite console log, uploaded only on failure | 30 days |
| `php-coverage` | Clover, text, and package-summary coverage reports | 30 days |
| `mutation-pilot` | Infection summary JSON and surviving-mutant text report | 30 days |
| `frontend-coverage` | V8/Istanbul JSON, JSON summary, text, and LCOV reports | 30 days |
| `frontend-build` | `packages/admin/.output/` | 14 days |
| `playwright-smoke-results` | `packages/admin/test-results/`, `packages/admin/playwright-report/` | 30 days |
| `server-logs` | `/tmp/php-server.log`, `/tmp/nuxt-server.log` (on failure only) | 7 days |

## Nightly Workflow (`.github/workflows/nightly.yml`)

Runs daily at 05:00 UTC plus manual `workflow_dispatch` (with an optional
`seed` input for replay). One job, `nightly/random-order-full`, runs the
**complete, unsharded** `composer test:random` against a fresh, independent
Composer install — the cross-shard ordering coverage that `ci/random-order`'s
2-way matrix necessarily drops (each shard only shuffles its own paths, never
the whole inventory together). The seed is date-derived when not supplied and
always logged, both as a workflow notice and inside the uploaded log itself,
so a failure stays replayable even after the run's own step log expires. It
holds no deployment, release, split, or other external-state authority. See
[docs/specs/ci-test-selection.md](../specs/ci-test-selection.md) §7.2.

### Running Tests Locally

```bash
# PHP lint + static analysis (matches ci/lint)
composer validate
find packages/*/src -name '*.php' -print0 | xargs -0 -n1 php -l
composer cs-check
composer phpstan

# Complete local test suite (CI assigns the same files to package-safe shards)
./vendor/bin/phpunit --no-coverage

# Inspect the deterministic CI assignment
php bin/build-phpunit-shards --timings=tools/phpunit-timings.json --shards=4 --pretty

# Frontend build + tests
cd packages/admin && npm ci && npm run build && npm test

# Unsharded local coverage (PCOV or Xdebug is required for PHP)
vendor/bin/phpunit --coverage-clover build/logs/clover.xml
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

PHPUnit's canonical `phpunit.xml.dist` sets a 1 GB ceiling for test discovery
and execution, including direct focused runs and CI shards. This is a
repository-owned test-tooling policy only; it does not change production web,
queue, or CLI application memory limits. Subprocess launchers that construct a
new PHP command must preserve the same ceiling with `PHP_BINARY -d
memory_limit=1G` when they can bypass or replace the canonical configuration.

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

## Release Readiness (`.github/workflows/release.yml`)

This manual-only workflow verifies an exact SHA reachable from `main`:

```
explicit SHA → build release candidate → full Playwright sweep
```

It does not use GitHub Environments and has no publication, deployment,
rollback, or incident authority. The Framework is a library; real application
promotion belongs in consumer and infrastructure repositories.

### Evidence (90-day retention)

- `release-candidate-evidence` — exact SHA, tag state, timestamp, actor, and run
- `release-readiness-playwright` — full browser results and HTML report
- `release-readiness-server-logs` — bounded failure diagnostics

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
| `release-cut.yml` | Create release | Governed changelog/version commit, exact-SHA CI gate, tag, and package fan-out |
| `scripts/build-release-candidate.sh` | Verify candidate | Fail-closed local dependency and Admin build with bounded metadata; no deployment or publication effects |
