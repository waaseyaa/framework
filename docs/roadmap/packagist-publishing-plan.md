# Waaseyaa Packagist Publishing Plan

> **Status:** DRAFT — Awaiting Russell's approval before any implementation sprint begins.
> **Produced:** 2026-03-14
> **Author:** Claude Sonnet 4.6 (planning run)
> **Repository:** github.com/waaseyaa/framework

---

## Executive Summary

- All 37 library subpackages are **individually publication-ready** — complete `name`, `description`, `license`, and `autoload` metadata confirmed. Zero per-package fixes required.
- The **recommended strategy** is Monorepo + per-package Packagist registration via `splitsh-lite` and GitHub Actions. This keeps the monorepo as the authoritative source and publishes mirror read-only repos per package on tag.
- The **only root-level blockers** are `type: project` (should stay as-is for development), path-repository declarations (irrelevant per-package), and `@dev` inter-package constraints (must become `^1.1.0` in published package `composer.json` files).
- A **3-package POC** (`waaseyaa/foundation`, `waaseyaa/entity`, `waaseyaa/api`) can validate the full workflow in one 5–7 day sprint with ~30 hours of engineering effort.
- **Full rollout** of all 40 packages (37 libraries + 3 metapackages) is achievable in 4–5 weeks, 1–2 engineers, with no repo splits, no history rewrites, and no breaking API changes.
- Russell must **approve three decision points** before implementation proceeds: strategy selection, mirror-repo naming, and Packagist account registration.

---

## Phase 0 — Decision Matrix

### Strategy Comparison

| # | Strategy | Effort | Time to First Install | Long-term Maintenance | CI Complexity | Consumer Ergonomics | Risk |
|---|---|---|---|---|---|---|---|
| A | **Split Repos** (38 separate GitHub repos) | 🔴 High | 🔴 High (weeks) | 🔴 High (38 repos) | 🔴 High | 🟢 Excellent | 🟡 Moderate |
| B | **Monorepo + splitsh-lite** (mirror repos per package, Packagist per package) | 🟡 Medium | 🟡 Medium (5–7 days POC) | 🟢 Low (single source) | 🟡 Medium | 🟢 Excellent | 🟡 Moderate |
| C | **Monorepo + Satis** (private/self-hosted Packagist mirror) | 🟢 Low | 🟢 Low (1–2 days) | 🟡 Medium (Satis server) | 🟢 Low | 🟡 Good (private only) | 🟢 Low |
| D | **Single root package** (publish monorepo root as `waaseyaa/framework`) | 🔴 Impossible | — | — | — | 🔴 Poor | 🔴 High |

### Recommended Strategy: **B — Monorepo + splitsh-lite + Per-package Packagist**

**Rationale:**
- Strategy A (split repos) requires creating and maintaining 38+ GitHub repositories, cross-repo PRs, and coordinated tagging. This is the standard for extremely large open-source ecosystems (Laravel components, Symfony) but carries very high ongoing cost for a small team.
- Strategy C (Satis) is only suitable for private/internal consumption. It does not enable public `composer require` from Packagist.
- Strategy D is architecturally impossible (path repos, `@dev` constraints, no root autoload).
- **Strategy B** is the 2026 standard for open-source PHP monorepos targeting Packagist. The monorepo remains the sole source of truth. On every release tag, a GitHub Actions workflow splits each `packages/{name}` directory into a read-only mirror repo (`github.com/waaseyaa/{name}`). Packagist tracks each mirror. Consumers run `composer require waaseyaa/entity ^1.1` with zero knowledge of the monorepo.

Tools: `symplify/monorepo-builder` (orchestration), `splitsh-lite` / `symplify/github-action-monorepo-split` (git splitting), Packagist webhooks (auto-sync on tag push).

> **Decision Point #1 (Russell must approve):** Confirm Strategy B before any implementation begins.

---

## Phase 1 — Inventory and Metadata Audit

### Audit Results (verified by automated research agent)

