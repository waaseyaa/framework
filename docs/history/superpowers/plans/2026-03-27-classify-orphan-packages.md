# Classify 6 Orphan Packages into Layer Architecture

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Assign layer classifications to the 6 unclassified packages (`auth`, `billing`, `deployer`, `github`, `inertia`, `ingestion`) so layer enforcement covers the entire monorepo.

**Architecture:** Each package gets a layer assignment based on its dependencies and responsibilities. The three artifacts that encode layer membership are: (1) the Layer Architecture table in CLAUDE.md, (2) the Orchestration table in CLAUDE.md, and (3) the drift-detector `PATTERN_TO_SPEC` map. The `LayerDependencyTest` forbidden-packages lists must also be updated to include the newly classified packages.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, Bash (drift-detector)

**Issue:** #540

**Classifications:**

| Package | Layer | Reasoning |
|---|---|---|
| `ingestion` | 0 — Foundation | Envelope/payload validation primitives, zero waaseyaa deps |
| `auth` | 1 — Core Data | User credential/session management, depends on foundation + user |
| `billing` | 3 — Services | Stripe payment workflows, external service integration |
| `github` | 3 — Services | GitHub API client for issues/PRs/milestones |
| `deployer` | 6 — Interfaces | CI/CD deployment recipe, no waaseyaa deps |
| `inertia` | 6 — Interfaces | Inertia.js protocol adapter for SSR rendering |

---

### Task 1: Update Layer Architecture table in CLAUDE.md

**Files:**
- Modify: `CLAUDE.md:68-76` (Layer Architecture table)

- [ ] **Step 1: Add packages to their layer rows**

Edit `CLAUDE.md` — update the Layer Architecture table to include the 6 packages in their assigned layers:

```markdown
| Layer | Name | Packages |
|---|---|---|
| 0 | Foundation | foundation, cache, plugin, typed-data, database-legacy, testing, i18n, queue, scheduler, state, validation, mail, http-client, ingestion |
| 1 | Core Data | entity, entity-storage, access, user, config, field, auth |
| 2 | Content Types | node, taxonomy, media, path, menu, note, relationship |
| 3 | Services | workflows, search, notification, billing, github |
| 4 | API | api, routing |
| 5 | AI | ai-schema, ai-agent, ai-pipeline, ai-vector |
| 6 | Interfaces | cli, admin, admin-surface, graphql, mcp, ssr, telescope, deployer, inertia |
```

New additions per row:
- Layer 0: `ingestion` (appended)
- Layer 1: `auth` (appended)
- Layer 3: `billing, github` (appended)
- Layer 6: `deployer, inertia` (appended)

- [ ] **Step 2: Verify edit**

Run: `grep -A 8 "## Layer Architecture" CLAUDE.md`

