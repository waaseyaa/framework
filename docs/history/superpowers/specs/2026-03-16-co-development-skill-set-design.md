# Co-Development Skill Set Design

**Date:** 2026-03-16
**Status:** Draft
**Scope:** Three skills for framework-app co-development governance across waaseyaa, minoo, and claudriel

## Problem

Waaseyaa (framework), Minoo (Indigenous knowledge platform), and Claudriel (AI personal ops system) are developed together toward v1. All three repos use codified context (CLAUDE.md orchestration → Tier 2 skills → Tier 3 specs with MCP retrieval). The challenges:

1. **Context switching:** When Claude works in one repo, it lacks awareness of what the other repos provide or have already solved.
2. **Pattern divergence:** Both apps may solve the same problem differently, creating duplication and inconsistency that should instead be framework-level abstractions.
3. **No measurement:** There is no systematic way to detect drift, score compliance, or track improvement over time.

## Design

Three skills forming a continuous cycle: **develop → measure → extract → repeat**.

### Skill 1: `waaseyaa-app-development`

**Location:** `waaseyaa/skills/waaseyaa/app-development/SKILL.md`
**Type:** Tier 2 skill (referenced from app CLAUDE.md orchestration tables)
**Triggers:** Working in any app repo on entities, service providers, controllers, access policies, ingestion pipelines, or deployment configs.

#### Responsibilities

1. **Framework-or-app decision framework.** Concrete criteria for where code belongs:
   - If two apps need it → framework
   - If it extends a framework extension point (e.g., custom entity type, access policy) → app
   - If it's domain-specific business logic with no reuse potential → app
   - If it's infrastructure (caching strategy, deployment pattern, middleware) → framework candidate

2. **Canonical pattern catalog.** The right way to do each common app task on waaseyaa:
   - Entity type registration (EntityType definition → entity class → provider → storage schema → access policy)
   - Service provider wiring (register vs boot, event subscriptions, route registration)
   - Controller and route patterns (JsonApiController CRUD, route access options, ResourceSerializer)
   - Access policy patterns (PolicyAttribute, intersection types for field access, entity vs field semantics)
   - Ingestion adapter patterns (source adapter interface, envelope validation, mapper registration)
   - Deployment configuration (Deployer structure, CI pipeline, environment variables)

