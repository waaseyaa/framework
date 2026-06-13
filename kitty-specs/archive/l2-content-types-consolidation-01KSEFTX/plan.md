# Implementation Plan: L2 Content-Types Consolidation

**Mission:** `l2-content-types-consolidation-01KSEFTX` — see `spec.md`.
**Pattern reference:** M5A `ai-observability-dashboard-01KSE9BX` for spec/plan/tasks shape. WP01 + WP02 mirror the audit-then-file-follow-ups shape of the API parity-matrix mission. WP03 is a metadata + spec edit; it has the smallest physical diff but the most architectural weight (the layer move).
**Three WPs, mostly sequential:** WP02 (filing follow-up mission scaffolds) depends on WP01 (the audit that classifies each package). WP03 (messaging → L3) is independent of WP01 / WP02 by source coupling and MAY run in parallel — but the conservative ordering is WP01 → WP02 → WP03 so the audit captures messaging's pre-graduation state for the historical record.

## §1 — Audit document structure (WP01)

`docs/audits/2026-05-l2-content-types-audit.md` is the deliverable. The file structure:

```
# L2 Content-Types Audit — 2026-05

**Audit date:** YYYY-MM-DD
**Mission:** l2-content-types-consolidation-01KSEFTX
**Auditor:** <implementer agent name>
**Sources consulted:** CLAUDE.md (layer table + orchestration table), packages/<name>/README.md, packages/<name>/src tree listings, packages/admin/app/ for consumer surfaces, packages/api/src/Controller/ for API exposure, gap-matrix.md, alpha-to-beta-plan.md, inventory.md.

## Scope

This audit covers every package listed in the L2 row of CLAUDE.md's layer-architecture table at the audit date.

If `attachment` and `structured-import` appear in the L2 row, they are included. If they appear in the orchestration table's work-surface group instead, they are excluded from this audit and the exclusion is documented in this section.

## Methodology

1. For each L2 package, read `packages/<name>/composer.json` + `README.md` to capture source state.
2. List entity types registered (look for `EntityType` constructor calls; check `*ServiceProvider.php`).
3. List public API classes (`@api` PHPDoc tag in `src/`).
4. List tests present (`packages/<name>/tests/`).
5. Cross-reference admin SPA consumers (`packages/admin/app/pages/` + `composables/`).
6. Cross-reference JSON:API controllers (`packages/api/src/Controller/`).
7. Cross-reference Anokii productivity surfaces (Anokii Wiki, Anokii Files, Anokii Chat — per the parallel anokii-distribution-scaffold work).
8. Classify per the rubric in spec.md "Scope → WP01 → Classification".
9. Write the rationale paragraph. If the classification is "production-ready", the rationale documents the evidence (consumers, tests, recent activity). If "alpha — needs hardening", the rationale identifies the gaps. If "dead — propose removal", the rationale identifies the absence (no consumers + no tests + no clear future role).

## Findings

<one section per package>

### waaseyaa/node

- **Source state:** ...
- **Consumer surfaces:** ...
- **Recent activity:** ...
- **Anokii productivity-surface mapping:** ...
- **Classification:** production-ready | alpha — needs hardening | dead — propose removal
- **Decision rationale:** ...
- **Follow-up mission:** <slug or "none">

### waaseyaa/taxonomy
...

(repeat for: media, path, menu, note, relationship, groups, engagement, messaging, attachment, structured-import if applicable)

## Summary table

| Package | Classification | Follow-up mission |
|---|---|---|
| waaseyaa/node | production-ready | none |
| waaseyaa/taxonomy | ... | ... |
| ... | | |

## Notes

- The classification of `messaging` reflects its pre-WP03 state (L2 content-type framing). WP03 of this mission graduates it to L3 (Services). Future audits should reflect the new layer.
- This audit is a snapshot. Future state changes (new tests, new consumers, new specs) supersede individual classifications; re-audit periodically.
```

The audit doc lives in `docs/audits/`, not `docs/specs/`. It is a snapshot, not a long-lived spec.

## §2 — Follow-up mission scaffolds (WP02)

For each L2 package classified as **alpha — needs hardening** or **dead — propose removal**, file a fresh mission scaffold:

```
spec-kitty specify l2-harden-<package>-<short-ULID>
```

or

```
spec-kitty specify l2-remove-<package>-<short-ULID>
```

The new mission's spec.md is a stub. The implementer of this mission writes only the stub; the new mission's full spec/plan/tasks happens in its own implement-review loop.

### Stub shape for `l2-harden-<package>-*`