**Summary:** 41 packages total — 37 libraries, 3 metapackages, 1 npm SPA (admin).
**All 37 libraries: READY** — complete metadata, PSR-4 autoload, GPL-2.0-or-later.
**0 packages require per-package composer.json fixes.**

| Layer | Packages | Status |
|---|---|---|
| 0 — Foundation | foundation, cache, plugin, typed-data, database-legacy, testing, i18n, queue, state, validation, mail | ✅ All READY |
| 1 — Core Data | entity, entity-storage, access, user, config, field | ✅ All READY |
| 2 — Content Types | node, taxonomy, media, path, menu, note, relationship | ✅ All READY |
| 3 — Services | workflows, search | ✅ All READY |
| 4 — API | api, routing | ✅ All READY |
| 5 — AI | ai-schema, ai-agent, ai-pipeline, ai-vector | ✅ All READY |
| 6 — Interfaces | cli, mcp, ssr, telescope, graphql | ✅ All READY |
| Metapackages | core, cms, full | ✅ All READY (type: metapackage) |
| npm | admin | N/A (Nuxt SPA, no composer.json) |

### Root-level blockers (not per-package)

| Blocker | Impact | Fix Required |
|---|---|---|
| `@dev` inter-package constraints | Published packages must use `^1.1.0` instead | Yes — update each package's `composer.json` `require` section |
| Path repositories in root | Irrelevant on Packagist (each package is standalone) | No — root stays as-is for dev |
| `type: project` in root | Root is NOT published; each package is published independently | No change needed |

### Exact shell commands to reproduce inventory

```bash
# Generate inventory CSV
echo "package,path,type,autoload,bin,tests,deps,status" > packagist_inventory.csv
for dir in packages/*/; do
  name=$(jq -r '.name // "MISSING"' "$dir/composer.json" 2>/dev/null)
  type=$(jq -r '.type // "library"' "$dir/composer.json" 2>/dev/null)
  autoload=$(jq -e '.autoload' "$dir/composer.json" > /dev/null 2>&1 && echo "yes" || echo "NO")
  bin=$(jq -e '.bin' "$dir/composer.json" > /dev/null 2>&1 && echo "yes" || echo "no")
  tests=$([ -d "${dir}tests" ] && echo "yes" || echo "no")
  deps=$(jq '.require | length' "$dir/composer.json" 2>/dev/null || echo "0")
  missing=""
  [ "$name" = "MISSING" ] && missing="${missing}name,"
  [ "$autoload" = "NO" ] && [ "$type" != "metapackage" ] && missing="${missing}autoload,"
  status=$([ -z "$missing" ] && echo "READY" || echo "FIX:${missing%,}")
  echo "$name,$dir,$type,$autoload,$bin,$tests,$deps,$status"
done >> packagist_inventory.csv
```

---

## Phase 2 — Strategy Selection and Rationale

**Chosen: Strategy B — Monorepo + splitsh-lite + Per-package Packagist**

### Why it fits Waaseyaa in 2026

1. **Single source of truth**: All code, issues, PRs, and releases live in one monorepo. No cross-repo PR coordination.
2. **Existing layer discipline**: The 7-layer architecture is already properly scoped per package. Splitting is a mechanical operation, not an architectural redesign.
3. **Metapackages ready**: `waaseyaa/core`, `waaseyaa/cms`, `waaseyaa/full` already exist and declare the right dependency sets. Consumers install a single metapackage.
4. **CI-native**: splitsh-lite integrates as a GitHub Actions step. No new servers or infrastructure required.
5. **Version coherence**: All packages release at the same version tag (e.g., `v1.1.0`). Consumers don't manage N different version constraints.

### Split-repo migration pattern (not recommended, included for completeness)

If the team later decides to split, the path is:
```bash
git subtree split --prefix=packages/entity -b entity-split
# OR with splitsh-lite (faster, cached):
splitsh-lite --prefix=packages/entity
```
Each resulting branch would be force-pushed to a dedicated GitHub repo. This is reversible but high-effort. Treat as v2.0 option.

