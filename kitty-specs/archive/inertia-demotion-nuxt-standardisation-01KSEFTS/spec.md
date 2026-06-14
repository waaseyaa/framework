# Inertia Demotion + Nuxt Standardisation — ratify DIR-007 in the framework manifest

**Mission:** `inertia-demotion-nuxt-standardisation-01KSEFTS`
**Status:** Spec
**Target branch:** `main`
**Tracks:** No GitHub issue at filing time. The mission is the source-of-truth for execution. The downstream effect is constitutional: ratifying DIR-007 in code + manifest is the signal every distribution maintainer (Anokii first) reads.
**Pattern reference:** M5A `ai-observability-dashboard-01KSE9BX` for spec/plan/tasks/wps.yaml shape. This mission has **no code-change WPs** — only documentation + composer-manifest edits. Closest reference for shape is the charter-amendment mission (`charter-amendment-anokii-track-01KSEFE0`), which is also documentation-only, but with a single WP; this mission carries three because the source-of-truth lives in the package README, not the charter.

## Why this mission exists

DIR-007 (per the parallel `charter-amendment-anokii-track-01KSEFE0` mission) commits the framework to the **Standalone Nuxt SPA bet**: the Nuxt 3 + Vue 3 + TypeScript admin SPA in `packages/admin/` is the committed workspace UI surface. `packages/inertia` is the alternative protocol adapter the framework explicitly carried "for a deliberate decision before v0.1 SPA expansion" (assumptions.md). That deliberate decision is now made: **Nuxt wins; Inertia stays in the tree as an optional/experimental protocol adapter for distributions that prefer server-driven UI**.

This mission converts the constitutional commitment into manifest reality:

1. **The README is the source-of-truth for a package's status.** `packages/inertia/README.md` currently presents Inertia as a peer L6 surface with no signal that the Nuxt SPA is the primary bet. A reader installing the framework for the first time has no way to know which surface to commit to. This mission adds a prominent banner.
2. **The `waaseyaa/full` metapackage signals "the recommended bundle."** Any package required by `waaseyaa/full` is, by definition, "what the framework recommends you install." If `waaseyaa/inertia` is required by `full`, the framework is recommending Inertia. After this mission, `full` requires only the Nuxt-aligned surfaces; Inertia moves to `suggest`.
3. **Spec and skill orchestration must point readers to Nuxt as primary.** Today, `docs/specs/admin-spa.md` covers the Nuxt SPA but does not explicitly carry the constitutional commitment. CLAUDE.md's orchestration table lists `packages/inertia/*` with no specialist skill (correctly — there's no `waaseyaa:inertia` skill); we add an explicit "Inertia is optional/experimental — see DIR-007" note to admin-spa.md so anyone routing to the spec via the table gets the framing.

This mission does NOT remove any code. The `packages/inertia/` source tree, tests, service provider, and existing API stay exactly as-is — Inertia remains a fully working L6 adapter for distributions that want it. The change is purely about **manifest semantics + reader signal**: `full` no longer pulls it; the README leads with the alternative-protocol banner; the admin-spa spec documents the bet.

## Scope

### In scope

**WP01 — `packages/inertia/README.md` banner + status section (source-of-truth edit):**
- Insert at the very top of the README (immediately after the `# waaseyaa/inertia` H1, before the existing "**Layer 6 — Interfaces**" subhead) a prominent banner:
  ```
  > **Alternative protocol — not the primary workspace UI.**
  >
  > Per charter directive **DIR-007** (see `.kittify/charter/charter.md`), the framework's
  > committed workspace UI surface is the standalone Nuxt SPA in `packages/admin/`.
  > `waaseyaa/inertia` remains supported as an **optional / experimental** L6 protocol
  > adapter for distributions that prefer server-driven UI. It is not bundled by
  > `waaseyaa/full`; install it explicitly when your distribution chooses Inertia.
  ```
