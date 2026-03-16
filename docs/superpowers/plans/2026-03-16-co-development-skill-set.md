# Co-Development Skill Set Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create three skills that govern framework-app co-development across waaseyaa, minoo, and claudriel — enforcing patterns, auditing divergence, and guiding framework extraction.

**Architecture:** Three skills layered by purpose: `waaseyaa:app-development` (Tier 2 in waaseyaa, symlinked to apps), `cross-project-audit` (personal skill in `~/.claude/skills/`), `waaseyaa:framework-extraction` (Tier 2 in waaseyaa). Each skill is a SKILL.md following the writing-skills methodology with TDD-style baseline testing before deployment.

**Tech Stack:** Markdown skills with YAML frontmatter, Claude Code skill system, existing codified context infrastructure (CLAUDE.md orchestration, MCP spec retrieval, `docs/specs/`)

**Spec:** `docs/superpowers/specs/2026-03-16-co-development-skill-set-design.md`

---

## Chunk 1: Skill 1 — `waaseyaa:app-development`

### Task 1: Baseline test — app development without the skill

**Files:**
- None created (observation only)

This task establishes what Claude does WITHOUT the skill, so we know what the skill needs to teach.

- [ ] **Step 1: Run baseline pressure scenario**

Dispatch a subagent with this prompt (no skill loaded):

```
You are working in /home/fsd42/dev/claudriel/. The user wants to add a new
entity type called "goal" with fields: title, description, status, due_date.
It needs an access policy, a service provider registration, and API endpoints.

Create the entity class, register it, add access policy, and wire the controller.
Do NOT modify any files — just describe exactly what you would create and the
code you would write. Show full file contents for each file.
```

- [ ] **Step 2: Document baseline behavior**

Record in a scratch file (`docs/superpowers/plans/baseline-app-dev.md`):
- Did the agent check if waaseyaa already provides this pattern?
- Did the agent check if minoo has a similar entity it could follow?
- Did the agent use `mixed $account` or `AccountInterface`?
- Did the agent include field definitions in EntityType registration?
- Did the agent use `#[PolicyAttribute]`?
- What rationalizations or shortcuts did the agent take?

- [ ] **Step 3: Run second baseline in minoo context**

Same prompt but for minoo — "add a `resource` entity type with title, url, category, description." Document divergences from the claudriel baseline.

### Task 2: Write the skill

**Files:**
- Create: `skills/waaseyaa/app-development/SKILL.md`

- [ ] **Step 1: Create skill directory**

```bash
mkdir -p skills/waaseyaa/app-development
```

- [ ] **Step 2: Write SKILL.md**

Write the skill with these sections, informed by baseline failures and real codebase patterns:

```markdown
---
name: waaseyaa-app-development
description: Use when building features in any application built on the waaseyaa framework — entity types, service providers, controllers, access policies, ingestion adapters, or deployment configs. Triggers on app-level code that must follow framework conventions.
---

# Building Applications on Waaseyaa

## Overview

This skill ensures consistent, framework-compliant application development across all apps built on waaseyaa. It provides the canonical patterns, a framework-or-app decision framework, and an anti-duplication checklist.

## When to Use

- Adding entity types, service providers, controllers, access policies
- Wiring ingestion pipelines or deployment configs
- Any time you're writing app-level code that interacts with waaseyaa APIs

## Anti-Duplication Checklist

**Before writing ANY new code, complete these checks:**

1. Search waaseyaa specs: does the framework already provide this?
   - Use `waaseyaa_search_specs <capability>` if available, or grep `/home/fsd42/dev/waaseyaa/packages/`
2. Search sibling apps: has minoo or claudriel already solved this?
   - Grep `/home/fsd42/dev/minoo/src/` and `/home/fsd42/dev/claudriel/src/`
   - Check their specs in `docs/specs/`
3. If prior art exists:
   - Same pattern needed? → Follow the existing implementation
   - Both apps need it? → Nominate for framework extraction (use `waaseyaa:framework-extraction` skill)
   - App-specific variation? → Implement locally but document why it diverges

**Skipping this checklist is a red flag.** If you think "this is obviously app-specific," check anyway.

## Framework-or-App Decision

| Signal | Location |
|--------|----------|
| Two apps need it | Framework package |
| Extends a framework extension point (custom entity, policy, route) | App code |
| Domain-specific business logic, no reuse | App code |
| Infrastructure (caching, deployment, middleware pattern) | Framework candidate |
| Could be useful for ANY waaseyaa app | Framework candidate |

When uncertain, default to app code. Extract to framework later when the second app needs it.

## Canonical Patterns

### Entity Class

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Goal extends ContentEntityBase
{
    protected string $entityTypeId = 'goal';
    protected array $entityKeys = [
        'id' => 'gid',
        'uuid' => 'uuid',
        'label' => 'title',
    ];

    public function __construct(array $values = [])
    {
        // Set defaults before parent constructor
        $values += [
            'status' => 'draft',
        ];
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
```

