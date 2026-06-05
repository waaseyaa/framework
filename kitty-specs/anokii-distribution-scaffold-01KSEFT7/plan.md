# Implementation Plan: Anokii Distribution — Repo Scaffolding (Wave 1)

**Mission:** `anokii-distribution-scaffold-01KSEFT7` — see `spec.md`.
**Pattern reference:** `ai-observability-dashboard-01KSE9BX` (WP/spec/plan rhythm). `charter-amendment-anokii-track-01KSEFE0` (Anokii constitutional commitments inherited verbatim).
**Four WPs, mostly sequential with one parallel arm:**
- **WP01** (repo + composer) → must land first.
- **WP02** (.kittify init + charter) → depends on WP01.
- **WP03** (deployer overlay + branded tokens) → depends on WP02 (charter sets the deployer/UX posture).
- **WP04** (ten artifact draft specs) → depends on WP02 (Anokii directive IDs minted by WP02 are referenced from drafts); may run **in parallel** with WP03 because WP03 modifies the Anokii repo while WP04 modifies this mission's lane in the Waaseyaa repo — non-overlapping owned files.

The mission's net effect: one new GitHub repo with scaffold + .kittify + deployer overlay + branded tokens, AND ten artifact draft specs committed inside this mission's lane in the Waaseyaa repo.

## WP01 — GitHub repo + initial Composer manifest

### Goal
Stand up `anokii/anokii` (or `<org>/anokii`) GitHub repo with four committed files: `composer.json`, `LICENSE.txt`, `README.md`, `.gitignore`.

### Subtasks
- **T001 — Org & repo creation.**
  - Decide `anokii/` org vs `<existing-waaseyaa-org>/anokii` sibling. Default: prefer `anokii/anokii` for clean separation aligned with framework-vs-distribution doctrine. If `anokii` org doesn't exist on GitHub, create it (Russell-owned).
  - Create repo: public visibility, default branch `main`, GPL-2.0-or-later license metadata, description: "Anokii — the first opinionated distribution built on Waaseyaa. Sovereign workspace for First Nations. OCAP-by-architecture. Working name pending language-keeper verification."
  - No GitHub Actions, no Issue templates, no `.github/` directory at scaffold time. Pure repo.