```
# L2 Package Hardening: waaseyaa/<package>

**Mission:** `l2-harden-<package>-<ULID>`
**Status:** Stub (filed by parent mission l2-content-types-consolidation-01KSEFTX WP02)
**Target branch:** main
**Parent mission:** l2-content-types-consolidation-01KSEFTX (see audit at docs/audits/2026-05-l2-content-types-audit.md for classification context)

## Hardening scope

<paste the alpha-classification rationale from the audit here>

## Suggested WPs

- WP01 — <e.g., add EntityType registration test>
- WP02 — <e.g., wire to admin SPA queue page>
- WP03 — <e.g., extract dead-code baseline entries>

## Pre-resolved decisions

- Package stays at L2 (per the parent mission's pre-resolved decision: no metapackage consolidation).
- Follow the M5A cross-layer wiring pattern for any new API surface.

## To be specified in implement-review

The full requirements list (FR/NFR/C), acceptance criteria, and per-WP execution plans land in this mission's own /spec-kitty.plan + /spec-kitty.tasks invocations.
```

### Stub shape for `l2-remove-<package>-*`

```
# L2 Package Removal Proposal: waaseyaa/<package>

**Mission:** `l2-remove-<package>-<ULID>`
**Status:** Stub (filed by parent mission l2-content-types-consolidation-01KSEFTX WP02)
**Target branch:** main
**Parent mission:** l2-content-types-consolidation-01KSEFTX (see audit at docs/audits/2026-05-l2-content-types-audit.md for classification context)

## Removal rationale

<paste the dead-classification rationale from the audit here>

## Charter-bearing context

- Decision-preference order (charter): preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.
- Removal must not break any consumer. The audit found <N> consumer surfaces; verify each before removal.
- Removal must not regress any codified-policy gate. `bin/check-package-layers`, `bin/check-composer-policy`, `bin/check-dead-code` must remain green.

## Suggested WPs

- WP01 — Pre-removal consumer audit + migration notes for any found consumers
- WP02 — Remove the package + update CLAUDE.md layer table + update composer manifests + update affected specs
- WP03 — Acceptance gate run + CHANGELOG entry

## To be specified in implement-review

The full requirements list (FR/NFR/C), acceptance criteria, and per-WP execution plans land in this mission's own /spec-kitty.plan + /spec-kitty.tasks invocations.
```

After all follow-up missions are filed, replace the **Out-of-band** placeholder in `../spec.md` with the real list.

## §3 — Messaging → L3 graduation (WP03)

Architectural change. Five file touchpoints:

### §3.1 — CLAUDE.md layer-table update

Read CLAUDE.md's layer-architecture table. The L2 row currently reads:

```
| 2 | Content Types | node, taxonomy, media, path, menu, note, relationship, groups, engagement, messaging |
```

Change to:

```
| 2 | Content Types | node, taxonomy, media, path, menu, note, relationship, groups, engagement |
```

The L3 row currently reads:

```
| 3 | Services | workflows, search, seo, notification, billing, github, migration, northcloud, listing |
```

Change to:

```
| 3 | Services | workflows, search, seo, notification, billing, github, migration, northcloud, listing, messaging |
```

(Append `messaging` after `listing` per the existing convention which is mostly insertion-order, not strict alphabetical. If the row IS alphabetical at edit time, insert in alphabetical position.)

Also update the CLAUDE.md orchestration table row for messaging. The current row is likely absent or points at a README; after WP03, it should read:

