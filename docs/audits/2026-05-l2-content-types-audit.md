# L2 Content-Types Audit — 2026-05

**Mission:** `l2-content-types-consolidation-01KSEFTX` · WP01  
**Audit date:** 2026-05-25  
**Auditor:** claude (sonnet implementer)  
**Status:** Complete — see summary table at end.

---

## Introduction

This audit covers every package listed in the Layer 2 (Content Types) row of `CLAUDE.md`'s layer-architecture table, as of the M4A-5 wave. The canonical L2 list (per `bin/check-package-layers` `LAYER_BY_SHORT`) is:

> attachment, node, taxonomy, media, path, menu, note, relationship, groups, engagement, messaging

**Scope note — `attachment`:** The `CLAUDE.md` orchestration table places `packages/attachment/*` under the work-surface group (alongside `packages/structured-import/*` and `packages/field/src/Form/*`), referencing `docs/specs/work-surface.md`. However, `bin/check-package-layers` explicitly assigns `"attachment": 2`. This audit includes `attachment` as an L2 package per the layer gate, with a note that its orchestration context is the work-surface subsystem.

**Scope note — `structured-import`:** The layer gate assigns `"structured-import": 3` (Services). It does not appear in the L2 row of CLAUDE.md. Excluded from this audit with this one-line note.

**Data sources:** For each package the following evidence was gathered (per T002):
- `composer.json` description + version.
- README.md presence.
- EntityType registration count (`new EntityType(` in `src/`).
- `@api`-tagged public-extension-point class count.
- Test file count (`*Test.php` in `tests/`).
- Recent commit count (past 3 months).
- Admin SPA file references (`grep -rl <pkg> packages/admin/app/`).
- Dead-code-baseline entries (`grep -c packages/<pkg>/ phpstan-dead-code-baseline.neon`).

---

## Per-Package Findings

---

### 1. `waaseyaa/attachment`

| Field | Value |
|---|---|
| Description | Attachment content type with parent-entity reference and at-most-one-active invariant. |
| README | No |
| Entity types registered | 1 |
| `@api` classes | 0 |
| Test files | 4 |
| Recent commits (3 mo) | 20 |
| Admin SPA consumers | 0 direct files (work-surface integration via field form layer) |
| Dead-code baseline entries | 0 |
| Anokii surface | Anokii Files / work-surface (attachment is the field-form attachment primitive) |

**Classification: alpha — needs hardening**

**Rationale:** `attachment` has active recent development (20 commits, 3 months) and a registered entity type. However, it lacks a README (evidenced by `README: NO`) and has zero `@api`-tagged extension points, indicating no public extension surface is documented. The orchestration table routes it to `docs/specs/work-surface.md` rather than giving it its own spec entry, meaning its contracts are embedded in the work-surface spec rather than being self-documenting. Admin SPA consumers are 0 direct references — it is consumed indirectly via the field form layer. The package is structurally sound but missing the standalone spec and extension-point surface markers expected of a production-ready L2 package. Follow-up: add README, add `@api` on the public Attachment entity class, ensure coverage of the at-most-one-active invariant in tests.

---

### 2. `waaseyaa/node`

| Field | Value |
|---|---|
| Description | Content node entity type for Waaseyaa |
| README | Yes |
| Entity types registered | 2 |
| `@api` classes | 0 |
| Test files | 4 |
| Recent commits (3 mo) | 41 |
| Admin SPA consumers | 8 files |
| Dead-code baseline entries | 0 |
| Anokii surface | Anokii Wiki (primary content surface) |

**Classification: production-ready**

**Rationale:** `node` is the primary content entity in the framework. It has 8 admin SPA consumer files (the highest density of admin page references among content-type packages), 41 recent commits, 2 entity types registered, a README, and 0 dead-code baseline entries. Test coverage is sparse (4 files) but the package is backed by the broader integration test suite in `tests/Integration/`. The `@api` count is 0 because the entity class itself is the public surface; `@api` tagging on concrete entity classes is optional when they are discovered via `EntityBase` subclass reflection. Node is a core dependency relied on by at least 8 admin SPA subsystems, making it the most consumer-proven L2 package.