Expected: Table shows all 6 new packages in their correct layers.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "chore(#540): add 6 orphan packages to layer architecture table"
```

---

### Task 2: Update Orchestration table in CLAUDE.md

**Files:**
- Modify: `CLAUDE.md:28-51` (Orchestration table)

- [ ] **Step 1: Add orchestration rows for each new package**

Add the following rows to the orchestration table in `CLAUDE.md`, inserting them in logical positions near related packages:

After the `packages/foundation/*` row (line 38), add `ingestion`:
```markdown
| `packages/ingestion/*` | `waaseyaa:ingestion` | `docs/specs/ingestion-defaults.md` |
```

After the `packages/access/*` row (line 31), add `auth`:
```markdown
| `packages/auth/*` | `waaseyaa:access-control` | `docs/specs/access-control.md` |
```

After `packages/workflows/*` (line 47), add `billing` and `github`:
```markdown
| `packages/billing/*` | — | — |
| `packages/github/*` | — | — |
```

After `packages/telescope/*` (line 46), add `deployer` and `inertia`:
```markdown
| `packages/deployer/*` | — | — |
| `packages/inertia/*` | — | — |
```

- [ ] **Step 2: Verify no duplicate rows**

Run: `grep -c "packages/auth" CLAUDE.md` — expected: 1
Run: `grep -c "packages/ingestion/\*" CLAUDE.md` — expected: 1 (distinct from the `packages/foundation/src/Ingestion/*` row)

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "chore(#540): add orchestration table rows for 6 orphan packages"
```

---

### Task 3: Update drift-detector PATTERN_TO_SPEC map

**Files:**
- Modify: `tools/drift-detector.sh:57-84` (PATTERN_TO_SPEC associative array)

- [ ] **Step 1: Add package patterns to drift-detector**

Add these entries to the `PATTERN_TO_SPEC` array in `tools/drift-detector.sh`:

```bash
  ["packages/ingestion/"]="docs/specs/ingestion-defaults.md"
  ["packages/auth/"]="docs/specs/access-control.md"
  ["packages/billing/"]="docs/specs/infrastructure.md"
  ["packages/github/"]="docs/specs/infrastructure.md"
  ["packages/deployer/"]="docs/specs/infrastructure.md"
  ["packages/inertia/"]="docs/specs/infrastructure.md"
```

Notes:
- `ingestion` maps to `ingestion-defaults.md` (its primary spec)
- `auth` maps to `access-control.md` (authentication is access-adjacent)
- `billing`, `github`, `deployer`, `inertia` map to `infrastructure.md` as the catch-all for packages without dedicated specs

- [ ] **Step 2: Verify syntax**

Run: `bash -n tools/drift-detector.sh && echo "OK"`

Expected: `OK` (no syntax errors)

- [ ] **Step 3: Commit**

```bash
git add tools/drift-detector.sh
git commit -m "chore(#540): add 6 orphan packages to drift-detector mapping"
```

---

### Task 4: Update LayerDependencyTest forbidden-packages lists

**Files:**
- Modify: `packages/validation/tests/Unit/LayerDependencyTest.php:20-48`
- Modify: `packages/foundation/tests/Unit/LayerDependencyTest.php`

- [ ] **Step 1: Read the foundation LayerDependencyTest**

Read `packages/foundation/tests/Unit/LayerDependencyTest.php` to understand its structure.

- [ ] **Step 2: Update validation LayerDependencyTest (layer 0)**

The validation package is layer 0. It must forbid all packages from layers 1+. Add the 6 orphan packages to the `FORBIDDEN_PACKAGES` constant:

```php
private const FORBIDDEN_PACKAGES = [
    // Layer 1 — Core Data
    'waaseyaa/entity',
    'waaseyaa/entity-storage',
    'waaseyaa/access',
    'waaseyaa/user',
    'waaseyaa/config',
    'waaseyaa/field',
    'waaseyaa/auth',
    // Layer 2 — Content Types
    'waaseyaa/node',
    'waaseyaa/taxonomy',
    'waaseyaa/media',
    'waaseyaa/path',
    'waaseyaa/menu',
    'waaseyaa/note',
    'waaseyaa/relationship',
    // Layer 3 — Services
    'waaseyaa/workflows',
    'waaseyaa/search',
    'waaseyaa/billing',
    'waaseyaa/github',
    // Layer 4 — API
    'waaseyaa/api',
    'waaseyaa/routing',
    // Layer 5 — AI
    'waaseyaa/ai-schema',
    'waaseyaa/ai-agent',
    'waaseyaa/ai-pipeline',
    'waaseyaa/ai-vector',
    // Layer 6 — Interfaces
    'waaseyaa/cli',
    'waaseyaa/admin',
    'waaseyaa/mcp',
    'waaseyaa/ssr',
    'waaseyaa/telescope',
    'waaseyaa/deployer',
    'waaseyaa/inertia',
];
```

New entries: `waaseyaa/auth` (layer 1), `waaseyaa/billing`, `waaseyaa/github` (layer 3), `waaseyaa/deployer`, `waaseyaa/inertia` (layer 6). `waaseyaa/ingestion` is NOT forbidden — it's also layer 0.

- [ ] **Step 3: Update foundation LayerDependencyTest (layer 0)**

Apply the same additions to `packages/foundation/tests/Unit/LayerDependencyTest.php`. Same logic: add the 5 packages from layers 1+ to its forbidden list. `waaseyaa/ingestion` is layer 0 peer — NOT forbidden.

- [ ] **Step 4: Run both tests**

Run: `./vendor/bin/phpunit packages/validation/tests/Unit/LayerDependencyTest.php packages/foundation/tests/Unit/LayerDependencyTest.php`

Expected: 2 tests, 2 assertions, OK

- [ ] **Step 5: Commit**

```bash
git add packages/validation/tests/Unit/LayerDependencyTest.php packages/foundation/tests/Unit/LayerDependencyTest.php
git commit -m "test(#540): add orphan packages to LayerDependencyTest forbidden lists"
```

---

### Task 5: Verify all tests pass and drift detector is clean

- [ ] **Step 1: Run full unit test suite**

Run: `./vendor/bin/phpunit --testsuite Unit`

Expected: All tests pass (no regressions from CLAUDE.md or drift-detector changes)

- [ ] **Step 2: Run drift detector**

Run: `bash tools/drift-detector.sh 20`

Expected: No new STALE or MISSING warnings introduced by these changes.

- [ ] **Step 3: Close issue**

If all checks pass, the acceptance criteria are met:
- [x] Each package assigned to a layer in CLAUDE.md's Layer Architecture table
- [x] Package dependencies verified against assigned layer (no upward imports)
- [x] CLAUDE.md orchestration table updated with file patterns for each package
