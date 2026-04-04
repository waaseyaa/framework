# Per-site convergence audit (Waaseyaa ecosystem)

## Purpose

Provide a **repeatable, adversarial, invariant-driven** checklist for each Waaseyaa consumer app after ecosystem-level alignment (version provenance, skeleton normalization, GraphQL contract base). Use it so audits are **comparable** across sites and remediation is **categorized and sequenced**.

## Audience

- Human maintainers running periodic or pre-release reviews.
- Agents executing structured passes; pair with each app’s `CLAUDE.md` / `AGENTS.md` for local gotchas.

## Related specs

- [version-provenance.md](./version-provenance.md) — golden SHA, `bin/waaseyaa-version`, strict vs report-only.
- [workflow.md](./workflow.md) — GitHub-first work, issues, milestones.
- [extension-compatibility-matrix.md](./extension-compatibility-matrix.md) — package / surface compatibility.

## Audit artifact

Each completed pass MUST produce **one** of:

- **`docs/audits/YYYY-MM-DD-<site-slug>-convergence.md`** (preferred for history), or
- **`docs/audits/SITE_CONVERGENCE.md`** (rolling; overwrite or append a dated section).

The file MUST include **Section 8** (drift table + remediation) from this document.

## Mechanical preflight

From the app repository root (after `composer install`):

```bash
./bin/waaseyaa-audit-site
```

This does **not** replace Sections 3–5 (entity/provider/API review); it only validates a minimal deterministic subset. Failing preflight is a **required fix** before claiming audit complete.

When `bin/golden-public-index.php` is present next to `waaseyaa-audit-site`, the script requires `public/index.php` to match it **byte-for-byte** (canonical Waaseyaa HTTP entry). See [`http-entry-point.md`](./http-entry-point.md). Legacy apps may set `WAASEYAA_AUDIT_SKIP_PUBLIC_INDEX=1` only when the deviation is documented in Section 8.

The script ends with `./bin/waaseyaa-version --report-only` so a committed `.waaseyaa-golden-sha` that lags a local path checkout does not fail preflight. Enforce golden alignment with `./bin/waaseyaa-version --strict` (or default without `--report-only`) in CI or before release.

`composer validate --no-check-publish` fails (exit `2`) when `composer.lock` is out of date relative to `composer.json`; run `composer update --lock` (or a targeted `composer update`) and commit the lockfile.

### Deploy artifact (rsync)

CI/deploy workflows that assemble a release with `rsync` must **not** use an unanchored `docs/` exclude (`--exclude='docs/'`). In rsync, that pattern matches **any** directory named `docs` anywhere in the tree (including `templates/docs/` for Twig). Exclude only the repository-root documentation tree:

```text
--exclude='/docs/'
```

Run `./bin/verify-deploy-rsync` (from the skeleton) in CI to enforce this. It is also invoked from `./bin/waaseyaa-audit-site`.