---

## Phase 3 — Migration Playbook

### Step 1: Per-package composer.json normalization

Each package's `composer.json` must replace `@dev` inter-package constraints with semver. This is the only code change required.

**Before (current):**
```json
{
  "require": {
    "waaseyaa/entity": "@dev",
    "waaseyaa/access": "@dev"
  }
}
```

**After (published):**
```json
{
  "require": {
    "waaseyaa/entity": "^1.1",
    "waaseyaa/access": "^1.1"
  }
}
```

**Automation script:**
```bash
# Replace @dev with ^1.1 in all package composer.json files
# Run once per release cycle, update the version number
RELEASE="1.1"
for dir in packages/*/; do
  if [ -f "$dir/composer.json" ]; then
    # Update @dev constraints for waaseyaa/* packages only
    sed -i 's/"waaseyaa\/\([^"]*\)": "@dev"/"waaseyaa\/\1": "^'"$RELEASE"'"/g' "$dir/composer.json"
  fi
done
# Verify no @dev constraints remain in packages/
grep -r '"@dev"' packages/ --include="composer.json"
```

> **Note:** The root `composer.json` keeps `@dev` + path repos for local development. Only `packages/*/composer.json` gets the semver constraints.

### Step 2: Create GitHub mirror repositories

Create 40 empty repositories under the `waaseyaa` GitHub org (one per package + 3 metapackages). Naming: `waaseyaa/{package-name}` matching the `name` field in each `composer.json`.

```bash
# Using gh CLI — run for each package
for pkg in access ai-agent ai-pipeline ai-schema ai-vector api cache cli \
            cms config core database-legacy entity entity-storage field \
            foundation full graphql i18n mail media menu mcp node note \
            path plugin queue relationship routing search ssr state \
            taxonomy telescope testing typed-data user validation workflows; do
  gh repo create waaseyaa/$pkg --public --description "Waaseyaa framework: $pkg package" || true
done
```

> **Decision Point #2 (Russell must approve):** Confirm repo naming convention and org ownership before creating mirror repos.

### Step 3: GitHub Actions split workflow

Create `.github/workflows/split-packages.yml`:

```yaml
name: Split and publish packages

on:
  push:
    tags:
      - 'v*'

jobs:
  split:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        package:
          - access
          - ai-agent
          - ai-pipeline
          - ai-schema
          - ai-vector
          - api
          - cache
          - cli
          - cms
          - config
          - core
          - database-legacy
          - entity
          - entity-storage
          - field
          - foundation
          - full
          - graphql
          - i18n
          - mail
          - media
          - menu
          - mcp
          - node
          - note
          - path
          - plugin
          - queue
          - relationship
          - routing
          - search
          - ssr
          - state
          - taxonomy
          - telescope
          - testing
          - typed-data
          - user
          - validation
          - workflows

    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0  # Full history required for split

      - name: Update inter-package constraints for release
        run: |
          VERSION="${GITHUB_REF_NAME#v}"
          MINOR="${VERSION%.*}"
          sed -i "s/\"waaseyaa\\/\\([^\"]*\\)\": \"@dev\"/\"waaseyaa\\/\\1\": \"^${MINOR}\"/g" \
            packages/${{ matrix.package }}/composer.json

      - name: Validate composer.json
        run: |
          composer validate packages/${{ matrix.package }}/composer.json --strict

      - uses: symplify/monorepo-split-github-action@v2
        env:
          GITHUB_TOKEN: ${{ secrets.SPLIT_GITHUB_TOKEN }}
        with:
          package-directory: packages/${{ matrix.package }}
          split-repository-organization: waaseyaa
          split-repository-name: ${{ matrix.package }}
          tag: ${{ github.ref_name }}
          user-name: "waaseyaa-bot"
          user-email: "bot@waaseyaa.dev"
```

**Required secrets:**
- `SPLIT_GITHUB_TOKEN`: Personal access token with `repo` scope for pushing to mirror repos.

