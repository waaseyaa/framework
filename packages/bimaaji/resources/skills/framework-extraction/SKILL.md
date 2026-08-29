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