- Append a new `## Status` section after the existing class summary, documenting:
  - **Stability:** optional / experimental. API surface frozen; no new feature work planned in framework cadence; community contributions accepted under the same review bar.
  - **Bundle membership:** suggested by `waaseyaa/full` (not required). To install: `composer require waaseyaa/inertia`.
  - **Decision provenance:** DIR-007 (ratified by mission `charter-amendment-anokii-track-01KSEFE0`); manifest update by mission `inertia-demotion-nuxt-standardisation-01KSEFTS`.

**WP02 — `packages/full/composer.json` + `packages/inertia/composer.json` manifest changes:**
- `packages/full/composer.json`: remove `"waaseyaa/inertia"` from `require` (if present); add it to `suggest` with a description: `"Server-side Inertia.js v3 protocol adapter (L6, optional). Install if your distribution prefers server-driven UI over the standalone Nuxt SPA."`.
- `packages/inertia/composer.json`: NO functional change to require / require-dev. Add a `"description"` clarification if the existing description doesn't already signal optional status (read first; update only if needed).
- Run `composer update --lock waaseyaa/inertia waaseyaa/full` from the repo root to refresh `composer.lock` to reflect the dependency-graph change. **Verify `bin/check-composer-policy` and `bin/check-package-layers` remain green.** No `require-dev` changes; layer rules are unaffected because Inertia stays at L6.

**WP03 — Documentation sweep + spec stamping:**
- `docs/specs/admin-spa.md` — add a new section `## SPA bet (DIR-007)` after the existing introduction, documenting:
  - The framework commits to the standalone Nuxt 3 + Vue 3 + TypeScript SPA as the workspace UI.
  - `packages/inertia` is the alternative protocol adapter, retained as optional / experimental per DIR-007.
  - Changes to this commitment require a charter amendment, not just a spec edit.
  - Cross-reference: `packages/admin/README.md` (canonical Nuxt SPA entrypoint), `packages/inertia/README.md` (alternative status), `.kittify/charter/charter.md` (DIR-007 text).
  - Stamp the file with `<!-- Spec reviewed YYYY-MM-DD - inertia-demotion-nuxt-standardisation-01KSEFTS - WP03 - SPA bet section -->`.
- Audit `docs/specs/*.md` for any reference to Inertia as a primary or recommended surface. Where found, edit to clarify it is optional. Owned files list below enumerates only those known to potentially reference Inertia; the actual audit may touch fewer or more — the implementer adds each one to the owned files list at edit time and records the result in the commit message.
- `packages/admin/README.md` — add a "Primary workspace UI per DIR-007" sentence at the top (one line; pointer-only — the README already describes what the SPA does).
- CLAUDE.md orchestration table — verify the existing line `| \`packages/inertia/*\` | — | \`packages/inertia/README.md\` |` is correct (no specialist skill; just a README pointer). **No edit required if already correct;** the mission's `owned_files` includes `CLAUDE.md` only as a verification target, not an edit target. If a change is needed, document it in the commit.
- `CHANGELOG.md` `[Unreleased]` → **Changed**:
  - `Demoted waaseyaa/inertia from waaseyaa/full require to suggest (DIR-007 ratification). The package remains supported; it is no longer in the recommended bundle.`

### Out of scope