### Step 4: Register packages on Packagist

After the first successful split (producing mirror repos with tags):

1. Go to https://packagist.org/packages/submit
2. Submit each mirror repo URL: `https://github.com/waaseyaa/{package-name}`
3. Enable GitHub webhook: Packagist provides a webhook URL; add it to each mirror repo's Settings → Webhooks.

**Automation (after initial registration):** The split workflow pushes tags to mirrors, Packagist webhooks auto-detect new tags and re-index.

### Step 5: Root composer.json stays unchanged for development

Local development workflow is unchanged:
```bash
composer install  # uses path repos + @dev — same as today
```

Published consumers:
```bash
composer require waaseyaa/core:^1.1    # installs 18 core packages
# or
composer require waaseyaa/entity:^1.1  # installs just the entity package
```

### Sample composer.json — typical subpackage (published form)

```json
{
  "name": "waaseyaa/entity",
  "description": "Waaseyaa entity type system — base classes, EntityTypeManager, and storage contracts",
  "type": "library",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.4",
    "symfony/event-dispatcher": "^7.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5"
  },
  "autoload": {
    "psr-4": {
      "Waaseyaa\\Entity\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Waaseyaa\\Entity\\Tests\\": "tests/"
    }
  }
}
```

### Sample composer.json — metapackage (published form)

```json
{
  "name": "waaseyaa/core",
  "description": "Waaseyaa core metapackage — Foundation + Core Data layers",
  "type": "metapackage",
  "license": "GPL-2.0-or-later",
  "require": {
    "waaseyaa/foundation": "^1.1",
    "waaseyaa/entity": "^1.1",
    "waaseyaa/entity-storage": "^1.1",
    "waaseyaa/access": "^1.1",
    "waaseyaa/user": "^1.1",
    "waaseyaa/config": "^1.1",
    "waaseyaa/field": "^1.1",
    "waaseyaa/cache": "^1.1",
    "waaseyaa/plugin": "^1.1",
    "waaseyaa/typed-data": "^1.1",
    "waaseyaa/database-legacy": "^1.1",
    "waaseyaa/i18n": "^1.1",
    "waaseyaa/queue": "^1.1",
    "waaseyaa/state": "^1.1",
    "waaseyaa/validation": "^1.1",
    "waaseyaa/mail": "^1.1",
    "waaseyaa/routing": "^1.1",
    "waaseyaa/api": "^1.1"
  }
}
```

---

## Phase 4 — Minimal Proof of Concept Sprint

### POC Scope