Apps that serve on-disk guides from `docs/guides/` while omitting other `docs/` content (e.g. audits) should keep `/docs/` anchored and **add a second** `rsync` of `docs/guides/` into the artifact, as [waaseyaa.org](https://github.com/waaseyaa/waaseyaa.org) does.

Optional deeper check (nightly / `workflow_dispatch` only): after reproducing the deploy `rsync` into a temp directory, run `ARTIFACT_DIR=... ./bin/deploy-artifact-smoke` from the skeleton. Paths default to `/`; add `scripts/deploy-smoke-routes.txt` for more URLs.

---

## 1. Provenance and version alignment

### Commands

```bash
./bin/waaseyaa-version
./bin/waaseyaa-version --json
# Strict CI / local gate (fails on drift when golden is set):
./bin/waaseyaa-version --strict
# Transitional: always exit 0
./bin/waaseyaa-version --report-only
```

Set `WAASEYAA_GOLDEN_SHA` or add `.waaseyaa-golden-sha` (see [version-provenance.md](./version-provenance.md)) before expecting `--strict` or default (non-`--report-only`) runs to enforce SHA alignment.

### Pass / fail

| Check | Pass |
|-------|------|
| Command runs | `./bin/waaseyaa-version` exits `0` with golden unset, or with golden set and no drift |
| Golden alignment | When golden is configured, resolved monorepo SHA(s) match policy |
| Single constraint family | `composer.json` does not mix incompatible `waaseyaa/*` constraint styles without justification (e.g. one coherent `^0.1` set, or one coherent `dev-main` + path story) |
| Lock coherence | `composer.lock` lists `waaseyaa/*` from a **single** path HEAD or a **single** split publish; no accidental mix of multiple path roots |
| `minimum-stability` / `prefer-stable` | Matches intentional ecosystem tier: typical pattern `minimum-stability: dev` + `prefer-stable: true` (see [skeleton `composer.json`](../skeleton/composer.json)) |
| Hermetic lock | `composer.lock` committed; `composer install` is reproducible |

---

## 2. Skeleton conformance

**Reference:** [`skeleton/composer.json`](../skeleton/composer.json), [`skeleton/bin/`](../skeleton/bin/), [`skeleton/phpunit.xml.dist`](../skeleton/phpunit.xml.dist).

Compare the app’s layout and Composer metadata to the skeleton. Deviations are allowed when **documented** in Section 8.

### Pass / fail

| Check | Pass |
|-------|------|
| `autoload` / `autoload-dev` | PSR-4 roots present and match `src/` and `tests/` (or documented alternate) |
| `require-dev` | PHPUnit **10.5+** (skeleton allows `^10.5 \|\| ^11.0`). **PHPStan** is recommended for mature apps; minimal skeleton does not require it — if the app runs static analysis in CI, align `phpstan/phpstan` (or org standard) with lockfile |
| `scripts` | Includes skeleton-equivalent `post-create-project-cmd` where the app is created from skeleton (chmod bins + post-create setup); existing apps may chmod in docs/CI |
| `extra.waaseyaa.providers` | **If** the app registers a custom `App\*` (or branded-namespace) service provider, it appears under `extra.waaseyaa.providers` |
| `config.optimize-autoloader` | `true` |
| `bin/waaseyaa-version` | Present; executable (`chmod +x` or equivalent in CI) |
| `bin/waaseyaa` | Present for standard apps |
| `public/index.php` | Byte-identical to [`skeleton/public/index.php`](../skeleton/public/index.php) (compare to [`skeleton/bin/golden-public-index.php`](../skeleton/bin/golden-public-index.php)); enforced by `waaseyaa-audit-site` when `bin/golden-public-index.php` exists |
| `bin/golden-public-index.php` | Shipped next to `waaseyaa-audit-site` when using the mechanical preflight from the skeleton |

---

## 3. Entity and provider audit

Manual / agent review (no single automated gate yet).

### Pass / fail

| Check | Pass |
|-------|------|
| One `EntityType` per ID | No duplicate registrations for the same `entityTypeId` across providers |
| `fieldDefinitions` | Present for every entity type exposed via GraphQL (empty definitions → no GraphQL fields) |
| Provider registration | No duplicate `extra.waaseyaa.providers` entries; providers follow `ServiceProvider` API (`register()` / `boot()` boundaries per [skeleton CLAUDE.md](../skeleton/CLAUDE.md)) |

---

## 4. API surface audit

### GraphQL apps

| Check | Pass |
|-------|------|
| Schema contract tests | `SchemaContractTest` (or equivalent) extends `Waaseyaa\GraphQL\Testing\AbstractGraphQlSchemaContractTestCase` from `waaseyaa/graphql` |
| Tests pass | `./vendor/bin/phpunit` includes contract tests green |
| Legacy JSON:API | No JSON:API controllers **unless** explicitly required and listed in Section 8 |

### Admin surface

| Check | Pass |
|-------|------|
| Routes | Follow current admin-surface / host patterns from framework docs |
| Hybrids | No stale Inertia/Blade mixes unless intentional and documented |

---

## 5. Framework boundary audit

| Check | Pass |
|-------|------|
| No vendored forks | App does not fork framework internals; patches go upstream or via supported extension points |
| Layer imports | App code respects framework layer rules (no imports from disallowed higher layers) |
| Temporary glue | “Temporary” workarounds listed in Section 8 with **uplift candidate** or expiry |

---

## 6. Test harness audit

### Commands

```bash
composer validate --no-check-publish
composer install --no-interaction --dry-run
./vendor/bin/phpunit
# If configured:
./vendor/bin/phpstan analyse
```

### Pass / fail

| Check | Pass |
|-------|------|
| PHPUnit | **10.5+**; `phpunit.xml.dist` committed and CI uses it |
| Integration tests | Present where the app has non-trivial HTTP/storage paths |
| GraphQL contracts | Present when GraphQL is used (Section 4) |
| Baselines / skips | PHPStan baseline and `@skip` only with justification in Section 8 |

---

## 7. Operational invariants

### Pass / fail

| Check | Pass |
|-------|------|
| `.env.example` | Aligned with skeleton / app needs; no secrets |
| Composer config | No ad-hoc `autoload` hacks that defeat `optimize-autoloader` without justification |
| Migrations / schema | No untracked migrations; SQL schema drift documented or absent |
| CI | Runs at minimum: `composer validate`, `composer install` (or dry-run where full install is separate job), `phpunit`, static analysis if required by app, **`bin/waaseyaa-version --strict`** (or default `bin/waaseyaa-version` without `--report-only`) when golden SHA is set; use `--report-only` only as a transitional gate |

---

## 8. Drift and remediation plan

Required closing section of the audit artifact.

### Table

For each deviation, record:

| ID | Area (1–7) | Description | Category | Complexity (S/M/L) | Sequencing note |
|----|------------|-------------|----------|-------------------|-----------------|

**Categories:**

- **Required fix** — blocks convergence or CI correctness.
- **Optional cleanup** — hygiene, no user-facing risk short term.
- **Framework uplift** — belongs in `waaseyaa/framework` or a split package.

### Summary

- Ordered remediation list (dependencies noted).
- Open issues / PR links.

---

## Recommended audit order (ecosystem roster)

| # | Site | Notes |
|---|------|--------|
| 1 | waaseyaa.org | Path consumer, relatively small surface |
| 2 | signalgarden | Same shape as waaseyaa.org; catches path drift |
| 3 | scratch-waaseyaa | Baseline skeleton clone |
| 4 | northops-waaseyaa | Skeleton + app providers |
| 5 | irc.waaseyaa.org | Autoloader / tooling recently touched |
| 6 | dashboard-waaseyaa | Full skeleton consumer (clone if not in workspace) |
| 7 | northcloud-search | Packagist-resolved consumer (clone if not in workspace) |
| 8 | oneredpaperclip-waaseyaa | Partial stack, custom providers |
| 9 | goformx-web | Metapackage / non-PHP — use **Reduced invariant set** below |
| 10 | claudriel | Largest surface; run after smaller consumers |
| 11 | minoo | Flagship; final invariant sweep |

Workspace roots that commonly host these clones (adjust to your machine): e.g. `~/dev/waaseyaa.org`, `~/dev/signalgarden`, `~/dev/scratch-waaseyaa`, `~/dev/northops-waaseyaa`, `~/dev/irc.waaseyaa.org`, `~/dev/claudriel`, `~/dev/minoo`.

---

## Reduced invariant set (metapackages and non-PHP repos)

For repositories that **do not** ship a Waaseyaa `composer.json` app root (e.g. documentation-only, metapackage, or polyrepo orchestration):

1. **Version story** — How consumer apps pin `waaseyaa/*` or paths; link to repos that *do* run Section 1.
2. **Documentation** — README / specs reference [version-provenance.md](./version-provenance.md) and this audit for downstream apps.
3. **CI** — Limited to what applies (e.g. markdown lint, submodule pointers); do **not** force `bin/waaseyaa-version` unless a PHP app root exists.

---

## Orchestration index

When adding this spec to `CLAUDE.md` orchestration tables, use specialist skill **`updating-codified-context`** for edits to `docs/specs/**`.