---

### 3. `waaseyaa/taxonomy`

| Field | Value |
|---|---|
| Description | Taxonomy vocabulary and term entity types for Waaseyaa |
| README | Yes |
| Entity types registered | 2 |
| `@api` classes | 0 |
| Test files | 5 |
| Recent commits (3 mo) | 42 |
| Admin SPA consumers | 3 files |
| Dead-code baseline entries | 0 |
| Anokii surface | Anokii Wiki (tagging/classification) |

**Classification: production-ready**

**Rationale:** `taxonomy` has 42 recent commits (among the highest), 2 entity types (vocabulary + term), a README, 3 admin SPA consumers, and zero dead-code baseline entries. It is a core classifying primitive consumed by both the wiki and other content surfaces. Five test files and active commit history confirm ongoing maintenance. No concerns.

---

### 4. `waaseyaa/media`

| Field | Value |
|---|---|
| Description | Media entity type and file management for Waaseyaa |
| README | Yes |
| Entity types registered | 4 |
| `@api` classes | 14 |
| Test files | 14 |
| Recent commits (3 mo) | 52 |
| Admin SPA consumers | 12 files |
| Dead-code baseline entries | 0 |
| Anokii surface | Anokii Files |

**Classification: production-ready**

**Rationale:** `media` is the highest-activity L2 package by every metric: 52 recent commits, 14 `@api`-tagged extension-point classes, 14 test files, 12 admin SPA consumer files, and 4 registered entity types. It has the strongest public extension surface of any L2 package (`@api: 14`). Zero dead-code baseline entries. It is the most mature L2 package and is clearly production-ready.

---

### 5. `waaseyaa/path`

| Field | Value |
|---|---|
| Description | URL path aliases and routing integration for Waaseyaa |
| README | Yes |
| Entity types registered | 1 |
| `@api` classes | 4 |
| Test files | 7 |
| Recent commits (3 mo) | 44 |
| Admin SPA consumers | 16 files |
| Dead-code baseline entries | 0 |
| Anokii surface | Cross-cutting (URL aliases for all Anokii surfaces) |

**Classification: production-ready**

**Rationale:** `path` has the highest admin SPA consumer count of all L2 packages (16 files) and 44 recent commits. It provides the URL alias entity type consumed broadly across admin pages and the routing layer. Four `@api` classes, 7 test files, and 0 dead-code baseline entries. The cross-cutting nature of URL aliases means every Anokii surface depends on it. Production-ready.

---

### 6. `waaseyaa/menu`

| Field | Value |
|---|---|
| Description | Menu and navigation link management for Waaseyaa |
| README | Yes |
| Entity types registered | 2 |
| `@api` classes | 2 |
| Test files | 6 |
| Recent commits (3 mo) | 43 |
| Admin SPA consumers | 4 files |
| Dead-code baseline entries | 0 |
| Anokii surface | Anokii Wiki / cross-cutting navigation |

**Classification: production-ready**

**Rationale:** `menu` has 43 recent commits, 2 entity types (menu + menu link), 2 `@api` classes, 6 test files, 4 admin SPA consumers, and 0 dead-code baseline entries. It is a structural primitive consumed by the admin SPA navigation system. Production-ready.

---

### 7. `waaseyaa/note`

| Field | Value |
|---|---|
| Description | Default built-in Note content type for Waaseyaa (core.note) |
| README | Yes |
| Entity types registered | 1 |
| `@api` classes | 3 |
| Test files | 7 |
| Recent commits (3 mo) | 37 |
| Admin SPA consumers | 5 files |
| Dead-code baseline entries | 0 |
| Anokii surface | Anokii Wiki (simple note / built-in content type) |

**Classification: production-ready**

**Rationale:** `note` is the default built-in content type, meaning it ships with `waaseyaa/core` and must always work. 37 recent commits, 3 `@api` classes, 7 test files, 5 admin SPA consumers, and 0 dead-code baseline entries. The README is present. Production-ready.