- Removing any code from `packages/inertia/`. The package stays fully functional.
- Deprecating or removing `InertiaServiceProvider`, `Inertia::render()`, `OptionalProp`, or any other Inertia-package symbol.
- Editing `packages/inertia/`'s tests, fixtures, or composer.json `require` / `require-dev` blocks.
- Any change to consumer-app code that uses Inertia today.
- Reframing the parallel GraphQL demotion (handled by `api-surface-consolidation-jsonapi-primary-01KSEFTV`).
- Any change to `.kittify/charter/charter.md` (handled by `charter-amendment-anokii-track-01KSEFE0`).
- Changes to `docs/adr/` (this is a manifest+doc edit, not a fresh architectural decision; the ADR lives implicitly in the charter and the parallel charter-amendment mission's plan).

## Requirements

| ID | Type | Requirement |
|---|---|---|
| FR-001 | functional | `packages/inertia/README.md` carries the "Alternative protocol — not the primary workspace UI" banner as the first block after the H1, with verbatim text from plan.md §1. The banner is a Markdown blockquote (`>`) and references DIR-007 + `packages/admin/`. |
| FR-002 | functional | `packages/inertia/README.md` carries a `## Status` section documenting stability (optional/experimental), bundle membership (suggested by `waaseyaa/full`, not required), and decision provenance (DIR-007 + this mission slug). |
| FR-003 | functional | `packages/full/composer.json` does NOT list `waaseyaa/inertia` in `require`. It DOES list `waaseyaa/inertia` in `suggest` with the description text from plan.md §2. |
| FR-004 | functional | `composer.lock` is regenerated after the manifest change so the dependency graph reflects the demotion. `composer install` on a fresh checkout no longer pulls `waaseyaa/inertia` via the `full` metapackage. |
| FR-005 | functional | `docs/specs/admin-spa.md` carries a `## SPA bet (DIR-007)` section documenting the Nuxt commitment + Inertia's optional status, and is stamped with the mission slug. |
| FR-006 | functional | `packages/admin/README.md` carries a one-line "Primary workspace UI per DIR-007" attribution at the top (or already does — verify, do not duplicate). |
| FR-007 | functional | Every `docs/specs/*.md` file mentioning Inertia is audited; any reference framing Inertia as primary or recommended is rewritten to clarify it is optional. The commit message enumerates each file edited (or "no spec edits required — audit found no problematic references"). |
| FR-008 | functional | `CHANGELOG.md` `[Unreleased]` → **Changed** records the demotion using the verbatim text from spec.md "Scope → WP03 → CHANGELOG". |
| NFR-001 | non-functional | The mission is purely additive in `packages/inertia/` source: no code, no tests, no service provider, no entity, no migration is added, removed, or modified. Only `README.md` (+ optionally `composer.json` description-only). |
| NFR-002 | non-functional | All codified-policy gates remain green after the change: `composer cs-check`, `composer phpstan`, `bin/check-composer-policy`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`. No new findings, no baseline regressions. |
| NFR-003 | non-functional | The mission's three WPs share a single PR (or three commits on one branch); their order is WP01 (README banner — source-of-truth lands first) → WP02 (manifest) → WP03 (docs sweep). WP02 and WP03 MAY run in parallel after WP01 lands; WP02 must not land before WP01 (the README banner is what FR-003's `suggest` description points to as "see the package README"). |
| C-001 | constraint | No code deletion. Implementer who is tempted to "tidy up" Inertia source MUST NOT — that is a separate, charter-amendment-bearing decision, not this mission. |
| C-002 | constraint | No changes to consumer apps. The framework remains backward-compatible: existing distributions that `require waaseyaa/full` and then explicitly `require waaseyaa/inertia` separately continue to work; distributions that relied on the transitive pull through `full` will get a single `composer require` to add it explicitly. The CHANGELOG entry is the migration note. |
| C-003 | constraint | The banner text is exact-match against plan.md §1. The `suggest` description text is exact-match against plan.md §2. No paraphrasing — these are the canonical constitutional signals. |

## Acceptance

- All 8 FRs met. The README banner, manifest demotion, spec stamping, and CHANGELOG entry are all in place on `main`.
- All codified gates green (NFR-002).
- `git diff --stat` for the mission's merge shows:
  - `packages/inertia/README.md` (+lines, banner + Status section).
  - `packages/full/composer.json` (1 line removed from `require`, lines added to `suggest`).
  - `composer.lock` (regenerated).
  - `docs/specs/admin-spa.md` (+lines, SPA bet section + stamp).
  - `packages/admin/README.md` (one line, if not already present).
  - `CHANGELOG.md` (one Changed entry).
  - Optionally other `docs/specs/*.md` files identified by the WP03 audit.
- `git diff packages/inertia/src` and `git diff packages/inertia/tests` are both empty (C-001).
- `git diff packages/inertia/composer.json` shows at most a `description` edit, never a `require` / `require-dev` change (NFR-001).
- The Charter Amendment mission's WP01 (`charter-amendment-anokii-track-01KSEFE0`) has landed first so DIR-007 exists as a referenceable identifier when this mission cites it. (Cross-mission ordering — confirmed by the wave plan; not a same-PR dependency.)

## Risks

- **Implementer assumes "demotion" means "remove code" (primary).** The strongest risk is well-intentioned tidying. C-001 + the WP02 owned-files allowlist (which does NOT include `packages/inertia/src/**`) are the explicit guard; reviewer checks `git diff --stat` to confirm zero diff in `packages/inertia/src` and `tests`.
- **The README banner is paraphrased.** A reviewer who reads the banner casually may not notice the exact wording matters. C-003 makes the text verbatim; reviewer diffs the README banner against plan.md §1 character-for-character.
- **`composer.lock` not regenerated.** If the manifest change ships but the lock is stale, a fresh `composer install` will still pull Inertia transitively for several days until the lock catches up. FR-004 makes lock regeneration explicit.
- **`bin/check-composer-policy` rejects the change.** The policy enforces `sort-packages: true` and forbids wildcards. Moving from `require` to `suggest` should be policy-clean (suggest is a free-form key:value block), but reviewer should run the gate before merge.
- **The audit misses a `docs/specs/*.md` reference to Inertia.** The mission accepts the risk: if a spec is found later, it is a one-line follow-up edit, not a constitutional re-vote. FR-007 enumerates the audited files in the commit, providing a paper trail.
- **CLAUDE.md is edited unnecessarily.** The orchestration table already correctly lists Inertia with no specialist skill (just a README link). NFR-001's "purely additive" wording could be read as forbidding even consequence-free CLAUDE.md edits — clarification: CLAUDE.md is allowed if the WP03 audit finds a substantive issue, but the default is no edit.

## Decisions pre-resolved

- **Demotion, not removal.** Removing Inertia outright was rejected (see assumptions.md note that Inertia is a "genuinely interesting alternative bet for the workspace SPA" and that server-driven UI has real advantages for large permission trees). Demotion preserves the option for distributions that need it while committing the framework's investment to Nuxt — per the decision-preference order, this minimises vendor lock-in (distributions are not forced into Nuxt; they can choose).
- **`suggest`, not `replace`, not `conflict`.** `suggest` is the Composer convention for "this works with the bundle but you must opt in." `replace` would have meant `full` replaces Inertia entirely (wrong — Inertia is not being replaced by anything; it's just being opted-out by default). `conflict` would have made `full` and `inertia` mutually exclusive (wrong — distributions can install both).
- **No code deprecation markers.** Adding `@deprecated` to `InertiaServiceProvider` would imply the package is on a removal track. It isn't. The README banner is the correct signal.
- **One mission, not three separate ones.** README + manifest + docs sweep are conceptually one act of ratification; splitting them across separate missions would invite the manifest change to land without the README signal (or vice versa). Three WPs in one mission preserves the unity while permitting parallel execution after WP01.

## Decisions deferred to implementer

- The exact wording of any `docs/specs/*.md` edit found by the WP03 audit. The implementer reads each match in context and decides whether the existing wording already conveys "Inertia is optional" or needs clarification.
- Whether `packages/inertia/composer.json`'s existing `description` field needs an "optional adapter" word. Read it first; edit only if it currently signals primary status.

Decision preference order (per charter): preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Out-of-band

- No follow-up issues required. This mission completes the DIR-007 ratification in the framework manifest.
- If a future distribution wants to revive Inertia as a primary surface, that is a charter-amendment decision (DIR-007 explicitly says so).
- If `composer.lock` regeneration surfaces a transitive dependency change unrelated to Inertia, file a separate "lock-file refresh" mission for it; do not bundle into this mission.
