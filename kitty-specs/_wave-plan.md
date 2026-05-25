# Spec Kitty Wave Plan — Waaseyaa → Beta + Anokii v0.1

**Generated:** 2026-05-24 (spec-production session)
**Authoritative until:** next spec-production run (this doc is a session artifact summarising the missions filed in one batch; the missions themselves remain authoritative even after this doc is superseded).
**Read order:** This doc → `.kittify/charter/charter.md` → individual mission `spec.md`/`plan.md`/`tasks.md` files.

Spec Kitty's mission state (under `kitty-specs/`) is the canonical execution map. This document layers wave organisation, parallel-fanout guidance, and dependency edges on top of it for downstream Sonnet implementers and Opus reviewers.

---

## Wave 0 — Foundation (sequential, blocks everything else)

| Mission | Slug | What it does | Blocks |
|---|---|---|---|
| Charter amendment | `charter-amendment-anokii-track-01KSEFE0` | Adds `## Framework vs Distribution Architecture` section and directives DIR-004..DIR-008 (OCAP-by-architecture invariant, two-axis storage invariant, codified gates as trust substrate, Nuxt SPA bet, GPL-2.0-or-later license commitment) to `.kittify/charter/charter.md`. Documentation-only; one WP; one file changed. | All Wave 1+ missions reference DIR-004..DIR-008 by ID. |

Land this BEFORE Wave 1 missions merge.

---

## Wave 1 — Flagship + Distribution Standup (parallel after Wave 0)

| Mission | Slug | What it does |
|---|---|---|
| M-A5 per-record AI access flagship | `per-record-ai-access-flagship-01KSEFT5` | Wires AccessChecker + `_account` into every `AgentToolInterface::execute()`; wires `FieldAccessPolicyInterface` into the MCP entity serializer; adds per-file AI-access toggle field on `media`/`attachment`. Three parallel WPs. Highest-leverage mission across the whole effort — the defining product claim (gap-matrix A5, alpha-to-beta-plan §1 item 1). |
| Anokii distribution scaffold | `anokii-distribution-scaffold-01KSEFT7` | Stands up the `anokii/anokii` repo with composer.json consuming Waaseyaa metapackages, `.kittify init`, Anokii distribution charter (AODA-AA + offline-first + Indigenous-language pipeline as design constraints), deployer recipe inheritance, branded UX baseline (deep-teal palette), AND ten v0.1 surface draft specs as `artifacts/v0.1/*.spec.md` (Drive, Form builder, Tasks, Data Rooms, Docs, Sheets, Co-Intelligence, Admin Centre) and `artifacts/cross-cutting/*.spec.md` (offline-first, AODA-AA baseline). The artifact drafts are NOT spec-kitty missions in this repo — they get re-filed in the Anokii repo by a follow-up agent. |

These two land in parallel. Neither depends on the other.

---

## Wave 2 — Framework Substrate (parallel within wave, after Wave 1 lands)

Parallel-fanout-capable cluster. Most missions have no cross-dependencies within the wave; where dependencies exist they are noted.

### Wave 2a — M5 cluster (mirror M5A's CodifiedContext L5/L6→L4 pattern)

| Mission | Slug | Depends on |
|---|---|---|
| M5B AI observability recent runs | `ai-observability-recent-runs-m5b-01KSEFT9` | M5A merged; `per-record-ai-access-flagship-*/WP01` for the replay endpoint AccessChecker integration |
| M5C MCP endpoint admin (read-only) | `mcp-endpoint-admin-m5c-01KSEFTB` | `per-record-ai-access-flagship-*/WP02` for MCP serializer FieldAccessPolicy wiring |
| M5D Mercure broadcast monitor | `mercure-broadcast-monitor-m5d-01KSEFTD` | none cross-mission |

All three mirror M5A's two-WP backend+frontend shape. Same Pattern-reference line in each spec.

### Wave 2b — New substrate (the four primitives v0.1 surfaces compose on)