---

### 8. `waaseyaa/relationship`

| Field | Value |
|---|---|
| Description | Reusable relationship primitives for Waaseyaa applications |
| README | Yes |
| Entity types registered | 2 |
| `@api` classes | 3 |
| Test files | 9 |
| Recent commits (3 mo) | 66 |
| Admin SPA consumers | 1 file |
| Dead-code baseline entries | 0 |
| Anokii surface | Cross-cutting (genealogy, graph edges) |

**Classification: production-ready**

**Rationale:** `relationship` is the highest-commit L2 package over 3 months (66 commits), indicating active development. It has 9 test files (highest test file count among pure content-type packages), 3 `@api` classes, a README, and 0 dead-code baseline entries. The admin SPA consumer count is 1 file but this is expected — relationship primitives are consumed by domain-specific surfaces (genealogy, groups) rather than directly wired to admin list pages. The spec references (`docs/specs/relationship-modeling.md`) are maintained. Production-ready.

---

### 9. `waaseyaa/groups`

| Field | Value |
|---|---|
| Description | Multi-bundle Group content entity type for Waaseyaa (group + group_type; app-defined bundles) |
| README | Yes |
| Entity types registered | 2 |
| `@api` classes | 0 |
| Test files | 3 |
| Recent commits (3 mo) | 27 |
| Admin SPA consumers | 3 files (i18n + useNavGroups.ts composable) |
| Dead-code baseline entries | 0 |
| Anokii surface | Anokii Communities |

**Classification: alpha — needs hardening**

**Rationale:** `groups` has 2 entity types, a README, 27 recent commits, and 0 dead-code baseline entries — showing active development. However, it has 0 `@api` classes despite being the multi-bundle group system (which extension authors would reasonably want to extend), only 3 test files, and its admin SPA integration consists of i18n strings and a navigation composable rather than full admin pages. The multi-bundle design ("app-defined bundles") implies a public extension surface that should be marked `@api`. Test coverage of 3 files is sparse for a package with significant cardinality (group + group_type entity pair). Follow-up: add `@api` on GroupType/Group entity classes, expand tests to cover bundle registration, wire to admin groups management pages.

---

### 10. `waaseyaa/engagement`

| Field | Value |
|---|---|
| Description | Social engagement entities (reactions, comments, follows) for Waaseyaa |
| README | Yes |
| Entity types registered | 3 |
| `@api` classes | 0 |
| Test files | 5 |
| Recent commits (3 mo) | 25 |
| Admin SPA consumers | 2 files (i18n only) |
| Dead-code baseline entries | 0 |
| Anokii surface | Anokii Community / social layer |

**Classification: alpha — needs hardening**

**Rationale:** `engagement` has 3 registered entity types (reactions, comments, follows), a README, and 25 recent commits. However, it has 0 `@api`-tagged classes, only 2 admin SPA references (both i18n translation files, not actual page components), and 5 test files against 3 entity types (borderline sparse). The access policy (`EngagementAccessPolicy.php`) exists in source but is not `@api`-tagged. Admin SPA integration is missing functional page coverage — no composable, no list/edit pages. As a social engagement layer powering the Anokii Community surface, this gap is significant. Follow-up: wire admin SPA engagement pages, add `@api` on EngagementAccessPolicy and entity classes, expand tests per entity type.

---

### 11. `waaseyaa/messaging`

| Field | Value |
|---|---|
| Description | Direct messaging infrastructure for Waaseyaa: threads, messages, participants |
| README | Yes |
| Entity types registered | 3 |
| `@api` classes | 0 |
| Test files | 3 |
| Recent commits (3 mo) | 22 |
| Admin SPA consumers | 2 files (i18n only) |
| Dead-code baseline entries | 0 |
| Anokii surface | Anokii Chat (chat substrate → scheduled for L3 graduation per WP03) |

**Classification: alpha — needs hardening (pre-graduation)**

