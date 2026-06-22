# Succession Framework — Tier 1 Publishing

**Mission:** `succession-framework-tier1-publishing-01KSEFV6`
**Status:** Spec
**Target branch:** `main`
**Tracks:** No GitHub issue (governance housekeeping; not a release item). Records the publication of the Tier 1 succession floor as proposed in `assumptions.md` §4 and detailed in `design-succession-framework.md`.
**Pattern reference:** `charter-amendment-anokii-track-01KSEFE0` for the documentation-edit shape (text-blocks-in-plan, implementer applies verbatim) plus `ai-observability-dashboard-01KSE9BX` for multi-WP wps.yaml / tasks structure.

## Why this mission exists

Nations adopting Waaseyaa on owned hardware will ask "what happens if Russell stops" during procurement. The answer must be published *before* the question is asked. Tier 0 (the trust substrate — codified CI gates, spec discipline, agent onboarding, GPL-2.0-or-later licensing) **already exists in the codebase**, but it is not procurement-legible: a Nation procurement officer cannot verify Tier 0 without reading the codebase.

This mission publishes the **Tier 1 floor** — the four near-term moves that make Tier 0 procurement-legible without organizational ceremony:

1. **`MAINTAINERS.md`** at repo root — enumerates Tier 0 substrate with file pointers so a procurement officer can verify continuity claims without reading the codebase. Lists current maintainers, their scope, decision authority, and the escalation path if a maintainer becomes unavailable.
2. **`SUCCESSION.md`** at repo root — describes the framework's continuity story across Tiers 1–4 (refined from `assumptions.md` §4 and the design doc's Tier-0-through-Tier-4 framing). Documents who acts at each tier, what triggers tier transitions, what guarantees Nations receive at each tier.
3. **Packagist namespace trustee** — designates a SECOND account with publish rights on the `waaseyaa/*` Packagist namespace. Protects against the "Russell loses access" scenario. Documented in `MAINTAINERS.md`.
4. **Nation-hosted mirror repo** — mirrors the framework repo on a Nation-owned Git forge (an existing tenant-hosted Gitea/Forgejo). Protects against the "GitHub disappears" scenario. Documented in `MAINTAINERS.md`.

These four artifacts are the Tier 1 floor: practical, available before v0.1 ships, no organizational ceremony required. Tiers 2–4 (an existing tenant stewards committee, contractor pool, federated maintainer network, full multi-org governance vehicle) come later — after v1.0 and as adoption grows. Tier 1 must land first because every downstream tier reads from `MAINTAINERS.md` and `SUCCESSION.md` as the canonical maintainer roster and continuity narrative.

The mission ratifies DIR-006 (codified policy gates as trust substrate) **operationally**: DIR-006 declares that the `bin/check-*` family is the procurement-legible surface of the framework's invariants; this mission publishes the documents that point a procurement officer at that surface and explain how to read it without source-code archaeology.

## Scope

### In scope

- Author NEW file `/MAINTAINERS.md` at the repo root. Sections per `plan.md` §1 (block authored verbatim in plan.md; implementer applies as-written, substituting deferred values for trustee name + mirror URL at execution time).
- Author NEW file `/SUCCESSION.md` at the repo root. Sections per `plan.md` §2 (block authored verbatim in plan.md; implementer applies as-written).
- Configure a Packagist trustee account on the `waaseyaa/*` namespace per `plan.md` §3 (account selection deferred to Russell; documentation of the steps + recording the chosen account in `MAINTAINERS.md` is the WP deliverable).
- Configure a read-only mirror of `github.com/waaseyaa/framework` on a Nation-owned Git forge per `plan.md` §4 (forge selection deferred to Russell; documentation of sync mechanism + recording the chosen mirror URL in `MAINTAINERS.md` is the WP deliverable).
- Cross-link `MAINTAINERS.md` ↔ `SUCCESSION.md` ↔ DIR-006 (charter directive) such that a reader entering at any of the three reaches the others.
- Add a one-line pointer to `MAINTAINERS.md` / `SUCCESSION.md` in the repo `README.md` so a first-time reader of the repo reaches the maintenance posture without searching.

### Out of scope