```
| `packages/messaging/*` | — | `docs/specs/messaging.md` |
```

### §3.2 — `bin/check-package-layers` update

Read `bin/check-package-layers`. The script carries an in-code layer map (a PHP/shell associative array mapping package name → layer number). Update so `messaging` is at layer 3, not layer 2.

Run the script. If it errors with a layer violation, that means an L1/L2 package requires `waaseyaa/messaging` — which is now a violation (messaging is L3, can't be required from L2-). Read the error, identify the package, and document the issue in WP03's report. Resolution depends on the consumer:

- If the consumer is L4+ (`api`, `admin`, etc.), no action — the gate is green because L4 → L3 is downward.
- If the consumer is L2 (some content-type package requires messaging), the dependency was a smell and the fix is to remove the require or move the consumer to L3. Either is a follow-up; document and either resolve in-mission or file a follow-up.

### §3.3 — `packages/messaging/README.md` update

Current README opens with:

```
# waaseyaa/messaging

**Layer 2 — Content Types**

Direct messaging infrastructure for Waaseyaa: threads, messages, participants.
...
```

Change `**Layer 2 — Content Types**` to `**Layer 3 — Services**`.

Append (after the existing summary paragraph) a "Why L3" paragraph:

```
## Why L3 (Services, not Content Types)

Messaging is the substrate for the framework's chat surface (gap-matrix capability C-1). Chat is a service abstraction, not a content type: it carries per-thread access policies, read-receipt semantics, real-time broadcast routing, and presence concerns that L2 content-type infrastructure does not provide. The L3 home (graduated by mission `l2-content-types-consolidation-01KSEFTX` WP03) supports future real-time broadcast + presence add-ons (e.g., the Anokii Chat admin surface), which are scheduled as separate follow-up missions.
```

### §3.4 — `docs/specs/messaging.md` (new file)

Create with this structure:

```
# Messaging — chat substrate (L3 Services)

<!-- Spec reviewed YYYY-MM-DD - l2-content-types-consolidation-01KSEFTX - WP03 - messaging L3 graduation -->

## Layer + role

`waaseyaa/messaging` lives at Layer 3 (Services) per the post-`l2-content-types-consolidation-01KSEFTX` layer map. It provides direct-messaging infrastructure — threads, messages, participants, per-thread access policies — as a service abstraction. Distributions consuming the framework's chat capability build on top of this substrate (the Anokii distribution's chat surface is the first consumer).

## Data model

- `MessageThread` — conversation container.
- `ThreadParticipant` — per-account membership + read state (last_read_at).
- `ThreadMessage` — the individual message.

## Per-thread access policy

Only participants can read or post in a thread. Unread counts derive from per-participant `last_read_at` rather than a separate read-status table — this is the canonical pattern; consumers MUST NOT introduce a parallel read-status mechanism.

## Cross-layer integration

- L4 (`packages/api`): JSON:API endpoints for thread CRUD + message posting register in `BuiltinRouteRegistrar`, controllers in `packages/api/src/Controller/`. Service-provider wiring in `ApiServiceProvider::httpDomainRouters()` follows the M4B pattern (`QueueController` / `QueueAdminApiRouter` shape).
- L0 (`packages/foundation/src/Http/Broadcasting/`): real-time broadcast hooks for new-message notifications. Best-effort side-effect pattern: try/catch around the broadcast, log via `LoggerInterface`, never crash the primary write.

## Out of scope (this spec)

- Real-time presence (online/away/typing indicators). Separate follow-up mission.
- Read-receipt UI surfaces. Separate follow-up mission.
- Federated XMPP / Matrix bridge. Separate follow-up mission.
- Group chat / channel semantics beyond the existing `MessageThread` + `ThreadParticipant` model. Future enhancement.

## Cross-references

- `packages/messaging/README.md` — package entrypoint.
- `gap-matrix.md` capability **C-1** — chat surface (the strategic role this package plays).
- The future `chat-surface-<ULID>` mission (filed separately when the Anokii Chat work begins).
```

### §3.5 — `CHANGELOG.md`

Under `[Unreleased]`:

- `### Changed` — new entry: `- Graduated waaseyaa/messaging from L2 (Content Types) to L3 (Services). The package is now positioned as the chat-service substrate; consumers may continue to require it unchanged (L4+ → L3 is downward-clean).`
- `### Added` — new entry: `- L2 content-types audit at docs/audits/2026-05-l2-content-types-audit.md. Per-package classifications + follow-up mission slugs.`

## Verification gate (each WP, in the mission worktree)

WP01:
1. The audit document covers every L2 package per the layer table.
2. Each row has a populated classification + rationale paragraph (C-003).
3. Reviewer cross-checks at least three classifications against the actual package state (NFR-003).

WP02:
1. Every alpha-classified package has a `kitty-specs/l2-harden-*/spec.md`.
2. Every dead-classified package has a `kitty-specs/l2-remove-*/spec.md`.
3. The audit's "Follow-up mission" column is populated for every needs-action row.
4. `../spec.md` Out-of-band section is post-edited with the real list.

WP03:
1. `git diff CLAUDE.md` — L2 row no longer contains `messaging`; L3 row contains `messaging`; orchestration table updated.
2. `git diff bin/check-package-layers` — layer map updated; gate runs green.
3. `git diff packages/messaging/README.md` — `**Layer 3 — Services**` + Why-L3 paragraph.
4. `git diff packages/messaging/src packages/messaging/tests` — empty (C-001 + NFR-001).
5. `docs/specs/messaging.md` exists + stamped.
6. `git diff CHANGELOG.md` — Changed + Added entries.
7. `bin/check-package-layers` green.

Mission close:
1. All codified gates green.
2. `vendor/bin/phpunit` green (no code changes; should be unaffected).
3. `bin/check-package-layers` green against the new layer map.

## Reviewer focus

- (a) **NFR-003 — audit reproducibility.** Cross-check at least three classifications against the package state. Reject classifications without rationale.
- (b) **C-003 — every classification has rationale.** Skim the audit; flag any row where the rationale is generic / boilerplate.
- (c) **C-001 — no L2 source code changes.** `git diff packages/{node,taxonomy,media,path,menu,note,relationship,groups,engagement,attachment}/{src,tests}` MUST be empty. The only source-tree change permitted is `packages/messaging/README.md` (metadata) — not `packages/messaging/src` or `tests`.
- (d) **C-004 — only messaging changes layer.** Diff the layer table; only one row delta on each side (one removed from L2, one added to L3) and the same package name on both sides.
- (e) **FR-005 — layer gate green.** Run `bin/check-package-layers` and paste the result.
- (f) **C-005 — follow-ups are scaffolds, not real missions.** Each follow-up scaffold's spec.md should be a stub (one section + suggested WPs), not a fully-fleshed spec.
- (g) **Out-of-band placeholder replaced.** Post-merge, `../spec.md`'s Out-of-band section MUST list real follow-up slugs, not the example placeholder.