3. **Anti-duplication checklist.** Before implementing, the skill instructs Claude to:
   - Check if waaseyaa already provides the capability (search waaseyaa specs via MCP)
   - Check if the other app already solved it (search the other app's specs and codebase)
   - If prior art exists: follow the existing pattern or nominate for framework extraction

4. **Cross-references.** Points to each app's own Tier 3 specs via MCP tools rather than duplicating domain knowledge.

#### Integration with App Orchestration Tables

Each app's CLAUDE.md adds entries like:

```markdown
| `src/Entity/*`, `src/Provider/*` | `waaseyaa-app-development` | (app's own entity spec) |
| `src/Controller/*`, `src/Routing/*` | `waaseyaa-app-development` | (app's own API spec) |
```

**Skill resolution mechanism:** Apps cannot directly load skills from waaseyaa's `skills/` directory. Instead, each app symlinks the skill into its own skills directory:

```bash
# In minoo:
ln -s ../../../waaseyaa/skills/waaseyaa/app-development skills/waaseyaa-app-development

# In claudriel:
ln -s ../../../waaseyaa/skills/waaseyaa/app-development skills/waaseyaa-app-development
```

This ensures the skill is discoverable from the app's project context without copying. The symlink follows the relative path convention already used by Composer path repositories. When waaseyaa updates the skill, both apps get the update automatically.

### Skill 2: `cross-project-audit`

**Location:** `~/.claude/skills/cross-project-audit/SKILL.md`
**Type:** Personal skill (sees across all repos)
**Triggers:** On-demand audit request, or pre-implementation check when building a feature that might exist elsewhere.

#### Two Modes

**Full audit mode** — comprehensive cross-project scan:

1. Scan codebases using file reads and grep across the three known repo paths (MCP tools are project-scoped and may not all be available in a single session; file-based scanning is the reliable cross-project method)
2. Query available MCP spec tools for any repos added as working directories (bonus context, not required)
3. Compare pattern categories across apps:
   - Entity registration patterns
   - Service provider structure
   - Controller/routing conventions
   - Access policy patterns
   - Ingestion pipeline structure
   - Deployment and CI configuration
   - Frontend/SSR patterns
3. Produce a structured report:

```markdown
## Cross-Project Audit Report — YYYY-MM-DD

### Pattern Divergence Inventory
| Category | Minoo Pattern | Claudriel Pattern | Divergence Level | Action |
|----------|--------------|-------------------|------------------|--------|
| Entity registration | ... | ... | Low/Medium/High | Align / Extract / OK |

### Framework Candidates
- [ ] Description — found in [app], reason for extraction

### Compliance Checklist
| Category | Check | Minoo | Claudriel |
|----------|-------|-------|-----------|
| Entity patterns | Entity class extends correct base | Pass/Fail | Pass/Fail |
| Entity patterns | EntityType uses named constructor params | Pass/Fail | Pass/Fail |
| Providers | register() vs boot() separation correct | Pass/Fail | Pass/Fail |
| Access | PolicyAttribute on all access policies | Pass/Fail | Pass/Fail |
| ... | (full checklist defined in Skill 1) | ... | ... |

### Trend (vs previous audit)
| Category | Previous | Current | Delta |
|----------|----------|---------|-------|
```

4. Save report to `waaseyaa/docs/audits/YYYY-MM-DD-audit.md` (version-controlled, shareable)

**Quick check mode** — lightweight pre-implementation query (invoked manually, unlike Skill 1's anti-duplication checklist which fires automatically via orchestration triggers):

1. Developer describes what they're about to build
2. Skill scans the other app's codebase and specs for prior art
3. Returns: "Already solved in [app] as [pattern]. Follow it / extract it / this is app-specific, proceed."

#### Known Repo Paths

The skill hardcodes the three repo paths:
- `/home/fsd42/dev/waaseyaa`
- `/home/fsd42/dev/minoo`
- `/home/fsd42/dev/claudriel`

**Cross-repo access strategy:** MCP tools are project-scoped — `waaseyaa_*` tools are only available when waaseyaa is a working directory, etc. The audit skill does NOT depend on MCP tools. It uses file-based scanning (grep/glob across the three known paths) as its primary method, which works regardless of which repo the session started in. When MCP tools happen to be available (because repos were added as working directories), the skill uses them as supplementary context for richer spec queries.

### Skill 3: `waaseyaa-framework-extraction`

**Location:** `waaseyaa/skills/waaseyaa/framework-extraction/SKILL.md`
**Type:** Tier 2 skill in waaseyaa
**Triggers:** Audit report nominations, or manual recognition that app code should be framework-level.

#### Extraction Process

1. **Scope the extraction:**
   - What is the capability being extracted?
   - Which apps use it? How do their implementations differ?
   - What is the minimal generic interface that covers both use cases?

2. **Determine placement using layer architecture:**
   - Layer 0 (Foundation): infrastructure utilities, cross-cutting concerns
   - Layer 1 (Core Data): entity/storage/access extensions
   - Layer 2 (Content Types): new content type packages
   - Layer 3 (Services): service-level features
   - Layer 4+ (API/AI/Interfaces): higher-level capabilities
   - Rule: packages can only import from their own layer or lower

3. **Design the extension point:**
   - Define the interface/abstract class that lives in the framework
   - Design the configuration or registration mechanism apps will use
   - Ensure apps can customize behavior without forking the framework code

4. **Execute the extraction:**
   - Create or modify the framework package
   - Define the extension point (interface, abstract class, event, config key)
   - Update both apps to use the new framework capability
   - Remove duplicated code from apps
   - Update Composer dependencies if a new package was created

5. **Verify across all repos:**
   - Run waaseyaa tests
   - Run minoo tests
   - Run claudriel tests
   - Confirm no regression

6. **Document the extraction:**
   - Update the framework's relevant Tier 3 spec
   - Update both apps' specs to reference the framework capability
   - Log the extraction in `docs/specs/extraction-log.md`:

```markdown
## Extraction: [capability name]
- **Date:** YYYY-MM-DD
- **Source:** [app(s)]
- **Target package:** waaseyaa/[package]
- **Layer:** [N]
- **Extension point:** [interface/config/event]
- **Why:** [what drove the extraction]
- **Apps updated:** minoo (PR #N), claudriel (PR #N)
```

#### Relationship to Other Skills

- `cross-project-audit` identifies candidates → this skill executes
- After extraction, `waaseyaa-app-development` pattern catalog is updated to reflect the new framework capability
- Future audits verify both apps migrated to the framework version

## Cycle Integration

```
┌─────────────────────────────────────────────────────┐
│                                                     │
│  ┌──────────────┐    ┌──────────────────┐          │
│  │ Skill 1:     │    │ Skill 2:         │          │
│  │ app-         │───▶│ cross-project-   │          │
│  │ development  │    │ audit            │          │
│  └──────────────┘    └────────┬─────────┘          │
│        ▲                      │                     │
│        │                      │ candidates          │
│        │                      ▼                     │
│        │             ┌──────────────────┐          │
│        │             │ Skill 3:         │          │
│        └─────────────│ framework-       │          │
│    pattern catalog   │ extraction       │          │
│    updated           └──────────────────┘          │
│                                                     │
└─────────────────────────────────────────────────────┘
```

1. **Develop** — Skill 1 governs app development, enforces patterns, checks for prior art
2. **Measure** — Skill 2 audits across projects, scores compliance, tracks trends
3. **Extract** — Skill 3 moves framework-candidate code from apps to waaseyaa
4. **Repeat** — Skill 1's pattern catalog reflects the extraction, audit scores improve

## Implementation Sequence

1. Write `waaseyaa-app-development` first — it's the foundation that the other two reference
2. Write `cross-project-audit` second — it needs to understand the patterns Skill 1 defines
3. Write `waaseyaa-framework-extraction` third — it executes on Skill 2's findings
4. Update app CLAUDE.md orchestration tables to reference Skill 1
5. Run first full audit to establish baseline scores
6. Create `docs/specs/extraction-log.md` in waaseyaa for tracking

## Testing Strategy

Per the writing-skills methodology, each skill is tested with pressure scenarios before deployment:

- **Skill 1:** Test with subagent working in an app repo — does it check for prior art before implementing? Does it correctly classify framework-vs-app code?
- **Skill 2:** Test with subagent running audit — does it find known divergences? Does it produce a structured, comparable report?
- **Skill 3:** Test with subagent given an extraction task — does it follow the process? Does it update all three repos?

## Files Created/Modified

**New files:**
- `waaseyaa/skills/waaseyaa/app-development/SKILL.md`
- `~/.claude/skills/cross-project-audit/SKILL.md`
- `waaseyaa/skills/waaseyaa/framework-extraction/SKILL.md`
- `waaseyaa/docs/specs/extraction-log.md`
- `waaseyaa/docs/audits/` (directory for audit reports)

**New symlinks in app repos:**
- `minoo/skills/waaseyaa-app-development` → `../../../waaseyaa/skills/waaseyaa/app-development`
- `claudriel/skills/waaseyaa-app-development` → `../../../waaseyaa/skills/waaseyaa/app-development`

**Modified files:**
- `minoo/CLAUDE.md` — add orchestration entries for `waaseyaa-app-development`
- `claudriel/CLAUDE.md` — add orchestration entries for `waaseyaa-app-development`