**Rules:**
- Always `final class`
- Always `declare(strict_types=1)`
- Constructor takes `(array $values = [])` only
- Hardcode `entityTypeId` and `entityKeys` as protected properties
- Pass `$this->entityTypeId` to parent (not a string literal)
- Set defaults via `$values +=` before parent call
- Entity keys: `id` (unique short key), `uuid`, `label` (human-readable field)

### EntityType Registration (Service Provider)

```php
public function register(): void
{
    $this->entityType(new EntityType(
        id: 'goal',
        label: 'Goal',
        class: Goal::class,
        keys: ['id' => 'gid', 'uuid' => 'uuid', 'label' => 'title'],
        group: 'planning',
        fieldDefinitions: [
            'description' => ['type' => 'text_long', 'label' => 'Description'],
            'status' => ['type' => 'string', 'label' => 'Status'],
            'due_date' => ['type' => 'datetime', 'label' => 'Due Date'],
        ],
    ));
}
```

**Rules:**
- Use named constructor parameters
- Always include `fieldDefinitions` — they drive admin UI, JSON Schema, and validation
- Group related entity types with `group:`
- One provider per domain (not one giant provider for all types)
- `register()` = DI bindings + entity types. `boot()` = event subscriptions, Twig globals, cache warming

### Access Policy

```php
<?php

declare(strict_types=1);

namespace App\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\User\AccountInterface;

#[PolicyAttribute(entityType: 'goal')]
final class GoalAccessPolicy implements AccessPolicyInterface
{
    public function access(
        EntityInterface $entity,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        return match ($operation) {
            'view' => AccessResult::allowed(),
            default => $account->hasPermission('administer goals')
                ? AccessResult::allowed()
                : AccessResult::neutral(),
        };
    }
}
```

**Rules:**
- Always use `#[PolicyAttribute(entityType: '...')]` — auto-discovery depends on it
- Type-hint `AccountInterface`, never `mixed`
- Return `AccessResult` — `::allowed()`, `::neutral()`, `::forbidden()`
- Add `FieldAccessPolicyInterface` (intersection type) only if field-level control needed
- Entity-level: `isAllowed()` (deny unless granted). Field-level: `!isForbidden()` (allow unless denied)

### Controller

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\SsrResponse;
use Waaseyaa\User\AccountInterface;
use Symfony\Component\HttpFoundation\Request as HttpRequest;

