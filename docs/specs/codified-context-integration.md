# Codified Context Integration Spec

**Owns:** Multi-repo inheritance model for codified context across Waaseyaa framework and applications

---

## Overview

Waaseyaa applications use a three-tier codified context system that enables AI coding assistants to understand architectural invariants, domain contracts, and operational conventions. This spec defines how context is structured, inherited, and federated across repositories.

---

## Three-Tier Architecture

| Tier | Location | Scope | Inheritance |
|------|----------|-------|-------------|
| **Constitution** | `CLAUDE.md` | App-level architecture, orchestration table, conventions | Project skeleton provides template; apps customize |
| **Rules** | `.claude/rules/*.md` | Silent invariants, always active | Project skeleton provides framework rules; apps may add app-specific rules |
| **Specs** | `docs/specs/*.md` | Domain contracts per subsystem | Apps create their own; framework specs live in Waaseyaa repo |

---

## Inheritance Model

### What Lives Where

#### Waaseyaa Framework (`waaseyaa/framework`)

Framework-level rules and specs that apply to ALL Waaseyaa applications:

```
.claude/rules/
├── data-freshness.md           # Source-over-summary verification
├── shell-compatibility.md      # Cross-platform bash safety
└── entity-storage-invariant.md # Canonical persistence pipeline

docs/specs/
├── entity-system.md            # Entity lifecycle, base classes, keys
├── access-control.md           # Policies, field access, route gates
├── api-layer.md                # JSON:API, serialization, query parsing
├── infrastructure.md           # DI, providers, middleware, testing
└── ... (35+ framework specs)
```

#### Waaseyaa Project Skeleton (`waaseyaa/waaseyaa`)

Inherited by new apps via `composer create-project`:

```
.claude/rules/
├── data-freshness.md           # Copied from framework (compact version)
├── shell-compatibility.md      # Copied from framework (compact version)
└── entity-storage-invariant.md # Copied from framework (compact version)

CLAUDE.md                       # Template with orchestration table stub
docs/specs/                     # Empty directory (apps populate this)
```

#### Application Repos (e.g., `goformx`, `claudriel`)

App-specific context layered on top of inherited rules:

```
.claude/rules/
├── data-freshness.md           # From skeleton (may customize examples)
├── shell-compatibility.md      # From skeleton (rarely modified)
├── entity-storage-invariant.md # From skeleton (rarely modified)
└── [app-specific-rules].md     # App-only rules (e.g., waaseyaa-invariants.md)

CLAUDE.md                       # Customized constitution with orchestration table
docs/specs/
├── [subsystem-a].md            # App-specific domain specs
├── [subsystem-b].md
└── ...
```

---

## Rule Categories

### Framework Rules (inherited via skeleton)

These rules apply universally to all Waaseyaa apps. They are copied into new apps from the published project skeleton package and rarely modified:

| Rule | Purpose | Modification Frequency |
|------|---------|----------------------|
| `data-freshness.md` | Prevent stale data in summaries | Never |
| `shell-compatibility.md` | Cross-platform bash safety | Never |
| `entity-storage-invariant.md` | Canonical persistence pipeline | Never |

### App Rules (created per-app)

Rules specific to an application's constraints. Examples:

| Rule | App | Purpose |
|------|-----|---------|
| `waaseyaa-invariants.md` | GoFormX | No Illuminate, no Laravel, Waaseyaa-only patterns |
| `claudriel-principles.md` | Claudriel | AI agent behavioral principles |
| `trust-north-star.md` | Claudriel | Data provenance and confidence rules |

---

## Spec Boundaries

### Framework Specs (live in `waaseyaa/framework`)

Specs about how the framework itself works. Applications reference these but don't copy them:

- Entity system, access control, API layer, routing, middleware
- These are authoritative for framework behavior
- Apps consult them when extending framework patterns

### App Specs (live in each app repo)

Specs about app-specific domain contracts:

- Cross-service auth, form lifecycle, user persistence (GoFormX)
- Ingestion pipeline, day brief assembly, chat protocol (Claudriel)
- These own the app's business logic documentation

### Boundary Test

Ask: "Would another Waaseyaa app need this spec?"
- **Yes** → Framework spec (lives in `waaseyaa/framework/docs/specs/`)
- **No** → App spec (lives in `<app>/docs/specs/`)

---

## MCP Federation (Future)

For deep framework spec access without copying, apps can use MCP servers:

```
App CLAUDE.md → references framework specs via skill triggers
  → Skill loads framework spec via MCP tool
  → Agent has full context without file duplication
```

This avoids spec drift between framework and apps. Currently, skills reference framework docs by convention (e.g., "consult Waaseyaa entity-system spec"). Future: dedicated MCP server exposes framework specs as searchable resources.

---

## Orchestration Table Pattern

Every `CLAUDE.md` should include an orchestration table mapping file patterns to skills and specs:

```markdown
| File Pattern | Skill | Spec |
|-------------|-------|------|
| `src/Entity/**` | `laravel-to-waaseyaa` | `docs/specs/entity-system.md` |
| `src/Controller/**` | `feature-dev` | `docs/specs/form-lifecycle.md` |
```

This enables AI assistants to load the right context before modifying code.

---

## Avoiding Duplication

| Problem | Solution |
|---------|----------|
| Same rule in framework + 5 apps | The published project skeleton distributes it at creation time; apps don't re-sync |
| Framework spec needed in app context | Skills reference framework specs by name; MCP federation (future) |
| App rule that should be framework rule | Promote to the framework repo and published project skeleton; update existing apps |
| Stale spec after refactor | Convention: update spec in same PR as code change |

---

## New App Checklist

When creating a new Waaseyaa application:

1. `composer create-project waaseyaa/waaseyaa my-app` — installs the published project skeleton package and inherits the rules + `CLAUDE.md` template
2. Customize `CLAUDE.md` — add app description, architecture, orchestration table
3. Add app-specific rules to `.claude/rules/` if needed
4. Create `docs/specs/` entries for each app domain
5. Populate the orchestration table as features are built