| Mission | Slug | Depends on |
|---|---|---|
| OCAP audit log substrate | `ocap-audit-log-substrate-01KSEFTF` | none; coordinates with empty-package-decisions for `analytics` → `audit` rename |
| Classification + retention engine | `classification-retention-engine-01KSEFTH` | OCAP-audit-log merged |
| Versioned-blob media abstraction | `versioned-blob-media-abstraction-01KSEFTJ` | none; preserves DIR-005 two-axis shape |
| Offline-first sync substrate | `offline-first-sync-substrate-01KSEFTM` | OCAP-audit-log (offline operation audit) + Classification (classification-aware conflict resolution) |

Offline-first sync is the substrate Anokii's offline-first cross-cutting depends on.

### Wave 2c — Substrate hardening / consolidation

| Mission | Slug | Depends on |
|---|---|---|
| OIDC flows completion | `oidc-flows-completion-01KSEFTP` | none. Unblocks "every other Tsen'awt component" per assumptions.md |
| Inertia demotion / Nuxt standardisation | `inertia-demotion-nuxt-standardisation-01KSEFTS` | Wave 0 charter merged (ratifies DIR-007) |
| API surface consolidation (JSON:API primary) | `api-surface-consolidation-jsonapi-primary-01KSEFTV` | none |
| L2 content-types consolidation | `l2-content-types-consolidation-01KSEFTX` | none |

### Wave 2d — Framework cleanup

| Mission | Slug | Depends on |
|---|---|---|
| Genealogy package extraction | `genealogy-package-extraction-01KSEFTZ` | Wave 0 charter merged (framework-vs-distribution boundary codified) |
| `database-legacy` retirement | `database-legacy-retirement-01KSEFV2` | none; honours DIR-003 Greenfield Removal Policy |
| Empty package decisions (analytics/billing/ai-schema) | `empty-package-decisions-analytics-billing-aischema-01KSEFV4` | Coordinates with OCAP-audit-log (analytics → audit rename) |

### Wave 2e — Succession framework

| Mission | Slug | Depends on |
|---|---|---|
| Succession framework Tier 1 publishing | `succession-framework-tier1-publishing-01KSEFV6` | Wave 0 charter merged (ratifies DIR-006 codified-gates trust substrate) |

Lands `MAINTAINERS.md`, `SUCCESSION.md`, Packagist namespace trustee, Nation-hosted mirror. THE single most procurement-relevant near-term move per assumptions.md §4. Can land in parallel with any other Wave 2 mission.

---

## Wave 3+ — Anokii v0.1 productivity surfaces (DEFERRED to Anokii repo)

These are NOT missions in this repo. They live as draft specs (`artifacts/v0.1/*.spec.md` + `artifacts/cross-cutting/*.spec.md`) inside the `anokii-distribution-scaffold-01KSEFT7` mission. A follow-up agent — working in the new Anokii repo after the scaffold mission completes — re-files each as a proper spec-kitty mission in `anokii/.kittify/`.

| Anokii v0.1 surface | Pre-resolved decisions captured in draft |
|---|---|
| Governed Drive | Folders + ACLs + classification + share-link revocation + Nation Drives |
| Form builder | Multi-submission-merge offline default; LWW opt-in for admin forms |
| Tasks | Kanban + assignment + classification labels |
| Data Rooms | Consent state machine + revocation |
| Governed Docs | Save-conflict + diff |
| Governed Sheets | Grid + formulas + cell-level access (first cut) |
| Co-Intelligence + AI Workspaces | Governed-data tool families per E1, AI Workspaces per E2 |
| Admin Centre v0.1 | Classification policy editor, retention policy editor, user mgmt, tenant mgmt |

| Cross-cutting | Pre-resolved decisions captured in draft |
|---|---|
| Offline-first | Dexie + Workbox + FSM sync engine on framework's two-axis revisions (depends on Wave 2b offline-first sync substrate) |
| AODA Level AA | axe-core + eslint-plugin-vuejs-accessibility + Lighthouse CI gates; 13-component baseline pass; per-surface a11y constraints baked in; governance-aware accessibility (live-region announcements for access-denied messages) |

---

## Wave N+ — v1.0 communication surfaces (DEFERRED, draft as artifacts)

Future Anokii roadmap. Draft specs in `artifacts/v1.0/`. Re-file in Anokii repo when v0.1 ships and v1.0 scope opens.