- **T002 — `composer.json`.**
  - Name `anokii/anokii`, type `project`, license `GPL-2.0-or-later`, description matching repo description, `config.sort-packages: true`, `prefer-stable: true`, `minimum-stability: alpha` (matches framework's alpha posture). Authors: Russell Jones + Anokii project.
  - `require`: pick **one** of (a) `"waaseyaa/full": "^0.1.0-alpha.<latest>"` for the kitchen-sink shape, or (b) `"waaseyaa/core": "^0.1.0-alpha.<latest>"` + `"waaseyaa/cms": "^0.1.0-alpha.<latest>"` for a leaner shape that excludes packages Anokii does not need at scaffold-time. WP01 implementer reads the metapackage `composer.json` files in the framework repo and chooses based on what they pull in. Document the choice in the WP01 commit message.
  - `require-dev`: empty at scaffold-time; later Anokii missions add testing tooling.
  - `autoload` / `autoload-dev`: empty namespaces map (no Anokii-namespace code yet).
  - `scripts`: empty or one `post-install-cmd` that prints "Anokii installed — see README.md".
  - No `repositories` section (Anokii consumes from Packagist; path repos are framework-side only).
- **T003 — `LICENSE.txt`.**
  - Copy GPL-2.0-or-later text from framework repo's `LICENSE.txt` verbatim. Single file, no preamble Anokii-customisation.
- **T004 — `README.md`.**
  - ~3–4 KB. Sections: "What Anokii is" (sovereign workspace for First Nations, Tsen'awt-comparable, OCAP-by-architecture, built on Waaseyaa); "Framework vs distribution" (link framework charter, link `charter-amendment-anokii-track-01KSEFE0`); "Status" (alpha — repo scaffold only, no product code yet); "Install" (`composer create-project anokii/anokii` placeholder); "License" (GPL-2.0-or-later); "Working name" (Anishinaabemowin etymology + language-keeper verification note); "How to contribute" (issues will open once v0.1 surfaces begin landing).
- **T005 — `.gitignore`.**
  - Standard Composer + PHP + node-ish exclusions: `/vendor/`, `/var/`, `/cache/`, `/.env`, `/.env.local`, `composer.lock` (debatable — for a project repo we KEEP composer.lock per Composer convention; do not gitignore it), `/.idea/`, `/.vscode/`, `/.DS_Store`, `/node_modules/` (for future admin overlay work), `/storage/`, `/public/build/`.
- **T006 — Verify install works.**
  - In a scratch directory (not the Anokii repo): `composer create-project anokii/anokii test-anokii --stability=alpha --no-install` then `cd test-anokii && composer install`. If Packagist has not yet indexed the new repo, this fails — that's expected at first push. Implementer documents the verification as "verified locally against path repo before push" if so.
  - Run `bin/check-composer-policy` (from a Waaseyaa checkout) against the Anokii `composer.json` — must pass CP002 (no `@dev`), CP003 (no wildcards for `waaseyaa/*`), CP-NEW (caret-bounded shape).

### Commits
- `chore(scaffold): initial composer.json + LICENSE.txt + README.md + .gitignore`
- Optional second commit: `docs: README.md for scaffold state` if the README is fleshed out separately.

### Verification
- Repo URL resolves on GitHub, is public, has 4 files in tree, default branch `main`.
- `composer install` in a clean scratch directory either succeeds (Packagist resolved) or succeeds via a path repository workaround (documented).
- `bin/check-composer-policy` green against Anokii `composer.json`.

## WP02 — `.kittify` init + Anokii charter

### Goal
Initialize spec-kitty in the Anokii repo and hand-author the Anokii distribution charter codifying the five elements named in spec.md §Scope WP02.

### Subtasks
- **T007 — `spec-kitty init --here`.**
  - From inside the Anokii repo working tree: `spec-kitty init --here`. Verify `.kittify/` is created with `charter/`, `dashboard/`, mission scaffolding, `.kittify/SPEC_KITTY_VERSION` file matching Russell's currently-installed spec-kitty version. The `.kittify/skills/` symlink directory is gitignored per `.gitignore` (matches framework convention).
- **T008 — Author `.kittify/charter/charter.md`.**
  - Hand-author. Do NOT run `spec-kitty charter interview` — Anokii has no interview transcript yet, and the charter content is pre-resolved.
  - Sections (in order):
    1. **Preamble** — what Anokii is, working-name caveat (Anishinaabemowin verb stem "she/he works"; pending language-keeper verification), GPL-2.0-or-later declaration, dependency-on-Waaseyaa declaration.
    2. **Framework vs Distribution (the consumer side)** — Anokii consumes Waaseyaa via Packagist; never modifies Waaseyaa from inside the Anokii repo; upstreams generally-useful work via separate framework-targeted missions.
    3. **Anokii Project Directives** — numbered DIR-A001 onward:
       - **DIR-A001 — AODA Level AA is a design constraint, not an optional feature.** Every v0.1 surface MUST meet WCAG 2.1 AA + AODA-specific procurement-legibility requirements. axe-core CI gate enforces baseline. Bypass requires a charter-exception with a removal date.
       - **DIR-A002 — Offline-first is a design constraint, not an optional feature.** Every v0.1 surface MUST function in offline-degraded mode per the offline-first design (Dexie + Workbox + FSM sync engine composing on framework two-axis revisions). A surface that requires connectivity for read-after-write within the user's own classification scope is a charter violation.
       - **DIR-A003 — Indigenous-language translation pipeline is a product layer, not a configuration toggle.** Extraction tooling → `translation_string` entity (mirrors framework two-axis storage shape) → contributor dashboard → `translation_review` workflow → glossary entity → per-Nation override layer. Pilot: English ↔ Anishinaabemowin (southern + northern Ojibwe), 20–30 glossary terms co-authored with a language keeper. Pilot Nations Pilot Nation A then Pilot Nation B; final selection deferred to language-keeper engagement.
       - **DIR-A004 — GPL-2.0-or-later license trajectory aligned with framework DIR-008.** Anokii is GPL-2.0-or-later because Waaseyaa is. Relicensing requires both a framework-charter amendment AND an Anokii-charter amendment.
       - **DIR-A005 — Product-surface OCAP-by-architecture commitments inherit framework DIR-004.** Anokii productivity surfaces MUST consume framework AccessChecker/FieldAccessPolicyInterface wiring; surface code never bypasses or weakens these. Per-record AI access (gap-matrix A5 flagship in framework) extends through Anokii Co-Intelligence Workspaces verbatim.
    4. **Amendment Process** — mirrors framework amendment process structure. Anokii charter amendments are recorded in Anokii's own Amendment History.
    5. **Exception Policy** — `charter-exception` mechanism with mandatory removal date, matching framework pattern.
    6. **Amendment History** — empty table at scaffold; future amendments append.
- **T009 — Verify spec-kitty state.**
  - `spec-kitty status` must report healthy (no missing dirs, charter present).
  - `cat .kittify/charter/charter.md | grep -c "^## Anokii Project Directives"` returns 1.
  - `cat .kittify/charter/charter.md | grep -cE "^DIR-A0[0-9]+ —"` returns ≥ 5.

### Commits
- `chore: spec-kitty init --here`
- `docs(charter): hand-author Anokii distribution charter (DIR-A001..DIR-A005)`

### Verification
- `.kittify/` tree exists, `charter.md` is non-empty and matches expected shape, `spec-kitty status` green.

## WP03 — Deployer recipe overlay + branded UX baseline

### Goal
Ship a deployer overlay file inheriting from Waaseyaa's reference recipe, and a single CSS-tokens file declaring the deep-teal palette as Anokii's branded baseline.

### Subtasks
- **T010 — Deployer overlay.**
  - Path: `deploy.php` at repo root (Deployer convention) OR `deployer/recipes/anokii.php` if a multi-recipe structure is preferred. Implementer picks `deploy.php` for the simplest case.
  - Inherits from `vendor/waaseyaa/deployer/recipes/waaseyaa.php` (or whatever path the framework's deployer package exposes). Overlays:
    - **Storage bucket naming:** `set('storage_bucket', fn($nation, $env) => "anokii-{$nation}-{$env}")`.
    - **Classification policy seed:** a Deployer task `anokii:seed:classification` that runs `bin/waaseyaa config:import config/classification.anokii-default.yaml` (which is also shipped in this WP at `config/classification.anokii-default.yaml`).
    - **Sample tenant config stub:** `config/tenants/example-nation.yaml.example` with placeholder Nation metadata (Nation name, ISO-639-3 language code `oji` for Ojibwe, an existing tenant affiliation flag, sample classification taxonomy reference).
  - The recipe MUST be runnable in `--dry-run` mode by the implementer as a smoke check.
- **T011 — Branded tokens file.**
  - Path: `assets/theme/anokii-tokens.css` (no `packages/` subdirectory yet — keeps the scaffold lean per C-005).
  - Contents: CSS custom properties declaring the deep-teal palette with the exact hex values from framework CLAUDE.md §Code Style — `#0d4f4f`, `#0f766e`, `#14b8a6` — mapped to `--anokii-color-primary-900`, `--anokii-color-primary-700`, `--anokii-color-primary-500`. Plus a `--color-primary: var(--anokii-color-primary-700);` alias so admin-shell code referencing `--color-primary` picks up Anokii's value when the file is loaded.
  - Document in `README.md` where consumers should `@import` it from (e.g., from a future Anokii admin overlay package). The token file itself is the canonical brand declaration.
- **T012 — `CHANGELOG.md` init.**
  - `# Changelog\n\nAll notable changes to Anokii will be documented in this file.\n\n## [Unreleased]\n\n### Added\n- Initial repo scaffold (composer.json, LICENSE.txt, README.md, .gitignore).\n- `.kittify/` init + Anokii distribution charter (DIR-A001..DIR-A005).\n- Deployer overlay (`deploy.php`) + classification policy seed + sample Nation tenant config stub.\n- Branded UX baseline tokens (`assets/theme/anokii-tokens.css`) — deep-teal palette.\n`
- **T013 — Smoke verifications.**
  - `php -l deploy.php` (lint OK).
  - `cat assets/theme/anokii-tokens.css | grep -c '#0d4f4f\|#0f766e\|#14b8a6'` returns 3.
  - `cat config/tenants/example-nation.yaml.example | head -5` shows valid YAML.

### Commits
- `feat(deploy): deployer overlay inheriting Waaseyaa reference recipe`
- `feat(theme): branded UX baseline — deep-teal tokens`
- `chore: CHANGELOG.md initialised`

## WP04 — Ten artifact draft specs

### Goal
Produce ten `spec.md`-shaped Markdown drafts under this scaffold mission's `artifacts/` directory in the Waaseyaa repo. These are seeds for future Anokii-repo missions; they do NOT land in either repo's `docs/specs/`.

### Subtasks
- **T014 — Read source material end-to-end.**
  - `/tmp/waaseyaa-design-offline-first.md` (canonical for cross-cutting offline-first draft).
  - `/tmp/waaseyaa-design-accessibility.md` (canonical for cross-cutting AODA AA draft + per-surface AODA notes in each surface draft).
  - `/tmp/waaseyaa-design-translation-pipeline.md` (canonical for translation pipeline references in surface drafts + charter).
  - `/tmp/waaseyaa-design-succession-framework.md` (background for governance posture; cited in drafts that touch maintainability).
  - `/mnt/c/Users/jones/Projects/RussellJones/projects/waaseyaa-platform/gap-matrix.md` (canonical for capability rows referenced by each surface draft).
  - `/mnt/c/Users/jones/Projects/RussellJones/projects/waaseyaa-platform/alpha-to-beta-plan.md` (reference for what's substrate vs distribution).
  - Anokii charter (WP02's output, settled before WP04 starts) for DIR-A001..DIR-A005 IDs.
- **T015 — Author eight v0.1 surface drafts.** Each at `kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/<surface>.spec.md`. Shape: Why / Scope-in-scope / Scope-out / Requirements table (≥ 8 entries, mix of FR/NFR/Constraint) / Acceptance / Risks / Out-of-band / Cross-references (framework DIR-IDs, Anokii DIR-A IDs, gap-matrix row). Each ≥ 4 KB, target 6–10 KB.
  - `governed-drive.spec.md` — Drive folder entity (`drive_folder`), ACL inheritance via FieldAccessPolicyInterface, classification-label field, share-link revocation, audit on every read/write/share/revoke. Composes on framework DIR-004 (OCAP), DIR-005 (two-axis storage). Gap-matrix B1/B2.
  - `form-builder.spec.md` — Form-definition entity, conditional logic (`when field X equals Y, show Z`), branching, validators (required, regex, min/max, custom), submission entity with multi-submission-merge default + LWW opt-in classification flag (per spec.md §Pre-resolved). AODA hooks for fieldsets, `<legend>`, error summary `role="alert" aria-live="assertive"`. Composes on DIR-004, DIR-005. Gap-matrix C section.
  - `tasks.spec.md` — `task` entity (assignee, due_at, status, parent_list), `task_list` entity (kanban-board model), assignment notifications via framework `notification` package, due-date scheduler via framework `scheduler` package. Composes on DIR-004. Gap-matrix D1.
  - `data-rooms.spec.md` — `data_room` entity, consent state machine via framework `state` + `workflows`, multi-party invitation flow, share-link expiry, watermarking on exported docs. Composes on DIR-004. Gap-matrix D2.
  - `governed-docs.spec.md` — rich-text field type (TipTap/ProseMirror integration), comment threads as relationship entities, entity-level revisions (framework two-axis), save-conflict resolution at v0.1 (three-way merge deferred to v1.0), per-character collab deferred. Composes on DIR-004, DIR-005. Gap-matrix B3.
  - `governed-sheets.spec.md` — `tabular_field` (cell grid), minimal formula engine (SUM, AVG, COUNT, IF, basic arithmetic), per-cell access via FieldAccessPolicyInterface (advanced), CSV export with audit. Composes on DIR-004, DIR-005. Gap-matrix B4.
  - `co-intelligence-workspaces.spec.md` — `co_intel_workspace` entity scoped to a Drive folder OR data room OR set of records, per-record AI toggle (consumes framework `per-record-ai-access-flagship-*` substrate), AI response surface with focus-management + progressive announcement (per accessibility design §7.2), translation-pipeline-aware i18n on response surface. Composes on DIR-004 (heavily — gap-matrix A5 flagship), DIR-005, DIR-A003 (translation pipeline). Gap-matrix A5 + A applied to Anokii surface.
  - `admin-centre.spec.md` — tenant-management UI (create/list/edit Nation tenants), classification-policy editor consuming framework `config` admin, unified audit-query UI consuming framework OCAP audit log (gap-matrix A3), Anokii-specific overlays for theme + translation-pipeline glossary management. Composes on DIR-004, DIR-006 (gates). Gap-matrix F section.
- **T016 — Author two cross-cutting drafts.** Each at `kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/cross-cutting/<topic>.spec.md`. Shape identical to surface drafts.
  - `offline-first.spec.md` — Dexie schema design (composite-key mirror of framework `(entity_id, langcode, vid)`), Workbox service-worker config, FSM-based sync-engine with per-surface conflict-resolution strategy table (Drive metadata: LWW; Forms: multi-submission-merge default + LWW opt-in; Tasks: LWW; Data Rooms: server-authoritative read-only-offline; Docs: save-conflict at v0.1; Sheets: LWW per cell at v0.1; Co-Intelligence: server-authoritative read-only-offline), identity-offline (token-cache + explicit expiry + re-auth-on-reconnect + partial-trust = read own classified data offline but not other Nations' cached data + offline-operations carry `offline_at` timestamp + audit-log-syncs-on-reconnect), network-aware UI affordances ("offline" / "syncing" / "synced" status indicator in admin shell). Pre-resolves all decisions per spec.md §Pre-resolved.
  - `aoda-aa-baseline.spec.md` — AODA Level AA target across all v0.1 surfaces. Per-surface constraint catalogue (mirrors design doc §5.1..§5.N). Governance-aware accessibility: hard access-denied → `aria-live="assertive"` + role="alert"; soft denied → `aria-live="polite"`; both include actionable recovery hint ("Request access from your Nation admin"). Co-Intelligence: focus moves to AI response surface on first token; progressive announcement of multi-step "thinking..." state; long responses summarised first with expandable detail. axe-core CI gate enforces baseline; per-component a11y test in vitest + Playwright. 13-component baseline pass listed by component name. Pre-resolves all decisions per spec.md §Pre-resolved.
- **T017 — Verify drafts.**
  - All ten files exist at expected paths.
  - `wc -c kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/*.spec.md kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/cross-cutting/*.spec.md` shows each ≥ 4000 bytes.
  - Each file has `## Requirements` heading, a Markdown table, and ≥ 8 row entries (`grep -c "^| FR-\|^| NFR-\|^| C-" <file>`).
  - No `_TBD_`, no `TODO`, no week/month/sprint language (`grep -i 'week\|month\|sprint\|TBD\|TODO' <file>` returns empty for normative sections).

### Commits
- `docs(artifacts): v0.1 productivity surface draft specs (8 files)`
- `docs(artifacts): cross-cutting draft specs — offline-first + AODA AA baseline`

## Verification gate (mission-wide)

In the lane worktree (Waaseyaa-side mission lane):
1. `ls kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/*.spec.md | wc -l` → `8`.
2. `ls kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/cross-cutting/*.spec.md | wc -l` → `2`.
3. `spec-kitty status` (run from Waaseyaa repo root) reports this mission ready for review/merge.

In the Anokii repo:
1. `gh repo view <org>/anokii` resolves successfully.
2. `composer install` succeeds.
3. `bin/check-composer-policy` (run from a Waaseyaa checkout against the Anokii composer.json) returns 0.
4. `.kittify/charter/charter.md` is non-empty and contains DIR-A001..DIR-A005.
5. `deploy.php` lints clean.
6. `grep -c '#0d4f4f\|#0f766e\|#14b8a6' assets/theme/anokii-tokens.css` returns 3.

## Reviewer focus

- **(a) Framework-vs-distribution discipline (DIR-004).** Zero `packages/` changes in the Waaseyaa repo. All distribution-specific content lives in the Anokii repo OR in this mission's `artifacts/` directory (lane-local, not framework-published).
- **(b) Composer policy compliance (CP002/CP003/CP-NEW).** `bin/check-composer-policy` green against the Anokii `composer.json`. No `@dev`, no wildcards, caret-bounded shape.
- **(c) Charter cross-referencing (FR-005/FR-009).** Anokii DIR-A001..DIR-A005 reference framework DIR-004..DIR-008 explicitly; surface drafts reference both directive sets correctly.
- **(d) Pre-resolved decisions baked in (NFR-003).** No `_TBD_`, no question to Russell, no timeline language. Cross-check `offline-first.spec.md` and `aoda-aa-baseline.spec.md` against the design docs for fidelity.
- **(e) Branded palette exactness (NFR-004).** `assets/theme/anokii-tokens.css` contains the exact hex values from framework CLAUDE.md §Code Style — no alternative palettes, no approximations.
- **(f) Scope discipline.** WP04 is *drafts only* — no `spec-kitty specify` run against the Anokii repo from inside this scaffold mission (C-002).