**3 representative packages:**
| Package | Layer | Rationale |
|---|---|---|
| `waaseyaa/foundation` | 0 — Foundation | No waaseyaa/* dependencies; simplest publish |
| `waaseyaa/entity` | 1 — Core Data | 1 waaseyaa/* dependency (foundation); core to everything |
| `waaseyaa/api` | 4 — API | Multiple waaseyaa/* deps; tests the dependency chain |

### POC Steps

```bash
# 1. Create 3 mirror repos
gh repo create waaseyaa/foundation --public
gh repo create waaseyaa/entity --public
gh repo create waaseyaa/api --public

# 2. Install splitsh-lite
curl -sL https://github.com/splitsh/lite/releases/latest/download/lite_linux_amd64.tar.gz | tar xz
chmod +x splitsh-lite

# 3. Update @dev constraints for the 3 packages (dry run — branch only)
git checkout -b poc/packagist-split
RELEASE="1.1"
for pkg in foundation entity api; do
  sed -i "s/\"waaseyaa\/\([^\"]*\)\": \"@dev\"/\"waaseyaa\/\1\": \"^${RELEASE}\"/g" \
    packages/$pkg/composer.json
done

# 4. Validate
composer validate packages/foundation/composer.json --strict
composer validate packages/entity/composer.json --strict
composer validate packages/api/composer.json --strict

# 5. Split
for pkg in foundation entity api; do
  SHA=$(./splitsh-lite --prefix=packages/$pkg)
  git push https://github.com/waaseyaa/$pkg.git "$SHA:refs/heads/main" --force
  git push https://github.com/waaseyaa/$pkg.git "v1.1.0-poc"
done

# 6. Register on Packagist (manual — browse to packagist.org/packages/submit)

# 7. Verify consumer install in temp project
mkdir /tmp/poc-consumer && cd /tmp/poc-consumer
composer init --no-interaction --name="test/consumer" --stability=stable
composer require waaseyaa/api:^1.1
php -r "require 'vendor/autoload.php'; new Waaseyaa\Api\JsonApiController(null, null); echo 'autoload OK';"
```

### POC Acceptance Criteria

```bash
# Packagist shows the package:
curl -s https://packagist.org/packages/waaseyaa/foundation.json | jq '.package.name'
# Expected: "waaseyaa/foundation"

# Latest version is indexed:
curl -s https://packagist.org/packages/waaseyaa/entity.json | jq '.package.versions | keys[0]'
# Expected: "v1.1.0"

# Consumer install works:
cd /tmp/poc-consumer && composer require waaseyaa/api:^1.1
# Expected: "Installing waaseyaa/api (v1.1.0)"

# Autoloader works:
php -r "require '/tmp/poc-consumer/vendor/autoload.php'; echo class_exists('Waaseyaa\Api\JsonApiController') ? 'PASS' : 'FAIL';"
# Expected: PASS
```

> **Decision Point #3 (Russell must approve):** Run POC before full rollout.

---

## Phase 5 — CI, Release, and Versioning Policy

### Versioning Policy

- **Scheme:** Semantic Versioning 2.0.0 (`MAJOR.MINOR.PATCH`)
- **All packages release together** at the same version (monorepo-style, e.g., all at `v1.1.0`)
- **Tag format:** `vMAJOR.MINOR.PATCH` on the monorepo (e.g., `v1.1.0`)
- **Branch strategy:** `develop/vN.N` for active development, merge to `main` for releases
- **Breaking changes:** Require `MAJOR` bump; documented in CHANGELOG.md per package

### CI Gating Rules

Add to existing CI before the split job can run:

```yaml
jobs:
  validate-packages:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Validate all composer.json files
        run: |
          for dir in packages/*/; do
            echo "Validating $dir"
            composer validate "$dir/composer.json" --strict
          done
      - name: Run PHP test suite
        run: ./vendor/bin/phpunit --configuration phpunit.xml.dist
      - name: Security audit
        run: composer audit
```

Split job runs **only after** validate-packages succeeds.

### Changed-package detection

```yaml
- name: Detect changed packages
  id: changes
  run: |
    CHANGED=$(git diff --name-only HEAD~1 HEAD | grep '^packages/' | cut -d/ -f2 | sort -u | tr '\n' ',')
    echo "packages=$CHANGED" >> $GITHUB_OUTPUT
```

Use `${{ steps.changes.outputs.packages }}` to build a dynamic matrix (only publish changed packages).

### Rollback / yank procedure

```bash
# Yank a broken version from Packagist (via API)
curl -X DELETE \
  -H "Authorization: Bearer $PACKAGIST_API_TOKEN" \
  "https://packagist.org/packages/waaseyaa/entity/v1.1.0"

# Patch release workflow:
# 1. Fix in monorepo
# 2. Tag v1.1.1
# 3. Split workflow republishes all packages at v1.1.1
# 4. Packagist auto-indexes v1.1.1
```

---

## Phase 6 — Documentation and Developer Experience

### Docs plan

Three new docs pages required:

1. **`docs/guides/install-via-packagist.md`** — Consumer guide: `composer require waaseyaa/core`, environment setup, first boot
2. **`docs/guides/local-development.md`** — Path repos, monorepo setup, `composer install` from root
3. **`docs/guides/consumer-smoke-test.md`** — Mini PHP script to verify installation

### README snippet for consumers

```markdown
## Installation

**Via Packagist (recommended for production):**