final class GoalController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function list(
        array $params,
        array $query,
        AccountInterface $account,
        HttpRequest $request,
    ): SsrResponse {
        $storage = $this->entityTypeManager->getStorage('goal');
        $entities = $storage->loadMultiple();
        // Return appropriate response (JSON API or SSR)
    }
}
```

**Rules:**
- Type-hint `AccountInterface $account`, not `mixed $account = null`
- Controller signature: `(array $params, array $query, AccountInterface $account, HttpRequest $request)`
- Use `EntityTypeManager::getStorage()` to access entity storage
- For JSON API: use `ResourceSerializer` with paired nullable `?EntityAccessHandler` + `?AccountInterface`

### Route Registration

```php
public function routes(): void
{
    $this->route('goal.list', '/api/goals', GoalController::class, 'list')
        ->methods(['GET'])
        ->option('_permission', 'access content');

    $this->route('goal.show', '/api/goals/{gid}', GoalController::class, 'show')
        ->methods(['GET'])
        ->option('_permission', 'access content');
}
```

**Rules:**
- Access via route options: `_public`, `_permission`, `_role`, `_gate`
- `_gate` for entity-level access (defers to AccessPolicy)
- `_public` for unauthenticated access
- `_permission` for permission-based access

## Compliance Checklist

When reviewing app code, verify:

| Check | Pass Criteria |
|-------|--------------|
| Entity constructor | Takes `(array $values = [])`, hardcodes entityTypeId/Keys |
| Entity base class | Extends `ContentEntityBase` or `ConfigEntityBase` |
| Entity class | `final class` with `declare(strict_types=1)` |
| EntityType registration | Named params, includes fieldDefinitions |
| Provider separation | register() for DI/entities, boot() for events/globals |
| Provider scope | One per domain, not one monolithic provider |
| Access policy | `#[PolicyAttribute]`, implements `AccessPolicyInterface` |
| Controller typing | `AccountInterface $account`, not `mixed` |
| Route access | Uses `_public`/`_permission`/`_role`/`_gate` options |
| Anti-duplication | Checked framework and sibling apps before implementing |

## Common Mistakes

- Using `mixed $account` instead of `AccountInterface` — breaks type safety and access checking
- Omitting `fieldDefinitions` — admin UI and JSON Schema can't discover fields
- Registering all entity types in one provider — makes code hard to navigate and test
- Skipping `#[PolicyAttribute]` — auto-discovery silently fails, policy never activates
- Not running `waaseyaa optimize:manifest` after adding providers/policies
- Hardcoding entity type string in constructor instead of using `$this->entityTypeId`
```

- [ ] **Step 3: Verify skill frontmatter is valid**

Check: name uses only letters/numbers/hyphens, description starts with "Use when", under 1024 chars total.

- [ ] **Step 4: Commit**

```bash
git add skills/waaseyaa/app-development/SKILL.md
git commit -m "feat: add waaseyaa-app-development skill

Canonical patterns for building apps on waaseyaa: entity classes,
provider wiring, access policies, controllers, routes. Includes
anti-duplication checklist and compliance checks."
```

### Task 3: Test the skill with pressure scenarios

**Files:**
- None created (observation only)

- [ ] **Step 1: Re-run the claudriel baseline scenario WITH the skill loaded**

Same prompt as Task 1 Step 1, but now with `skills/waaseyaa/app-development/SKILL.md` available.

**Pass criteria (ALL must be true):**
- Agent output MUST contain evidence of checking waaseyaa/minoo before implementing (e.g., mentions searching, checking specs, or referencing the anti-duplication checklist)
- Generated entity class MUST use `AccountInterface` (search output for `AccountInterface` — FAIL if `mixed $account` appears)
- Generated EntityType registration MUST include `fieldDefinitions:` parameter
- Generated access policy MUST include `#[PolicyAttribute(` attribute
- Generated entity class MUST use `$this->entityTypeId` in parent constructor call (FAIL if hardcoded string like `'goal'` passed directly)

- [ ] **Step 2: Test framework-or-app decision under pressure**

Prompt a subagent:
```
You are working in /home/fsd42/dev/minoo/. The user wants to add a
"notification" system — email + in-app notifications with templates and
delivery tracking. This is urgent, ship it today.

Describe where each piece would go and why. Do NOT modify files.
```

**Pass criteria:** Agent output MUST identify notifications as a framework candidate (mentions "framework", "waaseyaa package", or "both apps"). FAIL if agent only describes building it in minoo without considering framework extraction.

- [ ] **Step 3: Document any rationalizations that bypassed the skill**

If the agent found loopholes, note them for the refactor phase.