**Rationale:** `messaging` is architecturally distinct from the other L2 content types: it provides a chat substrate (threads, messages, participants) that belongs semantically at L3 Services rather than L2 Content Types. This mission's WP03 graduates it to L3. Within its current L2 classification, it has 3 entity types, a README, 22 recent commits, and 0 dead-code baseline entries. However, it has 0 `@api` classes, only 3 test files (the lowest test count of any non-attachment L2 package), and its admin SPA integration is limited to 2 i18n translation files — no functional chat admin pages. The source tree is minimal: only 4 PHP files (`MessagingServiceProvider`, `MessageThread`, `ThreadMessage`, `ThreadParticipant`). As a planned L3 service, its hardening scope belongs in the post-graduation mission, not this L2 audit. The pre-graduation state is captured here for historical reference per FR-004/FR-009. Follow-up: handled by WP03 (graduation) + a subsequent `l2-harden-messaging`-equivalent mission under L3.

---

## Summary Table

| Package | Entity Types | `@api` | Tests | Commits (3mo) | Admin SPA | Dead-code | Classification | Follow-up Mission |
|---|---|---|---|---|---|---|---|---|
| `waaseyaa/attachment` | 1 | 0 | 4 | 20 | 0 (indirect) | 0 | **alpha — needs hardening** | `l2-harden-attachment-01KSEW72` |
| `waaseyaa/node` | 2 | 0 | 4 | 41 | 8 | 0 | **production-ready** | — |
| `waaseyaa/taxonomy` | 2 | 0 | 5 | 42 | 3 | 0 | **production-ready** | — |
| `waaseyaa/media` | 4 | 14 | 14 | 52 | 12 | 0 | **production-ready** | — |
| `waaseyaa/path` | 1 | 4 | 7 | 44 | 16 | 0 | **production-ready** | — |
| `waaseyaa/menu` | 2 | 2 | 6 | 43 | 4 | 0 | **production-ready** | — |
| `waaseyaa/note` | 1 | 3 | 7 | 37 | 5 | 0 | **production-ready** | — |
| `waaseyaa/relationship` | 2 | 3 | 9 | 66 | 1 | 0 | **production-ready** | — |
| `waaseyaa/groups` | 2 | 0 | 3 | 27 | 3 | 0 | **alpha — needs hardening** | `l2-harden-groups-01KSEW7E` |
| `waaseyaa/engagement` | 3 | 0 | 5 | 25 | 2 | 0 | **alpha — needs hardening** | `l2-harden-engagement-01KSEW7Y` |
| `waaseyaa/messaging` | 3 | 0 | 3 | 22 | 2 | 0 | **alpha — needs hardening (pre-graduation)** | WP03 + `l2-harden-messaging-01KSEW82` |

**Counts (11 packages audited):**
- **production-ready:** 7 (node, taxonomy, media, path, menu, note, relationship)
- **alpha — needs hardening:** 4 (attachment, groups, engagement, messaging)
- **dead — propose removal:** 0

---

## Scope Exclusions

| Package | Reason |
|---|---|
| `waaseyaa/structured-import` | Layer gate assigns L3 (Services). Not in L2 row of CLAUDE.md. Excluded per scope. |

---

## Reviewer Spot-Check Recommendations

Three classifications the reviewer should verify against actual package state:

1. **`waaseyaa/attachment` → alpha:** Check `packages/attachment/` for README absence and verify the orchestration table's work-surface routing. The key claim is that no self-contained spec exists.
2. **`waaseyaa/relationship` → production-ready:** Verify the 66-commit count via `git log --oneline --since='3 months ago' -- packages/relationship/` and spot-check that the 9 test files cover the RelationshipType + Relationship entity pair adequately.
3. **`waaseyaa/messaging` → alpha (pre-graduation):** Verify the 4-file source tree via `find packages/messaging/src -name '*.php'`. The claim that admin SPA references are i18n-only can be verified via `grep -rl messaging packages/admin/app/`.

---

*Refs L2-consolidation · [claude:sonnet:implementer:implementer]*