- Tier 2 work (an existing tenant stewards committee charter, `docs/governance/stewards-charter.md`). Defer to a post-v1.0 mission.
- Tier 3 work (NorthOps contractor pipeline, `docs/governance/package-owners.md` per-package owner roster). Defer to a funded-engagement mission.
- Tier 4 work (Indigenous-tech foundation, Apache incubation option analysis, `docs/governance/foundation-options.md`). Defer to a long-horizon governance mission.
- Bus-factor CI job that counts authors per package and flags HIGH RISK packages. Defer to a separate mission (design doc Bridge Mechanism #4).
- Succession runbook (`docs/governance/succession-runbook.md` with 48-hour and 1-week triage timelines). Defer to a separate mission (design doc Bridge Mechanism #3) — `MAINTAINERS.md` carries a minimal escalation pointer; the full rehearsed runbook is its own artifact.
- Any change to charter directives DIR-001..DIR-008. The charter is authoritative; this mission references DIR-006 but does not amend it.
- Any change to `bin/check-*` gates themselves. The gates are authoritative; this mission documents them as the trust substrate but adds no new gate.
- Any code change. This mission produces two new Markdown files at the repo root, a one-line `README.md` edit, plus two configuration deliverables (Packagist trustee + mirror) that are external to the codebase but documented within `MAINTAINERS.md`.

## Requirements

| ID | Type | Requirement |
|---|---|---|
| FR-001 | functional | A new file `/MAINTAINERS.md` MUST exist at the repo root. Its content matches the block in `plan.md` §1 byte-for-byte except for the two implementer-substituted values (trustee Packagist account name in §1.A; mirror URL in §1.B), which MUST be filled in at execution time with real values (no `<TBD>` / placeholder text). |
| FR-002 | functional | A new file `/SUCCESSION.md` MUST exist at the repo root. Its content matches the block in `plan.md` §2 byte-for-byte. No implementer substitutions. |
| FR-003 | functional | `MAINTAINERS.md` MUST contain a "Tier 0 substrate inventory" section that enumerates the current `bin/check-*` family (`check-composer-policy`, `check-package-layers`, `check-dead-code`, `check-getquery-bindings`, `check-ingestion-defaults`, `check-no-secrets`, `check-openapi`, `check-admin-coercion-patterns`, `check-monorepo-release-shape`, `check-phpstan`, `check-release-tag-parity`, `check-symfony-imports`, `check-external-consumers`), the audit tools (`audit-composer-deps`, `audit-require-dev-layers`), and `tools/drift-detector.sh`, each with a one-line description of what the gate enforces. Inventory date `(as of <YYYY-MM-DD>)` is recorded so future audits know when the list was last reconciled. |
| FR-004 | functional | `MAINTAINERS.md` MUST contain a "Current maintainers" section listing Russell Jones as primary maintainer (with affiliation Pilot Nation A Anishnawbek / an existing tenant) and the designated Packagist trustee account (FR-007) by Packagist username and one-line scope description ("publish rights on `waaseyaa/*` namespace; activated if primary maintainer becomes unavailable"). |
| FR-005 | functional | `MAINTAINERS.md` MUST contain a "Decision authority" section stating who can merge to `main` (primary maintainer; trustee in escalation), who can publish releases (primary maintainer; trustee in escalation), and a one-paragraph escalation procedure ("If the primary maintainer is unavailable for more than 14 calendar days and no holiday/leave was pre-announced, the trustee is authorised to publish patch releases and merge security fixes to `main`; downstream Nations notified via the Nation-hosted mirror's news feed"). |
| FR-006 | functional | `MAINTAINERS.md` MUST contain a "Nation-hosted mirror" section recording the mirror forge URL (substituted at execution time per FR-009), the sync mechanism (GitHub Actions push-on-merge to `main`, or equivalent), the sync cadence ("on every push to `main`; at minimum nightly"), and the recovery procedure if GitHub becomes unavailable ("Nations adopt the mirror as the new origin; the trustee account holds publish rights on the mirror as well; releases continue from the mirror with no namespace change"). |
| FR-007 | functional | A second Packagist account with publish rights on the `waaseyaa/*` namespace MUST be designated and the account name MUST appear in `MAINTAINERS.md` under "Current maintainers" (FR-004). The Packagist owner-list on packagist.org/packages/waaseyaa/framework (and the sibling `waaseyaa/*` packages) MUST include this account by the time WP03 moves to `for_review`. |
| FR-008 | functional | `SUCCESSION.md` MUST document all five tiers (Tier 0 through Tier 4) with one paragraph per tier covering: (a) the tier's shape; (b) who acts at the tier; (c) what triggers transition to the next tier; (d) what Nations get at the tier (procurement-legible commitment). Tier 0 references the trust-substrate inventory in `MAINTAINERS.md`; Tier 1 cites this mission as its delivery vehicle; Tiers 2–4 are described as "deferred to post-v1.0 missions" with no implementation commitment in this mission. |
| FR-009 | functional | A read-only mirror of `github.com/waaseyaa/framework` MUST be operational on a Nation-owned Git forge (Gitea or Forgejo on a Nation-controlled or an existing tenant-controlled host) by the time WP04 moves to `for_review`. The mirror URL MUST be recorded in `MAINTAINERS.md` per FR-006. Sync MUST be automated (no manual `git push` step in the steady state). |
| FR-010 | functional | `MAINTAINERS.md` and `SUCCESSION.md` MUST cross-reference each other and both MUST reference DIR-006 (codified policy gates as trust substrate) in `.kittify/charter/charter.md` by directive number ("see DIR-006 in the project charter"). |
| FR-011 | functional | The repo `README.md` MUST gain a one-line pointer to `MAINTAINERS.md` and `SUCCESSION.md` so a fresh reader of the repo reaches the maintenance posture without searching. Exact text: `> Governance: see [MAINTAINERS.md](MAINTAINERS.md) for the current maintainer roster and [SUCCESSION.md](SUCCESSION.md) for the framework's continuity plan across Tiers 0–4.` Insertion point: immediately after the existing badge/header block, before the first prose section. |
| NFR-001 | non-functional | Every WP MUST land as a single atomic commit on `main`. Commit messages: WP01 `docs(governance): publish MAINTAINERS.md (succession-framework-tier1-publishing-01KSEFV6 WP01)`; WP02 `docs(governance): publish SUCCESSION.md (succession-framework-tier1-publishing-01KSEFV6 WP02)`; WP03 `docs(governance): record Packagist trustee in MAINTAINERS.md (succession-framework-tier1-publishing-01KSEFV6 WP03)`; WP04 `docs(governance): record Nation-hosted mirror in MAINTAINERS.md (succession-framework-tier1-publishing-01KSEFV6 WP04)`. |
| NFR-002 | non-functional | No `<TBD>` / `TODO` / `_placeholder_` / unresolved bracket-tag content in any inserted block at WP-completion time. Every clause is final text. The two deferred values (trustee account name in WP03; mirror URL in WP04) MUST be substituted with real values before their respective WPs move to `for_review`. |
| NFR-003 | non-functional | The tone of `MAINTAINERS.md` and `SUCCESSION.md` MUST match the tone of `.kittify/charter/charter.md` (authoritative, MUST/MUST NOT where binding, concrete consequences, no marketing prose, no hedging in normative clauses). |
| NFR-004 | non-functional | The Packagist trustee designation (WP03) MUST preserve the OCAP audit lineage on the namespace — the trustee inherits publish rights but the namespace owner remains the primary maintainer; "trustee" is an additional publisher, not a transfer. (Stated explicitly so that a future trustee turnover does not accidentally cycle ownership.) |
| NFR-005 | non-functional | The Nation-hosted mirror (WP04) MUST be read-only from the mirror side in the steady state — pushes flow from GitHub to the mirror, never the reverse. The mirror is a continuity artifact, not a co-primary; this prevents accidental write-divergence. The recovery procedure (FR-006) is the documented exception. |
| C-001 | constraint | WP01 produces exactly TWO new files (`/MAINTAINERS.md`, `/SUCCESSION.md` are split across WP01 and WP02) plus one line of edit to `/README.md`. WP02 produces one new file (`/SUCCESSION.md`). WP03 and WP04 each modify EXACTLY ONE file (`/MAINTAINERS.md`) plus zero or one configuration artifact external to the repo (Packagist account membership; mirror sync configuration). |
| C-002 | constraint | All four WPs are purely additive at the repo level: no existing file is rewritten, reworded, removed, or reordered except `README.md` which gains exactly one line per FR-011, and `MAINTAINERS.md` which is appended-to (not rewritten) by WP03 and WP04. |
| C-003 | constraint | The mission MUST NOT modify `bin/check-*` scripts, `tools/drift-detector.sh`, `.kittify/charter/charter.md`, any `composer.json`, any `LICENSE`, or any package source. The mission is documentation + external configuration only. |
| C-004 | constraint | The trustee account (WP03) and the mirror forge (WP04) MUST be selected by Russell at execution time. The spec gives selection criteria and categories; it does NOT name a specific account or forge. The WP cannot move to `for_review` until the chosen values are recorded in `MAINTAINERS.md`. |
| C-005 | constraint | Implementer preference order, applied to every choice made during this mission: **(1) preserve OCAP audit lineage > (2) minimise vendor lock-in > (3) don't break codified policy gates.** Concretely: the trustee designation MUST NOT consolidate publish history into a single non-primary account (preserves OCAP lineage); the mirror MUST run on FOSS forge software, not a vendor-locked SaaS (minimises vendor lock-in); no edit to `bin/check-*` or `tools/drift-detector.sh` is allowed (don't break gates). |

## Acceptance

- `test -f /home/fsd42/dev/waaseyaa/MAINTAINERS.md` returns 0.
- `test -f /home/fsd42/dev/waaseyaa/SUCCESSION.md` returns 0.
- `grep -c "Tier 0 substrate inventory" MAINTAINERS.md` returns `1`.
- `grep -c "Current maintainers" MAINTAINERS.md` returns `1`.
- `grep -c "Decision authority" MAINTAINERS.md` returns `1`.
- `grep -c "Nation-hosted mirror" MAINTAINERS.md` returns `1`.
- `grep -cE "^## Tier [01234]" SUCCESSION.md` returns `5` (one heading per tier).
- `grep -c "DIR-006" MAINTAINERS.md` returns at least `1`.
- `grep -c "DIR-006" SUCCESSION.md` returns at least `1`.
- `grep -c "SUCCESSION.md" MAINTAINERS.md` returns at least `1`.
- `grep -c "MAINTAINERS.md" SUCCESSION.md` returns at least `1`.
- `grep -c "MAINTAINERS.md" README.md` returns at least `1`.
- `grep -c "SUCCESSION.md" README.md` returns at least `1`.
- `grep -cE "TBD|TODO|_placeholder_|<placeholder>" MAINTAINERS.md SUCCESSION.md` returns `0`.
- A trustee account is listed on packagist.org/packages/waaseyaa/framework (and at least one other `waaseyaa/*` package) by the time WP03 is in `for_review`. (Manual verification by reviewer; trustee account name in `MAINTAINERS.md` matches the Packagist owner-list entry.)
- The mirror URL recorded in `MAINTAINERS.md` resolves to a live read-only mirror of `github.com/waaseyaa/framework` by the time WP04 is in `for_review`. (Manual verification by reviewer; `git ls-remote <mirror-url>` returns the same `main` ref as `git ls-remote https://github.com/waaseyaa/framework`.)
- Reviewer (Opus) can read the spec, the plan, and the published files and confirm every clause in `MAINTAINERS.md` / `SUCCESSION.md` is justifiable by the strategic context (`assumptions.md` §4, `design-succession-framework.md`) and the existing constitutional logic of DIR-006.

## Risks

- **Trustee selection delays mission close.** Russell may need to consult an existing tenant leadership before naming a trustee. Mitigation: WP01 + WP02 + the documentation portion of WP03/WP04 can land independently; the trustee-account-on-Packagist and mirror-on-forge tasks within WP03/WP04 are split into a "documentation step" (writes the section in MAINTAINERS.md with the chosen value) and an "operational step" (configures the Packagist owner list / forge mirror) so the WP can be paused after documentation if Russell needs more time to confirm the chosen entity.
- **Mirror forge selection forces a tooling decision that outlives the mission.** Once the an existing tenant-hosted Gitea/Forgejo instance is chosen, switching later requires re-coordinating with Nations who have begun depending on the mirror URL. Mitigation: spec restricts the choice to FOSS forge software (per C-005) so any future move stays within the same data-portability envelope (git is git); the URL is recorded in MAINTAINERS.md with an explicit "Mirror forge selection rationale" footnote so a future change has the original reasoning available.
- **MAINTAINERS.md drift vs codebase reality.** Tier 0 substrate inventory enumerates current gate scripts; new gates added later (per DIR-006 "Adding a gate is encouraged and requires no amendment") would make the inventory stale. Mitigation: the inventory carries an explicit `(as of <YYYY-MM-DD>)` date stamp per FR-003; future gate-additions include a `MAINTAINERS.md` inventory-refresh commit in the same PR (added as a follow-up convention in WP01's `MAINTAINERS.md` "Inventory maintenance" subsection). `tools/drift-detector.sh` already exits non-zero on stale specs; adding `MAINTAINERS.md` to its watch-list is a separate follow-up mission.
- **DIR-006 cross-reference becomes brittle if directive numbering changes.** If a future charter amendment renumbers directives, the "DIR-006" references in `MAINTAINERS.md` / `SUCCESSION.md` go stale. Mitigation: DIR-006 is already ratified in the current charter (per `charter-amendment-anokii-track-01KSEFE0`) and the charter's Amendment History records that ratification; any future renumbering would itself require an amendment, and the amendment template can be extended at that time to include a sweep for cross-references. Acceptable risk; not blocking.
- **README.md insertion (FR-011) collides with future README rewrites.** A future README refresh might lose the pointer. Mitigation: the pointer is one line, immediately after the badge/header block, so it is hard to miss in a README diff review; if it is dropped, the missing reference is caught by `tools/drift-detector.sh` once `MAINTAINERS.md` / `SUCCESSION.md` are added to its watch-list (follow-up).

## Decisions pre-resolved

- **Four WPs, dependency shape `WP01 || WP02` then `WP03 → WP01` then `WP04 → WP01,WP03`.** WP01 and WP02 are parallelisable (independent new files). WP03 and WP04 both append a section to `MAINTAINERS.md`, so they are serial-after-WP01 (and serial-with-each-other to avoid append-conflict on the same file).
- **`MAINTAINERS.md` and `SUCCESSION.md` content is drafted in `plan.md` as code blocks the implementer applies verbatim.** Same pattern as `charter-amendment-anokii-track-01KSEFE0/plan.md` §1/§2/§3 — the implementer does not author from scratch; they apply blocks.
- **README.md pointer (FR-011) is folded into WP01's commit**, not split into its own WP. Rationale: it is one line; splitting it would balloon the WP count for no review benefit.
- **No `docs/governance/` directory is created in this mission.** The Tier 1 floor lives at the repo root because root-level files are what procurement officers grep first. Tiers 2–4 artifacts (future missions) populate `docs/governance/` when they ship.
- **The mission references DIR-006 specifically** (not "the project charter generally") because DIR-006 is the directive that calls out the codified-gates-as-trust-substrate logic this mission operationalises. Other directives (DIR-001..DIR-008) are not referenced because they govern other concerns.

## Decisions deferred to implementer

- **Specific trustee Packagist account name.** Russell selects at WP03 execution time. Spec gives selection criteria (active Packagist account; 2FA-enabled; held by an individual or organisation Russell trusts to publish security fixes on `waaseyaa/*` if primary maintainer is unavailable for >14 days; candidate categories: an existing tenant technical lead, long-term external contributor, academic-institution partner) but does NOT name an account. The chosen name is substituted into `MAINTAINERS.md` per the placeholder annotated in `plan.md` §1.A.
- **Specific Nation-owned forge URL.** Russell selects at WP04 execution time. Spec gives selection criteria (Nation-controlled or an existing tenant-controlled host; FOSS forge software — Gitea or Forgejo; HTTPS-accessible URL; supports webhook-driven mirror or polling-based mirror; held under a domain a Nation procurement officer would recognise as Nation-controlled — e.g. `git.<oiatc-or-nation-domain>.ca` rather than a SaaS subdomain) but does NOT name a host. The chosen URL is substituted into `MAINTAINERS.md` per the placeholder annotated in `plan.md` §1.B.
- **The exact `(as of <YYYY-MM-DD>)` date stamp in the Tier 0 substrate inventory** — use `date -u +"%Y-%m-%d"` at edit time.
- **The exact line in `README.md` where the pointer is inserted** (FR-011) — implementer reads the existing README, identifies the post-header anchor, and inserts. If the documented anchor (immediately after badge/header block, before first prose section) cannot be located unambiguously, the implementer surfaces the discrepancy and pauses rather than improvising.

Decision preference order (re-stated for the implementer at every choice point per C-005): preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Out-of-band

- No follow-up GitHub issue. Governance housekeeping is not a release item.
- Follow-up missions queued by this one but explicitly out of scope here:
  - Bus-factor CI job + `docs/adr/adr-NNNN-bus-factor-targets.md` (design doc Bridge Mechanism #4).
  - Succession runbook with 48-hour / 1-week triage timelines (`docs/governance/succession-runbook.md`; design doc Bridge Mechanism #3).
  - Per-package owner roster (`docs/governance/package-owners.md`; design doc Bridge Mechanism #1; Tier 3 prep).
  - Stewards-charter draft (`docs/governance/stewards-charter.md`; Tier 2 prep, post-v1.0).
  - Foundation-options analysis (`docs/governance/foundation-options.md`; Tier 4 prep, multi-year horizon).
  - Adding `MAINTAINERS.md` and `SUCCESSION.md` to `tools/drift-detector.sh` watch-list so future codebase changes that invalidate the substrate inventory get flagged.
