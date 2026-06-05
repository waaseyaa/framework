# Anokii Distribution — Repo Scaffolding (Wave 1)

**Mission:** `anokii-distribution-scaffold-01KSEFT7`
**Target branch:** `main` (this scaffold mission; Anokii repo itself targets a fresh `main` in its own remote)
**Tracks:** No GitHub issue at framework level. This mission is the seed for the **Anokii distribution repo**, which becomes the canonical home for all downstream Anokii issues, PRs, releases, and missions. Blocks none of the Waaseyaa framework substrate work; gated by `charter-amendment-anokii-track-01KSEFE0` (which codifies DIR-004 through DIR-008 — Anokii's productivity-surface drafts reference them by ID).
**Pattern reference:** `ai-observability-dashboard-01KSE9BX` (cross-layer surface shape + WP/spec/plan rhythm). `charter-amendment-anokii-track-01KSEFE0` (Anokii constitutional commitments + Framework-vs-Distribution doctrine the draft specs inherit).

## Why this mission exists

Waaseyaa is a framework. **Anokii** (Anishinaabemowin verb stem "she/he works"; working name pending language-keeper verification before public use) is the first opinionated distribution built on it — a sovereign-workspace product for First Nations, Tsen'awt-comparable in productivity surface area, OCAP-by-architecture differentiated. The framework charter (`charter-amendment-anokii-track-01KSEFE0`) codifies the framework-vs-distribution split (DIR-004..DIR-008) but leaves the Anokii repo non-existent. Until Anokii has a repo, every Anokii-specific design decision (productivity surface scope, offline-first commitments, AODA Level AA commitments, Indigenous-language translation pipeline scope, deployer overlay) has nowhere to live — it gets jammed into framework specs and pollutes Waaseyaa's reusable-substrate doctrine.

This mission stands up the Anokii repo so that:

1. A real `composer.json` exists declaring Anokii's dependency on Waaseyaa packages via Packagist (`waaseyaa/full` or selected metapackages), validating end-to-end that the framework's published package shape works for downstream distributions.
2. A real `.kittify/charter/charter.md` exists codifying Anokii's distribution-level commitments (AODA Level AA as design constraint, offline-first as design constraint, Indigenous-language translation pipeline as product layer, GPL-2.0-or-later alignment with framework DIR-008, OCAP-by-architecture product surfaces inheriting framework DIR-004).
3. A real deployer overlay exists demonstrating distribution-specific config layering on top of the framework's reference recipe.
4. A real branded UX baseline exists (deep-teal palette per framework CLAUDE.md §Code Style) replacing the framework's reference admin theme tokens at the distribution layer.
5. **Ten draft `spec.md`-shaped artifact files exist** under this mission's `artifacts/` directory — one per v0.1 productivity surface (8 surfaces) plus two cross-cutting concerns (offline-first, AODA Level AA). These drafts are **not** spec-kitty missions in the Anokii repo yet (the repo did not exist when they were drafted); they are pre-resolved spec content that a follow-up agent in the Anokii repo will paste into `spec-kitty specify` invocations to file as proper Anokii missions. Treat them as fully-fleshed mission spec content.

## The framework-vs-distribution constraint (read before designing)

Per the charter amendment (`DIR-004` through `DIR-008`), Anokii is governed by the framework charter for substrate concerns AND by its own distribution charter for product-surface concerns. The dependency flows **one way: Anokii → Waaseyaa**. The Waaseyaa repo must never reference Anokii. Anokii contributes upstream when functionality is generally useful (e.g., a new field type, a sync-engine primitive). It keeps distribution-specific code in its own repo (branded UX, the translation pipeline, productivity-surface configuration). A change to the framework that breaks Anokii is Anokii's problem to absorb on its own schedule; a change to Anokii never propagates back to the framework without a separate upstream-contribution mission filed against the framework.

The implementer of WP01–WP03 is creating a **separate GitHub repo** (not a `packages/` subdirectory of waaseyaa). The implementer of WP04 is producing **artifact Markdown files** inside this mission's lane — those files are seeds for future Anokii-repo missions, not specs that live in either repo's `docs/specs/` long-term.

## Scope

### In scope

**WP01 — GitHub repo creation + initial Composer manifest:**
- Create `anokii/anokii` GitHub repo (or `waaseyaa-org/anokii` sibling — implementer chooses based on org structure during execution). Default branch `main`. Public visibility (Anokii is GPL-2.0-or-later and built in the open).
- Initial `composer.json`: name `anokii/anokii`, type `project`, license `GPL-2.0-or-later`, `config.sort-packages: true` (per framework Composer policy CP-NEW). Requires `waaseyaa/full` at the current released tag (e.g., `^0.1.0-alpha.<latest>`) OR a curated subset of metapackages (`waaseyaa/core` + `waaseyaa/cms`) — implementer chooses based on what `waaseyaa/full` pulls in versus what Anokii actually needs at scaffold-time. No `@dev` (CP002). No wildcard internal constraints (CP003).
- `LICENSE.txt` containing GPL-2.0-or-later text (canonical copy from the framework repo).
- `README.md` (~3–4 KB) describing: what Anokii is, distribution-vs-framework position, link to framework charter, link to this scaffold mission, GPL-2.0-or-later license declaration, basic install instruction (`composer create-project anokii/anokii` placeholder until the first release tag).
- `.gitignore` covering composer artifacts, `vendor/`, `var/`, `.env`, build outputs.

**WP02 — `.kittify` init + Anokii charter:**
- Run `spec-kitty init --here` in the new repo (creates `.kittify/` scaffold).
- Author `.kittify/charter/charter.md` codifying:
  - Distribution-vs-framework relationship (mirrors framework DIR-004's framework half from the inverse side: Anokii consumes Waaseyaa, never modifies it from inside the Anokii repo).
  - **AODA Level AA as design constraint** (not optional feature) — every Anokii v0.1 surface MUST meet WCAG 2.1 AA + AODA-specific requirements; axe-core CI gates enforce baseline.
  - **Offline-first as design constraint** (not optional feature) — every Anokii v0.1 surface MUST function in offline-degraded mode per the offline-first design (Dexie + Workbox + FSM sync engine composing on framework two-axis revisions).
  - **Indigenous-language translation pipeline as product layer** — extraction tooling → `translation_string` entity (mirrors two-axis storage) → contributor dashboard → `translation_review` workflow → glossary entity → per-Nation override layer. Pilot scope: English ↔ Anishinaabemowin (southern + northern Ojibwe dialects), 20–30 glossary terms co-authored with a language keeper. Pilot Nations: Pilot Nation A-first (a maintainer's home Nation; an existing tenant already on Waaseyaa) with Pilot Nation B as second. Final Nation selection deferred to the language-keeper engagement moment.
  - **GPL-2.0-or-later license trajectory aligned with framework DIR-008** — Anokii is GPL-2.0-or-later because Waaseyaa is GPL-2.0-or-later; relicensing requires both a framework-charter amendment AND an Anokii-charter amendment.
  - **Product-surface OCAP-by-architecture commitments matching framework DIR-004** — every Anokii productivity surface inherits framework AccessChecker/FieldAccessPolicyInterface wiring; surface code never bypasses or weakens these.
- Anokii charter directives numbered DIR-A001 through DIR-A00N (distinct from framework DIR-NNN to avoid ID collision when cross-referencing).

**WP03 — Deployer recipe overlay + branded UX baseline:**
- `deploy.php` (or `deployer/recipes/anokii.php`) inheriting Waaseyaa's reference deployer recipe (`packages/deployer/recipes/waaseyaa.php` or equivalent). Overlay points: storage bucket naming convention (e.g., `anokii-<nation>-<env>`), classification-policy seed (default Anokii classification taxonomy values that a fresh tenant gets), sample Nation tenant config stub (`config/tenants/example-nation.yaml.example`).
- Branded UX baseline: a single CSS-tokens file (`packages/admin-theme-anokii/src/tokens.css` or similar — Anokii consumes Waaseyaa's `packages/admin` SPA and overlays its own theme tokens) defining the **deep-teal palette** (`#0d4f4f → #0f766e → #14b8a6`) per framework CLAUDE.md §Code Style. Replaces Waaseyaa's reference admin tokens at the distribution layer; auth flows, AdminShell `--color-primary`, and brand-mark surfaces all reference the Anokii tokens once installed.
- `CHANGELOG.md` initialised with `[Unreleased]` section.

**WP04 — Draft v0.1 productivity surface specs as `artifacts/`:**
- Produce one full `spec.md`-shaped Markdown file per v0.1 productivity surface in `kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/<surface>.spec.md`:
  - `governed-drive.spec.md` (Drive folder entity + ACL inheritance + classification + audit per item — gap-matrix B1/B2)
  - `form-builder.spec.md` (Forms with conditional logic, branching, validators — gap-matrix C and design-accessibility §5.3)
  - `tasks.spec.md` (Tasks/Kanban/lists/assignments — gap-matrix D1; cheapest surface, admin kanban pattern reusable)
  - `data-rooms.spec.md` (multi-party + consent + audit + revocability — gap-matrix D2)
  - `governed-docs.spec.md` (rich text + OCAP collab + comments + revisions — gap-matrix B3; save-conflict resolution at v0.1)
  - `governed-sheets.spec.md` (tabular + formulas + OCAP sharing — gap-matrix B4; first-cut tabular field + minimal formula engine)
  - `co-intelligence-workspaces.spec.md` (per-record AI workspaces — gap-matrix A5 flagship; consumes framework `per-record-ai-access-flagship-*` substrate)
  - `admin-centre.spec.md` (tenant management + classification policy + audit query UI — gap-matrix F)
- Plus two cross-cutting drafts under `artifacts/cross-cutting/`:
  - `offline-first.spec.md` (Dexie + Workbox + FSM sync engine, per-surface conflict-resolution strategy table, identity-offline token-cache + re-auth-on-reconnect + partial-trust + audit-log-syncs-on-reconnect — pre-resolved per design doc)
  - `aoda-aa-baseline.spec.md` (AODA Level AA across all surfaces, governance-aware accessibility — `aria-live="assertive"` for hard access-denied, polite for soft; focus management + progressive announcement for Co-Intelligence — pre-resolved per design doc)
- Each draft is ~6–10 KB, pre-resolves the decisions named in `Pre-resolved decisions` below, and is shaped exactly like a `spec.md` (Why / Scope / Requirements / Acceptance / Risks / Out-of-band) so a follow-up agent can run `spec-kitty specify` in the Anokii repo and paste with minimal editing.
- Each draft references framework directives by ID (DIR-004..DIR-008), Anokii charter directives by ID (DIR-A001..DIR-A00N — placeholder when the WP02 charter is not yet final; implementer of WP04 inserts the actual IDs once WP02 has settled them), and the relevant gap-matrix capability row.

### Out of scope

- **Migrating any existing Waaseyaa or Minoo data into Anokii.** Anokii ships empty; data-migration tooling is a future Anokii mission.
- **Implementing any of the v0.1 productivity surfaces.** WP04 produces *draft specs*, not code. The Anokii repo, once seeded, will run its own implementation missions surface-by-surface — each spec draft becomes its own `spec-kitty specify` invocation.
- **Filing GitHub issues in the Anokii repo for the surfaces.** WP04 produces specs; mission filing happens in a follow-up after WP03 lands.
- **CI configuration for the Anokii repo beyond the bare minimum.** No GitHub Actions setup, no test runners, no deployer GitHub workflow. Initial repo passes `composer install` and that is the entire CI surface at scaffold-time.
- **Translation-pipeline implementation.** WP04's translation-pipeline content stays inside the draft spec for `co-intelligence-workspaces.spec.md` (where it surfaces via i18n strings) and is referenced in the charter (WP02); the translation pipeline itself ships in a later Anokii mission.
- **Per-Nation tenant configuration beyond the sample stub.** WP03 ships one example tenant config; real Nation onboarding is post-scaffold work.
- **OIDC provider completion or Indigenous IdP federation.** Anokii consumes whatever IdP shape Waaseyaa ships; finishing OIDC is a framework mission, not an Anokii scaffold concern.
- **Charter regeneration in the Anokii repo from a fresh interview.** WP02 hand-authors the Anokii charter using pre-resolved content. A future Anokii mission may run `spec-kitty charter interview` against an Anokii-specific question set; this scaffold does not block on that.
- **Any modification to the Waaseyaa repo from this mission.** Per DIR-004's framework-vs-distribution rule, Anokii scaffold work is one-directional — Anokii consumes Waaseyaa; Waaseyaa knows nothing about Anokii's existence.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | A new GitHub repo named `anokii` (under either `anokii/` org or `waaseyaa-org/`) MUST exist with default branch `main`, public visibility, GPL-2.0-or-later license declared in repo metadata, and at minimum the four scaffold files committed: `composer.json`, `LICENSE.txt`, `README.md`, `.gitignore`. |
| FR-002 | Mandatory | The repo's `composer.json` MUST: name `anokii/anokii`, type `project`, license `GPL-2.0-or-later`, `config.sort-packages: true`, require Waaseyaa packages at a concrete released tag (no `@dev`, no `*` wildcards for `waaseyaa/*`). The constraint shape MUST comply with framework Composer policy CP002 + CP003 + CP-NEW (verifiable by running `bin/check-composer-policy` from a Waaseyaa checkout against the Anokii composer.json). |
| FR-003 | Mandatory | `composer install` against the new repo MUST succeed on a clean checkout (Packagist resolves Waaseyaa metapackages; sibling-version pinning works via `self.version` from the framework). |
| FR-004 | Mandatory | `.kittify/` MUST exist at repo root with charter / dashboard / mission scaffolding produced by `spec-kitty init --here`. Spec-kitty CLI (current version per Russell's environment) MUST report a healthy state via `spec-kitty status`. |
| FR-005 | Mandatory | `.kittify/charter/charter.md` MUST codify the five distribution-charter elements named in §Scope WP02: framework-vs-distribution relationship, AODA Level AA as design constraint, offline-first as design constraint, Indigenous-language translation pipeline as product layer, GPL-2.0-or-later license trajectory aligned with framework DIR-008. OCAP-by-architecture product-surface commitments inherit framework DIR-004 explicitly by reference. Anokii directives numbered DIR-A001..DIR-A00N (distinct namespace). |
| FR-006 | Mandatory | A deployer overlay file MUST exist (e.g., `deploy.php` or `deployer/recipes/anokii.php`) inheriting from the Waaseyaa reference recipe and overlaying: storage-bucket naming convention, classification-policy seed, one sample Nation tenant config stub at `config/tenants/<nation>.yaml.example`. |
| FR-007 | Mandatory | A branded UX baseline MUST exist as a single CSS-tokens file declaring the deep-teal palette (`#0d4f4f → #0f766e → #14b8a6`) at the distribution layer. The token file MUST be referenced from the Anokii admin overlay (or documented as the consumption point a future admin-overlay package will reference). |
| FR-008 | Mandatory | Ten draft spec files MUST exist under `kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/`: eight surface drafts at `artifacts/v0.1/<surface>.spec.md` (governed-drive, form-builder, tasks, data-rooms, governed-docs, governed-sheets, co-intelligence-workspaces, admin-centre) and two cross-cutting drafts at `artifacts/cross-cutting/{offline-first,aoda-aa-baseline}.spec.md`. Each MUST be ≥4 KB and follow the `spec.md` shape (Why / Scope-in-scope / Scope-out / Requirements table / Acceptance / Risks / Out-of-band). |
| FR-009 | Mandatory | Each surface draft MUST cross-reference: (a) the framework directive IDs it composes on (DIR-004 OCAP, DIR-005 two-axis storage, DIR-006 gates, DIR-007 Nuxt SPA, DIR-008 GPL-2.0-or-later — choose the relevant subset per surface), (b) the relevant gap-matrix row (B*, C*, D*, F*), and (c) the Anokii charter directive IDs that govern it (DIR-A001..DIR-A00N — concrete IDs once WP02 is settled). |
| FR-010 | Mandatory | Each cross-cutting draft MUST pre-resolve the decisions named in §Pre-resolved decisions (e.g., offline-first draft pre-resolves Dexie + Workbox + FSM, identity-offline token-cache + re-auth + partial-trust + audit-log-syncs; AODA draft pre-resolves Level AA target + governance-aware access-denied `aria-live` semantics + Co-Intelligence focus/announcement strategy). No question is left for Russell. |
| FR-011 | Mandatory | The mission lane's `status.events.jsonl`, `meta.json`, and the populated `spec.md` / `plan.md` / `tasks.md` / `wps.yaml` / `tasks/WP01-*.md` / `tasks/WP02-*.md` / `tasks/WP03-*.md` / `tasks/WP04-*.md` files MUST exist and the mission MUST be runnable end-to-end via `spec-kitty next`. |
| NFR-001 | Mandatory | The Anokii repo MUST NOT import from Minoo or any Nation-specific code; only from Waaseyaa metapackages via Packagist. Verifiable by inspecting `composer.json` `require`. |
| NFR-002 | Mandatory | The Anokii repo MUST NOT introduce Waaseyaa-incompatible upper bounds on framework packages (e.g., pinning to a single patch version). Constraints MUST use caret (`^x.y.z`) or tilde (`~x.y`) ranges allowing minor/patch upgrades within the framework's semver promise. |
| NFR-003 | Mandatory | All ten artifact draft specs MUST be free of questions to Russell, free of `_TBD_`/`TODO` placeholders in normative clauses, and free of timeline language (no week/month/sprint estimates — that is alpha-to-beta-plan content, not spec content per the framework's hard rules). |
| NFR-004 | Mandatory | Deep-teal palette MUST match exactly the hex values codified in framework CLAUDE.md §Code Style: `#0d4f4f → #0f766e → #14b8a6` (no drift, no alternative palettes). |
| C-001 | Constraint | This scaffold mission produces **zero changes to `packages/`** in the Waaseyaa repo. The only Waaseyaa-side file changes are this mission's own lane artifacts under `kitty-specs/anokii-distribution-scaffold-01KSEFT7/`. The Anokii repo is created separately. |
| C-002 | Constraint | WP04 produces draft specs, not Anokii missions. The drafts are seeds — they get re-filed via `spec-kitty specify` in the Anokii repo by a follow-up agent. Do NOT attempt to run `spec-kitty specify` in the Anokii repo from inside this scaffold mission. |
| C-003 | Constraint | Anokii is GPL-2.0-or-later. No permissive-licensed code may be imported into the Anokii repo without explicit license-compatibility analysis recorded in a follow-up Anokii mission. Initial dependencies are Waaseyaa-only (already GPL-2.0-or-later). |
| C-004 | Constraint | No changes to `.kittify/charter/charter.md` in the Waaseyaa repo from this mission (that is `charter-amendment-anokii-track-01KSEFE0`'s job, and it is a prerequisite, not a co-mission). |
| C-005 | Constraint | The Anokii repo does not pre-create a `packages/` directory. Distribution-specific packages (translation pipeline, admin overlay theme, productivity-surface implementations) are filed in later Anokii missions when they are needed; the scaffold ships an empty workspace. |

## Acceptance

- All FRs met.
- GitHub repo exists, is public, composer install works.
- `.kittify/` exists; `.kittify/charter/charter.md` matches the WP02 charter content; `spec-kitty status` is green.
- Deployer overlay file exists; branded tokens file exists with exact hex values.
- All ten artifact draft specs exist under `artifacts/` with the correct shape and the pre-resolved decisions baked in.
- This mission's lane files (`spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, four `tasks/WP*-*.md` files, `checklists/requirements.md`) are populated and pass a `spec-kitty status` check.
- Reviewer can confirm: (a) the framework repo has zero `packages/` changes from this mission; (b) the Anokii repo has the four scaffold files plus the deployer overlay plus the branded tokens; (c) the ten draft specs are byte-faithful to the §Pre-resolved decisions and each contains a Requirements table with at least 8 entries.

## Risks

- **Dependency on the charter amendment.** WP04 draft specs reference DIR-004..DIR-008 by ID. If `charter-amendment-anokii-track-01KSEFE0` has not merged when WP04 runs, the IDs are forward-references. Mitigation: this scaffold mission is gated by the charter amendment (sequencing per Wave-1 ordering); WP04 implementer verifies DIR-004..DIR-008 exist in the framework charter before starting.
- **Anokii directive IDs in flux during WP02→WP04.** WP04 cross-references DIR-A001..DIR-A00N which are minted by WP02. WP04 starts only after WP02 settles the directive IDs. If WP02 renumbers mid-flight, WP04 must update references atomically before its commit.
- **Packagist publication timing.** WP01 requires Waaseyaa metapackages to be installable from Packagist. If a metapackage is mid-publish or has a broken `replace`/`provide`/`self.version` chain, `composer install` in the Anokii repo fails. Mitigation: WP01 implementer runs `composer install` in a scratch directory against a fresh Anokii `composer.json` before committing; if Packagist resolution fails, the implementer either falls back to a known-good earlier framework tag OR files a framework-side bug and pauses.
- **Distribution-vs-framework boundary erosion.** Pressure to "just put it in waaseyaa/" for the admin-overlay tokens or the deployer overlay would violate DIR-004. Reviewer checks specifically: branded tokens live in the Anokii repo (or in a dedicated `waaseyaa/admin-theme-*` package that is opt-in from the framework's side, never default); deployer overlay lives in the Anokii repo.
- **Draft spec drift from design docs.** The four design docs at `/tmp/waaseyaa-design-{offline-first,accessibility,translation-pipeline,succession-framework}.md` are the canonical source for the cross-cutting and translation-pipeline content. If WP04 paraphrases or hallucinates beyond what those docs say, the resulting Anokii missions inherit drift. Mitigation: WP04 implementer reads each design doc end-to-end before writing the corresponding draft.
- **GitHub org choice locks an audit trail.** Choosing `anokii/anokii` vs `waaseyaa-org/anokii` is a one-way door for issue history. Mitigation: WP01 implementer chooses based on org-availability at execution-time and records the rationale in the WP01 commit message; future renaming is non-trivial but possible via GitHub's repo-transfer flow.
- **an existing tenant stewards channel involvement timing.** Anokii's charter (WP02) mentions an existing tenant stewards as the Nation-level governance channel referenced by framework DIR-008 (license-change amendment process). an existing tenant formal engagement is not a WP02 blocker (charter codification predates formal endorsement), but WP02 implementer notes the engagement as a future Anokii mission so it does not get lost.

## Pre-resolved decisions

The following decisions are pre-resolved; implementer MUST NOT ask Russell or relitigate during execution. All decisions trace to the four design docs and/or the framework CLAUDE.md.

- **License:** GPL-2.0-or-later (matches framework DIR-008).
- **Branded color palette:** Deep teal — `#0d4f4f → #0f766e → #14b8a6` (chosen to differentiate from Drupal blue, Laravel red, Django/Nuxt green, Strapi purple per framework CLAUDE.md §Code Style).
- **Accessibility target:** AODA Level AA (not Level A; not WCAG-only). Codified as design constraint, not optional feature. Pilot baseline for every v0.1 surface.
- **Offline substrate:** Dexie (IndexedDB) + Workbox (service worker) + an FSM-based sync engine composing on the framework's two-axis revisions model (`RevisionableStorageDriver` + `(entity_id, langcode, vid)` tuple maps cleanly to Dexie composite keys). Per `/tmp/waaseyaa-design-offline-first.md` §10 "Strongest existing asset & recommendation".
- **Translation pipeline pilot Nations:** Pilot Nation A-first (a maintainer's home Nation; an existing tenant already on Waaseyaa) with Pilot Nation B as the second Nation. Final Nation selection deferred to the language-keeper engagement moment only.
- **Translation pipeline language scope:** English ↔ Anishinaabemowin (southern + northern Ojibwe dialects), 20–30 glossary terms co-authored with a language keeper (per `/tmp/waaseyaa-design-translation-pipeline.md` §7 Phase 1).
- **Forms offline queue strategy:** Multi-submission-merge as DEFAULT for governed community data (governance posture — every submission is a record, never overwritten). LWW (last-write-wins) available as opt-in `classification-flag` for administrative forms where latest-is-canonical is correct (e.g., a single admin updating a config record).
- **Identity offline:** Tokens cached locally with explicit expiry; re-auth-on-reconnect; partial-trust offline operation (read own classified data offline, NOT other Nations' cached data); audit log captures offline operations and syncs on reconnect (offline operations carry an `offline_at` timestamp; server reconciles on sync).
- **Governance-aware accessibility:** Access-denied messages use live-region announcements — `aria-live="assertive"` for hard denials (server-side OCAP forbidden), `aria-live="polite"` for soft denials (capability-not-granted in this session). Co-Intelligence response surfaces use focus management + progressive announcement (per `/tmp/waaseyaa-design-accessibility.md` §7.2).
- **Productivity surface scope at v0.1:** Eight surfaces — governed Drive, Form Builder, Tasks, Data Rooms, governed Docs (with save-conflict at v0.1; three-way merge to v1.0), governed Sheets (first-cut tabular field + minimal formula engine), Co-Intelligence Workspaces (per-record AI access — gap-matrix A5 flagship), Admin Centre. Communication-surface parity (Email, Calendar, Chat, Meet) is OUT of v0.1 — deferred to a later Anokii mission cluster.

## Decision preference order (per Wave 1+ mission constitution)

When the implementer faces a judgment call not covered above, apply: **preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.** Always document the call in the WP commit message.

## Out-of-band

- After this mission lands, the Anokii repo exists but is empty of product code. A follow-up "Anokii Wave-2 mission filing" agent (separate session) takes the ten artifact drafts and runs `spec-kitty specify` for each in the Anokii repo, producing real Anokii missions that can then be planned and implemented surface-by-surface.
- A follow-up Anokii mission will run `spec-kitty charter interview` against an Anokii-specific question set to regenerate `.kittify/charter/charter.md` from a transcript (currently hand-authored per WP02). Not blocking.
- A follow-up Anokii mission will file the an existing tenant stewards engagement letter / formal endorsement track.
- A follow-up framework mission (NOT an Anokii mission) will publish a `waaseyaa/admin-theme-anokii` package if the branded tokens become useful to other distributions wanting to fork Anokii's theme; default posture is to keep the tokens in the Anokii repo and let other distributions choose their own.