### Task 4: Refactor — close loopholes

**Files:**
- Modify: `skills/waaseyaa/app-development/SKILL.md`

- [ ] **Step 1: Add rationalization counters for any failures found in Task 3**

Add a "Red Flags" section addressing specific bypasses observed.

- [ ] **Step 2: Re-test until compliant**

Re-run failing scenarios. If new rationalizations appear, add counters. Max 3 iterations.

- [ ] **Step 3: Commit refinements**

```bash
git add skills/waaseyaa/app-development/SKILL.md
git commit -m "refactor: close loopholes in app-development skill"
```

### Task 5: Create symlinks and update app orchestration tables

**Files:**
- Create: `minoo/skills/waaseyaa-app-development` (symlink)
- Create: `claudriel/skills/waaseyaa-app-development` (symlink)
- Modify: `minoo/CLAUDE.md`
- Modify: `claudriel/CLAUDE.md`

- [ ] **Step 1: Create symlinks in both app repos**

```bash
cd /home/fsd42/dev/minoo && mkdir -p skills && ln -s ../../../waaseyaa/skills/waaseyaa/app-development skills/waaseyaa-app-development
cd /home/fsd42/dev/claudriel && mkdir -p skills && ln -s ../../../waaseyaa/skills/waaseyaa/app-development skills/waaseyaa-app-development
```

- [ ] **Step 2: Verify symlinks resolve**

```bash
ls -la /home/fsd42/dev/minoo/skills/waaseyaa-app-development/SKILL.md
ls -la /home/fsd42/dev/claudriel/skills/waaseyaa-app-development/SKILL.md
```

- [ ] **Step 3: Add orchestration entries to minoo's CLAUDE.md**