Surfaces: Slides, Calendar, Chat (extending messaging — note that the L2 consolidation mission graduates `messaging` to L3 chat service, unblocking this), Email, Meet, Templates, Vault, Reporting, Community Intel.

Cross-cutting: Indigenous-language UI translation pipeline (extraction → translation_string entity → contributor dashboard → translation_review workflow → glossary entity → per-Nation override; pilot Sagamok-first, Sheguiandah second). Licensing / multi-tenant. Nation↔Nation federation. k8s-class cluster ops. Mobile PWA.

---

## Parallel-fanout guide (for downstream multi-agent runners)

Recommended dispatch pattern for the implement-review loop:

- **Single-thread Wave 0.** One Sonnet implements `charter-amendment-anokii-track-01KSEFE0`; one Opus reviews. Block on merge.
- **Two-thread Wave 1.** Sonnet-A implements `per-record-ai-access-flagship-01KSEFT5` (3 WPs internally parallel — A can fan out to 3 sub-sonnets); Sonnet-B implements `anokii-distribution-scaffold-01KSEFT7` (4 WPs internally parallel after WP01). One Opus reviews each mission's WPs as they reach `for_review`.
- **N-thread Wave 2.** All Wave 2 missions are independently merge-able subject to the dependency edges in the tables above. The natural fanout: assign one Sonnet pair (implement + review) per cluster (2a, 2b, 2c, 2d, 2e), or one per mission if context allows. The OCAP-audit-log mission should land before classification + offline-sync (intra-2b ordering).
- **Wave 3 starts in the Anokii repo** by an agent invoked there. The Anokii scaffold mission's `artifacts/` directory contains the draft specs; the Anokii-repo agent runs `spec-kitty specify <surface>` and pastes content.

## Agent-pairing convention

Per `.kittify/charter/charter.md` Review Policy (alpha, solo-author + AI-driven review):

- **Implementer:** Sonnet-class (claude-sonnet-4-6 or equivalent). Tasks-status moves are stamped `[claude:sonnet:implementer:implementer]` in the commit message per the M5A precedent.
- **Reviewer:** Opus-class (claude-opus-4-7 or equivalent). Tasks-status moves are stamped `[claude:opus:reviewer:reviewer]` in the commit message per the M5A precedent.
- **Author:** Russell. Final merge after for_review → approved transition.

Cross-mission orchestration (running multiple missions through implement-review concurrently) is handled by the `spec-kitty-implement-review` skill on the orchestrator agent's side; missions themselves don't need any coordination logic in their specs.

## Constitutional reference table (codified by Wave 0)

| ID | Directive | Where applied across this wave plan |
|---|---|---|
| DIR-004 | OCAP-by-architecture invariant | M-A5 (operational embodiment); M5C (MCP serializer respects it); OCAP-audit-log; Classification engine; Offline-first sync (audit-log integration); OIDC (Userinfo response respects field-access) |
| DIR-005 | Two-axis entity-storage invariant | Versioned-blob abstraction (extends shape preserving both axes); Classification engine (classification field is typed-data extension); Offline-first sync (replicates two-axis tuple) |
| DIR-006 | Codified policy gates as trust substrate | Succession Tier 1 (publishes the trust surface); Empty package decisions (Composer policy CP-006 coordination); every mission's verification gate cites `bin/check-*` |
| DIR-007 | Standalone Nuxt SPA bet | Inertia demotion mission; M5B/C/D and M-A5 WP03 frontends; all v0.1 surface drafts |
| DIR-008 | GPL-2.0-or-later license commitment | Anokii scaffold (Anokii charter aligns); every new package keeps SPDX header per existing Quality Gates |

---

## Open decisions surfaced to Russell during this session

| Decision | Resolution captured 2026-05-24 |
|---|---|
| Git reconciliation | Hard reset to `origin/main`; local bdb0be92a discarded |
| SPA bet (Nuxt vs Inertia) | Standalone Nuxt — codified DIR-007 |
| License trajectory | GPL-2.0-or-later — codified DIR-008 |
| Anokii repo home | Scaffold new repo first (separate from framework repo); v0.1 productivity surface specs deferred to Anokii repo |

No further open decisions blocking downstream agents. Where missions defer decisions to implementers, the preference order applies: **preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.**