```bash
# Core framework (entity system, routing, API, auth):
composer require waaseyaa/core:^1.1

# Full CMS stack (adds node, taxonomy, media, menus, paths):
composer require waaseyaa/cms:^1.1

# AI-native full stack (adds AI pipeline, MCP, SSR, Telescope):
composer require waaseyaa/full:^1.1

# Individual package:
composer require waaseyaa/entity:^1.1
```

**Local development (monorepo):**

```bash
git clone https://github.com/waaseyaa/framework
cd framework && composer install
```
```

---

## Phase 7 — Risk Register

| # | Risk | Likelihood | Impact | Mitigation | Detection | Rollback |
|---|---|---|---|---|---|---|
| 1 | **@dev constraint left in published package** — consumers can't resolve version | High (first run) | High | CI `composer validate --strict` gates publish | `grep "@dev" packages/*/composer.json` | Yank version; publish patch |
| 2 | **Circular dependencies between packages** | Low | High | Audit dependency graph before first publish; enforce layer discipline | `composer why-not` from consumer | Refactor dependency direction |
| 3 | **Split push fails for one package** | Medium | Medium | `fail-fast: false` in matrix; each package is independent | GitHub Actions job status | Re-run failed job only |
| 4 | **Packagist webhook mis-fires** (stale/missing version) | Medium | Low | Re-trigger with `composer outdated` check; force Packagist re-scan | `curl packagist.org/packages/NAME.json` | Manual Packagist re-sync |
| 5 | **SPLIT_GITHUB_TOKEN expires or revoked** | Low | High | Set token expiry reminder; use org-level token | CI failure on push to mirror | Rotate token in GitHub secrets |
| 6 | **Version explosion** (40 packages × N versions hard to track) | Medium | Medium | Monorepo simultaneous tagging ensures versions stay aligned | Check all mirrors have same latest tag | Yank stray versions |
| 7 | **Public tag conflicts** (e.g., mirror already has v1.1.0) | Low | Medium | First push to fresh repos; `--force` only on initial setup | CI detects non-fast-forward | Do NOT force-push; skip with warning |
| 8 | **Consumer sees non-semantic "1.1.0" without `v` prefix** | Low | Low | Configure Packagist tag normalization; use `v1.1.0` consistently | Packagist version list | Re-tag if needed (new tag, don't delete) |
| 9 | **CI split takes too long** (40 parallel jobs, rate limits) | Medium | Low | Matrix with `max-parallel: 10`; GitHub Actions has 20 concurrent job limit | Job queue time | Reduce parallelism |
| 10 | **Dependency resolution failure in consumer** (wrong `php` constraint) | Low | Medium | Each package specifies `"php": ">=8.4"` — audit before publish | Consumer `composer require` failure | Publish patch with correct constraint |

---

## Phase 8 — Timeline, Resource Estimate, and Milestones

### Timeline

| Milestone | Tasks | Effort | Calendar Days | Owner |
|---|---|---|---|---|
| M0: Approval | Russell reviews plan, approves strategy | 2h | 1 day | Russell |
| M1: POC (3 packages) | Mirror repos + split workflow + Packagist register + consumer test | 30h | 5–7 days | Release Engineer |
| M2: POC validation | Russell verifies consumer install | 2h | 1 day | Russell |
| M3: Full rollout (40 packages) | Normalize constraints + extend CI matrix + register all on Packagist | 20h | 3–5 days | Release Engineer + CI Engineer |
| M4: Documentation | 3 docs pages + README snippet | 8h | 2 days | Package Steward |
| M5: Post-launch monitoring | Verify webhooks, spot-check installs, yank drill | 4h | 1 day | Release Engineer |
| **Total** | | **66h** | **~4 weeks** | |

**⚠️ Stop condition:** If total effort exceeds 80 hours or calendar time exceeds 6 weeks, escalate to Russell before continuing.

### Recommended team composition

| Role | Responsibility | Allocation |
|---|---|---|
| **Owner (Russell)** | Approves decisions, signs off milestones | Part-time (5h) |
| **Release Engineer** | Split workflow, CI, Packagist registration | Full sprint (40h) |
| **CI Engineer** | Validate/gating jobs, changed-package detection | Part-time (15h) |
| **Package Steward** | Documentation, consumer guides, README | Part-time (10h) |

---

## Phase 9 — Verification Checklist for Russell

### M1: POC complete

```bash
# 1. Mirror repos exist and have tags
gh repo view waaseyaa/foundation --json name,visibility
gh api repos/waaseyaa/foundation/tags --jq '.[].name'
# Expected: "v1.1.0-poc" (or v1.1.0 for real release)

# 2. Packagist has indexed the packages
curl -s https://packagist.org/packages/waaseyaa/foundation.json | jq '.package.name'
curl -s https://packagist.org/packages/waaseyaa/entity.json | jq '.package.versions | keys | .[0]'

# 3. Consumer install works in fresh project
mkdir /tmp/consumer-test && cd /tmp/consumer-test
composer init --no-interaction
composer require waaseyaa/api:^1.1
echo "exit code: $?"
# Expected: 0

# 4. Autoloader smoke test
php -r "
  require 'vendor/autoload.php';
  \$classes = [
    'Waaseyaa\Api\JsonApiController',
    'Waaseyaa\Entity\EntityType',
    'Waaseyaa\Foundation\Kernel\AbstractKernel',
  ];
  foreach (\$classes as \$c) {
    echo class_exists(\$c) ? \"PASS: \$c\n\" : \"FAIL: \$c\n\";
  }
"
```

### M3: Full rollout complete

```bash
# All 40 packages indexed on Packagist
for pkg in access ai-agent ai-pipeline ai-schema ai-vector api cache cli \
            cms config core database-legacy entity entity-storage field \
            foundation full graphql i18n mail media menu mcp node note \
            path plugin queue relationship routing search ssr state \
            taxonomy telescope testing typed-data user validation workflows; do
  status=$(curl -s -o /dev/null -w "%{http_code}" https://packagist.org/packages/waaseyaa/$pkg.json)
  echo "$pkg: $status"
done
# Expected: all 200

# Latest version matches monorepo tag
EXPECTED_TAG="v1.1.0"
for pkg in foundation entity api; do
  latest=$(curl -s https://packagist.org/packages/waaseyaa/$pkg.json | jq -r '.package.versions | keys | .[0]')
  echo "$pkg latest: $latest (expected $EXPECTED_TAG)"
done

# metapackage install pulls all core packages
cd /tmp/consumer-test-full
composer require waaseyaa/core:^1.1
composer show | grep waaseyaa | wc -l
# Expected: >= 18 (core metapackage dependencies)
```

### M4: Documentation complete

```bash
ls docs/guides/install-via-packagist.md docs/guides/local-development.md docs/guides/consumer-smoke-test.md
# Expected: all three files present
grep "composer require waaseyaa" README.md
# Expected: at least one match
```

---

## Final JSON Summary

```json
{
  "strategy_recommended": "monorepo-splitsh-per-package-packagist",
  "poc_packages": ["waaseyaa/foundation", "waaseyaa/entity", "waaseyaa/api"],
  "poc_timeline_days": 7,
  "total_estimated_hours": 66,
  "ci_changes_required": true,
  "packagist_ready": false,
  "next_steps": [
    "Russell approves strategy (Decision Point #1)",
    "Create 40 mirror repos under waaseyaa GitHub org (Decision Point #2)",
    "Add SPLIT_GITHUB_TOKEN secret to waaseyaa/framework Actions",
    "Run POC sprint: split foundation, entity, api — register on Packagist",
    "Russell verifies consumer install against POC packages (Decision Point #3)"
  ],
  "status": "draft"
}
```

---

*This plan was produced by an automated planning run on 2026-03-14. All research findings are based on live codebase inspection of `/home/fsd42/dev/waaseyaa` and current Packagist/GitHub Actions documentation. Russell must review and approve before any implementation sprint begins.*