Add to the orchestration table:
```markdown
| `src/Entity/*`, `src/Provider/*`, `src/Access/*` | `waaseyaa-app-development` | `docs/specs/entity-model.md` |
| `src/Controller/*`, `src/Routing/*` | `waaseyaa-app-development` | — |
```

- [ ] **Step 4: Add orchestration entries to claudriel's CLAUDE.md**

Add to the orchestration table:
```markdown
| `src/Entity/*`, `src/Provider/*`, `src/Access/*` | `waaseyaa-app-development` | `docs/specs/entity.md` |
| `src/Controller/*`, `src/Routing/*` | `waaseyaa-app-development` | — |
```

- [ ] **Step 5: Commit in each repo**

```bash
cd /home/fsd42/dev/minoo && git add skills/waaseyaa-app-development CLAUDE.md && git commit -m "feat: wire waaseyaa-app-development skill via symlink"
cd /home/fsd42/dev/claudriel && git add skills/waaseyaa-app-development CLAUDE.md && git commit -m "feat: wire waaseyaa-app-development skill via symlink"
```

---

## Chunk 2: Skill 2 — `cross-project-audit`

### Task 6: Baseline test — audit without the skill

**Files:**
- None created (observation only)

- [ ] **Step 1: Run baseline audit scenario**

Dispatch a subagent:
```
You have access to three codebases:
- /home/fsd42/dev/waaseyaa (PHP framework)
- /home/fsd42/dev/minoo (app)
- /home/fsd42/dev/claudriel (app)

Compare how minoo and claudriel implement entity types, service providers,
and access policies. Identify where they diverge and whether any app code
should be extracted to the framework. Produce a structured report.
```

- [ ] **Step 2: Document baseline behavior**

Record: Did the agent produce a structured, comparable format? Did it find the known divergences (mixed vs AccountInterface, fieldDefinitions presence, monolithic vs per-domain providers)? Did it suggest actionable next steps?

### Task 7: Write the skill

**Files:**
- Create: `~/.claude/skills/cross-project-audit/SKILL.md`

- [ ] **Step 1: Create skill directory**

```bash
mkdir -p ~/.claude/skills/cross-project-audit
```

- [ ] **Step 2: Write SKILL.md**

```markdown
---
name: cross-project-audit
description: Use when auditing waaseyaa app codebases for pattern divergence, framework-candidate code, or compliance with framework conventions. Also use for pre-implementation checks to find prior art across sibling apps.
---

# Cross-Project Audit

## Overview

Compares implementation patterns across waaseyaa apps (minoo, claudriel) to detect divergence, identify framework extraction candidates, and track compliance over time. Two modes: full audit and quick check.

## When to Use

- Periodic health check across all apps
- Before implementing a feature that might exist in a sibling app
- After completing a framework extraction to verify migration
- When onboarding to understand cross-project state

## Known Repos

| Repo | Path | Specs | MCP Tools |
|------|------|-------|-----------|
| waaseyaa | `/home/fsd42/dev/waaseyaa` | `docs/specs/` | `waaseyaa_*` (if available) |
| minoo | `/home/fsd42/dev/minoo` | `docs/specs/` | `minoo_*` (if available) |
| claudriel | `/home/fsd42/dev/claudriel` | `docs/specs/` | claudriel-specs (if available) |

**Primary method:** File-based scanning (grep/glob across paths). Works regardless of which repo started the session.
**Supplementary:** MCP spec tools when repos are added as working directories.

## Full Audit Mode

Run when asked to "audit", "compare projects", or "check divergence."

### Step 1: Scan Pattern Categories

For each category, grep both app codebases and compare:

| Category | What to Scan | Where |
|----------|-------------|-------|
| Entity classes | Base class, constructor shape, entityTypeId pattern | `src/Entity/` |
| EntityType registration | fieldDefinitions presence, named params, grouping | `src/Provider/` |
| Service providers | register() vs boot() separation, scope (per-domain vs monolithic) | `src/Provider/` |
| Access policies | `#[PolicyAttribute]`, interface compliance, typing | `src/Access/` |
| Controllers | Account type-hint, signature pattern, response style | `src/Controller/` |
| Routes | Access option usage, naming conventions | `src/Provider/` or `src/Routing/` |
| Ingestion | Adapter pattern, validation, mapper structure | `src/Ingestion/` |
| Deployment | Deployer config, CI pipeline, env vars | `deploy.php`, `.github/workflows/` |

### Step 2: Produce Structured Report

Use this exact format (machine-comparable across runs):

```markdown
## Cross-Project Audit Report — YYYY-MM-DD

### Pattern Divergence Inventory
| Category | Minoo Pattern | Claudriel Pattern | Divergence | Action |
|----------|--------------|-------------------|------------|--------|

### Framework Candidates
- [ ] [capability] — found in [app], reason: [why extract]

### Compliance Checklist
| Category | Check | Minoo | Claudriel |
|----------|-------|-------|-----------|
| Entity | Constructor takes `(array $values = [])` | Pass/Fail | Pass/Fail |
| Entity | Uses `$this->entityTypeId` in parent call | Pass/Fail | Pass/Fail |
| Entity | `final class` with `declare(strict_types=1)` | Pass/Fail | Pass/Fail |
| Registration | Includes fieldDefinitions | Pass/Fail | Pass/Fail |
| Registration | Uses named constructor params | Pass/Fail | Pass/Fail |
| Provider | register() vs boot() separation | Pass/Fail | Pass/Fail |
| Provider | Per-domain scope (not monolithic) | Pass/Fail | Pass/Fail |
| Access | `#[PolicyAttribute]` on all policies | Pass/Fail | Pass/Fail |
| Access | Implements `AccessPolicyInterface` | Pass/Fail | Pass/Fail |
| Controller | `AccountInterface` type-hint | Pass/Fail | Pass/Fail |
| Controller | Standard signature pattern | Pass/Fail | Pass/Fail |
| Routes | Uses access options | Pass/Fail | Pass/Fail |

### Summary
- Total checks: N
- Minoo: N pass / N fail
- Claudriel: N pass / N fail

### Trend (vs previous audit)
| Metric | Previous | Current | Delta |
|--------|----------|---------|-------|
| Minoo pass rate | —% | —% | — |
| Claudriel pass rate | —% | —% | — |
| Framework candidates | — | — | — |
| Divergences | — | — | — |
```

### Step 3: Save Report

Save to `/home/fsd42/dev/waaseyaa/docs/audits/YYYY-MM-DD-audit.md`

For trend comparison, read the most recent previous report from that directory and populate the Trend section.

## Quick Check Mode

Run when asked to "check if [feature] exists" or before implementing something new.

1. Ask: "What are you about to build?"
2. Grep both sibling app codebases for related code
3. Check sibling app specs (`docs/specs/`) for related documentation
4. Report:
   - **Found in [app]:** [description of implementation, key files]
   - **Recommendation:** Follow existing pattern / Extract to framework / App-specific, proceed

## Common Mistakes

- Reporting divergence without actionable recommendations
- Comparing surface syntax instead of architectural patterns
- Missing the `docs/specs/` files which document intent, not just implementation
- Not checking previous audit reports for trend comparison
```

- [ ] **Step 3: Verify frontmatter validity**

Check name, description constraints.

- [ ] **Step 4: Commit (if `~/.claude` is a git repo)**

```bash
cd ~/.claude && git rev-parse --git-dir 2>/dev/null && git add skills/cross-project-audit/SKILL.md && git commit -m "feat: add cross-project-audit skill for framework-app governance"
```

If `~/.claude/` is not a git repo, this step is a no-op — the file is created and usable regardless. The user's dotfile management tracks it separately.

### Task 8: Test the audit skill

**Files:**
- None created (observation only)

- [ ] **Step 1: Run full audit with skill loaded**

Dispatch subagent with the skill available and prompt: "Run a full cross-project audit."

**Pass criteria (ALL must be true):**
- Output MUST contain "Pattern Divergence Inventory" table header
- Output MUST contain "Compliance Checklist" table with Pass/Fail values (not placeholders)
- Checklist MUST flag claudriel's `mixed $account` as Fail on "AccountInterface type-hint"
- Checklist MUST flag claudriel's missing fieldDefinitions as Fail
- Report MUST be saved to `/home/fsd42/dev/waaseyaa/docs/audits/` directory

- [ ] **Step 2: Run quick check mode**

Prompt: "I'm about to add email notification support in claudriel. Quick check — has minoo solved this?"

**Pass criteria:** Output MUST reference minoo's mail/notification implementation with specific file paths. MUST include a recommendation (Follow / Extract / Proceed).

- [ ] **Step 3: Document failures and refine**

Fix any format or coverage issues found. Commit refinements.

- [ ] **Step 4: Create audits directory and run first real audit**

```bash
mkdir -p /home/fsd42/dev/waaseyaa/docs/audits && touch /home/fsd42/dev/waaseyaa/docs/audits/.gitkeep
```

Run the first real audit to establish baseline. Commit the report.

Expected: Report file exists at `/home/fsd42/dev/waaseyaa/docs/audits/YYYY-MM-DD-audit.md` with all compliance checklist items populated as Pass or Fail.

```bash
cd /home/fsd42/dev/waaseyaa && git add docs/audits/ && git commit -m "docs: add first cross-project audit baseline report"
```

---

## Chunk 3: Skill 3 — `waaseyaa:framework-extraction`

### Task 9: Baseline test — extraction without the skill

**Files:**
- None created (observation only)

- [ ] **Step 1: Run baseline extraction scenario**

Dispatch subagent:
```
Both minoo (/home/fsd42/dev/minoo/) and claudriel (/home/fsd42/dev/claudriel/)
have their own I18nServiceProvider. The framework (waaseyaa) has an i18n package
at /home/fsd42/dev/waaseyaa/packages/i18n/.

Describe how you would extract the common i18n provider pattern into the
framework so both apps can use a shared base. Do NOT modify files — just
describe the extraction plan: what goes in the framework, what stays in
the apps, and how you'd verify nothing breaks.
```

- [ ] **Step 2: Document baseline**

Did the agent: check both implementations? identify the common interface? propose the right waaseyaa layer? consider app-specific overrides? mention updating specs?

### Task 10: Write the skill

**Files:**
- Create: `skills/waaseyaa/framework-extraction/SKILL.md`

- [ ] **Step 1: Create skill directory**

```bash
mkdir -p skills/waaseyaa/framework-extraction
```

- [ ] **Step 2: Write SKILL.md**

```markdown
---
name: waaseyaa-framework-extraction
description: Use when moving app-specific code into the waaseyaa framework — triggered by audit findings, pattern duplication across apps, or recognition that a capability belongs at the framework level.
---

# Framework Extraction

## Overview

Guides the process of extracting code from app repos (minoo, claudriel) into the waaseyaa framework. Ensures clean interfaces, proper layer placement, and verified migration across all repos.

## When to Use

- Audit report nominates a framework candidate
- Two apps have independently solved the same problem
- App code has no domain-specific logic and could benefit any waaseyaa app
- You recognize infrastructure code living at the wrong level

## Extraction Process

### 1. Scope

Before writing any code:

- **Read both implementations.** Don't assume they're similar — read the actual code.
- **Identify the common interface.** What's the minimal abstraction that covers both use cases?
- **Identify app-specific parts.** What MUST stay in the app? Configuration? Domain logic? Custom behavior?
- **Check existing framework packages.** Does the capability extend an existing package or need a new one?

### 2. Layer Placement

| Layer | When to place here |
|-------|--------------------|
| 0 — Foundation | Cross-cutting utilities, base classes, no entity/storage deps |
| 1 — Core Data | Entity/storage/access extensions, field types |
| 2 — Content Types | New entity type packages (node, taxonomy, etc.) |
| 3 — Services | Service-level features (search, workflows) |
| 4 — API | Routing, serialization, schema |
| 5 — AI | AI pipeline, schema, vector |
| 6 — Interfaces | CLI, admin, SSR, MCP |

**Rule:** Packages import from own layer or lower only. Upward communication via DomainEvents.

### 3. Design the Extension Point

Choose the right mechanism:

| Mechanism | When |
|-----------|------|
| Interface + app implementation | App provides the behavior, framework defines the contract |
| Abstract class + app extends | Shared base logic with app-specific overrides |
| Config key | App provides values, framework provides the engine |
| Event/listener | Framework emits, app reacts |
| Service provider hook | App registers capabilities during boot |

**Prefer interfaces over abstract classes.** Interfaces are more testable and don't create inheritance coupling.

### 4. Execute

1. Create/modify the framework package
2. Define the extension point (interface, config, event)
3. Write framework-level tests
4. Update app 1 to use the framework version
5. Run app 1 tests — verify no regression
6. Update app 2 to use the framework version
7. Run app 2 tests — verify no regression
8. Delete the old app-level code from both apps

**Order matters:** Update one app at a time. Don't try to update both simultaneously.

### 5. Verify

Run all test suites:
```bash
cd /home/fsd42/dev/waaseyaa && ./vendor/bin/phpunit
cd /home/fsd42/dev/minoo && ./vendor/bin/phpunit
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit
```

### 6. Document

Update these files:

- **Framework spec:** Update the relevant `docs/specs/` file in waaseyaa
- **App specs:** Update both apps' `docs/specs/` to reference the framework capability
- **Extraction log:** Append to `docs/specs/extraction-log.md`:

```markdown
## Extraction: [capability]
- **Date:** YYYY-MM-DD
- **Source:** minoo, claudriel (or just one)
- **Target:** waaseyaa/[package]
- **Layer:** [N]
- **Extension point:** [interface/config/event name]
- **Why:** [what drove extraction — audit finding, duplication, etc.]
- **Apps updated:** minoo (commit abc), claudriel (commit def)
```

Update the `waaseyaa:app-development` skill's pattern catalog if the extraction creates a new canonical pattern.

## Red Flags

- Extracting without reading BOTH app implementations first
- Creating an abstraction that only fits one app's use case
- Skipping the verification step ("tests passed in waaseyaa so it's fine")
- Not updating specs and extraction log
- Extracting prematurely — wait until the second app actually needs it

## Common Mistakes

- **Over-abstracting:** The framework interface should be minimal. If it has 10 methods and the apps each use 3, the interface is too large.
- **Breaking layer discipline:** A Layer 0 extraction must not import from Layer 1+. Use string constants for cross-layer attribute references.
- **Forgetting Composer deps:** New packages need `composer.json` with path repositories. Both apps need the new dependency added.
- **Not running optimize:manifest:** After adding new providers or policies, the manifest cache is stale.
```

- [ ] **Step 3: Verify frontmatter validity**

- [ ] **Step 4: Commit**

```bash
git add skills/waaseyaa/framework-extraction/SKILL.md
git commit -m "feat: add framework-extraction skill for app-to-framework code migration"
```

### Task 11: Test the extraction skill

**Files:**
- None created (observation only)

- [ ] **Step 1: Re-run the I18n extraction scenario WITH the skill loaded**

Same prompt as Task 9.

**Pass criteria (ALL must be true):**
- Agent output MUST reference reading both minoo's and claudriel's I18nServiceProvider implementations (specific file paths)
- Agent MUST identify the correct waaseyaa layer (Layer 0 — Foundation, since `packages/i18n` already exists there)
- Agent MUST propose an interface or abstract class as the extension point (FAIL if it just copies one app's code)
- Agent MUST include verification commands for all three repos' test suites
- Agent MUST mention updating `docs/specs/extraction-log.md`

- [ ] **Step 2: Test with edge case — premature extraction**

Prompt:
```
Claudriel has a "temporal agent" system for scheduling background work.
Minoo doesn't have anything like this. Should we extract it to the framework?
```

**Pass criteria:** Agent MUST recommend keeping it app-specific (mentions "only one app", "premature", or "not a framework candidate"). FAIL if agent proposes extracting it.

- [ ] **Step 3: Document any rationalizations that bypassed the skill**

If the agent found loopholes, note them for the refactor phase.

### Task 12: Refactor — close loopholes in extraction skill

**Files:**
- Modify: `skills/waaseyaa/framework-extraction/SKILL.md`

- [ ] **Step 1: Add rationalization counters for any failures found in Task 11**

Add explicit counters for bypasses observed. Common patterns to watch for:
- "This is simple enough to just move" (skipping the scope analysis)
- "Tests pass in waaseyaa so both apps are fine" (skipping per-app verification)
- "I'll update the specs later" (skipping documentation)

- [ ] **Step 2: Re-test until compliant**

Re-run failing scenarios. If new rationalizations appear, add counters. Max 3 iterations.

- [ ] **Step 3: Commit refinements**

```bash
git add skills/waaseyaa/framework-extraction/SKILL.md
git commit -m "refactor: close loopholes in framework-extraction skill"
```

### Task 13: Create extraction log and finalize

**Files:**
- Create: `docs/specs/extraction-log.md`

- [ ] **Step 1: Create extraction log**

```markdown
# Extraction Log

Tracks code extracted from app repos (minoo, claudriel) into the waaseyaa framework.

See `waaseyaa:framework-extraction` skill for the extraction process.

---

(No extractions yet — this log was created as part of the co-development skill set.)
```

- [ ] **Step 2: Commit**

```bash
git add docs/specs/extraction-log.md
git commit -m "docs: add extraction log for co-development governance"
```

- [ ] **Step 3: Update waaseyaa CLAUDE.md orchestration table**

Add entries for the new skills and audit reports:
```markdown
| `skills/waaseyaa/app-development/*` | — | — |
| `skills/waaseyaa/framework-extraction/*` | — | `docs/specs/extraction-log.md` |
| `docs/audits/*` | — | — |
```

- [ ] **Step 4: Commit CLAUDE.md update**

```bash
git add CLAUDE.md
git commit -m "docs: add co-development skills to orchestration table"
```
