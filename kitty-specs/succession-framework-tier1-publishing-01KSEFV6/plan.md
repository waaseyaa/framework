# Implementation Plan: Succession Framework — Tier 1 Publishing

**Mission:** `succession-framework-tier1-publishing-01KSEFV6` — see `spec.md`.
**Pattern reference:** Verbatim-block-application, same shape as `charter-amendment-anokii-track-01KSEFE0/plan.md` §1/§2/§3. The implementer does not author from scratch; they apply the blocks below.
**Four WPs.** WP01 (`MAINTAINERS.md` + README pointer) and WP02 (`SUCCESSION.md`) are parallelisable. WP03 (Packagist trustee) depends on WP01. WP04 (mirror) depends on WP01 + WP03 (serial-on-MAINTAINERS.md to avoid append-conflict).

The implementer's job per WP: read the existing repo state, apply the block below verbatim (substituting the two deferred values at WP03/WP04 time), commit atomically, run the verification grep checks, hand off.

## §1 — `MAINTAINERS.md` (WP01 — full file content)

Create new file `/MAINTAINERS.md` at the repo root with exactly this content. The two annotated placeholders (`<<TRUSTEE_PACKAGIST_ACCOUNT>>` in the "Current maintainers" table and `<<NATION_HOSTED_MIRROR_URL>>` in the "Nation-hosted mirror" section) are left as marker tokens at WP01 close — they will be substituted by WP03 and WP04 respectively. WP01 itself only writes the file with the marker tokens in place; WP01 does NOT move the mission to `for_review` until WP03 and WP04 have completed the substitutions. (See spec NFR-002: no marker tokens may remain at WP-completion time for WP03/WP04.)

The `<as of YYYY-MM-DD>` token in the Tier 0 substrate inventory IS substituted by WP01 at edit time using `date -u +"%Y-%m-%d"`.

````markdown
# Maintainers

This file enumerates the Waaseyaa framework's current maintainers, the Tier 0 trust substrate that underwrites the framework's continuity claim, and the decision and escalation authority that governs day-to-day operations.

Companion document: see [SUCCESSION.md](SUCCESSION.md) for the multi-tier continuity narrative this file's roster operationalises.
Constitutional source: see DIR-006 in [`.kittify/charter/charter.md`](.kittify/charter/charter.md) for the codified-gates-as-trust-substrate directive that this file makes procurement-legible.

## Current maintainers

| Role | Name / account | Affiliation | Scope |
|---|---|---|---|
| Primary maintainer | Russell Jones (`jonesrussell`) | Pilot Nation A Anishnawbek / an existing tenant | Merge authority on `main`; release authority on every `waaseyaa/*` Packagist package; charter amendment authority per the charter's Amendment Process. |
| Packagist trustee | `<<TRUSTEE_PACKAGIST_ACCOUNT>>` | (recorded at trustee designation by WP03 of `succession-framework-tier1-publishing-01KSEFV6`) | Publish rights on `waaseyaa/*` namespace; activated if the primary maintainer becomes unavailable per the escalation procedure below. The trustee is an additional publisher; the namespace owner remains the primary maintainer. |

## Tier 0 substrate inventory

*(as of <as of YYYY-MM-DD>)*

The following codified gates are the framework's **procurement-legible trust substrate** per DIR-006. They run in CI on every commit. A Nation procurement officer can verify the framework's invariants by reading the list below and inspecting each named file — they do not need to read the codebase to confirm the framework enforces what it claims.

**CI gates (`bin/check-*`):**

- [`bin/check-composer-policy`](bin/check-composer-policy) — Enforces Composer manifest policy across all 62 packages: no wildcard `waaseyaa/*` constraints, no `@dev` outside root manifest, `config.sort-packages` true on all first-party manifests, internal constraints pinned to current tag.
- [`bin/check-package-layers`](bin/check-package-layers) — Enforces the 7-layer architecture: each package may only `require` packages in its own layer or lower. Prevents upward imports (e.g. Foundation depending on Entity).
- [`bin/check-dead-code`](bin/check-dead-code) — Fails on new unreferenced symbols via PHPStan + `phpstan-dead-code-baseline.neon`. Baseline reduced from 1,341 → 66 entries; gate has been fail-on-new since PR #1504.
- [`bin/check-getquery-bindings`](bin/check-getquery-bindings) — Fails on new `getQuery()->...->execute()` callsites that have neither `->setAccount()` nor `->accessCheck(false)` in the chain. Prevents unguarded auth bypass via `tools/getquery-bindings-baseline.txt` exemption list (every exemption carries an inline reason).
- [`bin/check-ingestion-defaults`](bin/check-ingestion-defaults) — Validates ingestion-defaults fixture-pack contract; prevents drift in the `defaults/ingestion.*` envelope shape consumers depend on.
- [`bin/check-no-secrets`](bin/check-no-secrets) — Scans the repo for secret patterns; fails on any match outside the documented allow-list.
- [`bin/check-openapi`](bin/check-openapi) — Schema compliance gate for the JSON:API surface; prevents undocumented endpoint shape changes.
- [`bin/check-admin-coercion-patterns`](bin/check-admin-coercion-patterns) — Detects unsafe type coercions in admin code paths.
- [`bin/check-monorepo-release-shape`](bin/check-monorepo-release-shape) — Validates the metapackage release shape across `core`, `cms`, `full`.
- [`bin/check-phpstan`](bin/check-phpstan) — Runs PHPStan analysis at level 5 against the committed baseline; new violations fail CI.
- [`bin/check-release-tag-parity`](bin/check-release-tag-parity) — Validates every published tag matches the internal version-sync invariants.
- [`bin/check-symfony-imports`](bin/check-symfony-imports) — Enforces Symfony component import conventions.
- [`bin/check-external-consumers`](bin/check-external-consumers) — Validates contracts external consumers (Minoo, Claudriel, downstream Nation distributions) rely on.

**Audit tools (warn-only reports for triage; not hard CI gates):**

- [`bin/audit-composer-deps`](bin/audit-composer-deps) — Composer dependency audit report.
- [`bin/audit-require-dev-layers`](bin/audit-require-dev-layers) — Warn-only audit of upward `require-dev` `waaseyaa/*` edges (test fixtures may pull from higher layers; runtime `require` may not).

**Spec freshness:**

- [`tools/drift-detector.sh`](tools/drift-detector.sh) — Exits non-zero when `docs/specs/*` files lag commits to the subsystems they document. Ensures specs and code stay synchronised so future maintainers inherit accurate documentation.

**Inventory maintenance:** When a new gate is added under the `bin/check-*` pattern (per DIR-006 "Adding a gate is encouraged and requires no amendment"), the same PR MUST append the gate to the list above with its one-line description and bump the `(as of YYYY-MM-DD)` date. When a gate is removed (which per DIR-006 requires a charter amendment with explicit rationale), the removing PR MUST remove the entry from the list above and the charter-amendment record MUST cite that removal.

## Decision authority

- **Merge to `main`:** Primary maintainer in steady state. Trustee in escalation only — see "Escalation" below.
- **Publish releases on Packagist:** Primary maintainer in steady state. Trustee in escalation only — see "Escalation" below.
- **Charter amendments:** Follow the Amendment Process documented in [`.kittify/charter/charter.md`](.kittify/charter/charter.md). Amendments are themselves Spec Kitty missions; the trustee MAY initiate an amendment mission in escalation but the amendment MUST follow the documented mission flow.
- **`charter-exception` issues:** Filed per the Exception Policy in the charter. Both primary maintainer and trustee may file; both may resolve.

## Escalation

If the primary maintainer is unavailable for more than **14 calendar days** and no holiday or leave was pre-announced in this file (see "Pre-announced absences" below), the trustee is authorised to:

1. Publish patch releases on `waaseyaa/*` Packagist packages for security fixes and downstream-breakage fixes.
2. Merge security fixes and downstream-breakage fixes to `main` (PRs from third parties may be merged; new substantive feature work is paused until the primary maintainer returns or the steward-committee Tier 2 escalation activates per [SUCCESSION.md](SUCCESSION.md)).
3. Notify downstream Nations via the Nation-hosted mirror's news feed (see "Nation-hosted mirror" below) that the framework is operating in escalation mode and the expected duration.

The trustee MUST NOT in escalation mode:

- Cut major or minor releases (only patch releases for security and downstream-breakage fixes).
- Amend the charter.
- Add or remove maintainers.
- Change the trustee designation.

When the primary maintainer returns, the trustee MUST hand back authority by posting a "primary maintainer resumed" note to the same channels used at escalation time. If the primary maintainer does not return within 90 calendar days of the escalation start and no Tier 2 (an existing tenant stewards committee) is yet operational, the trustee MUST file a `charter-exception` issue documenting the extended escalation and the plan to either re-activate the primary maintainer or accelerate Tier 2 setup.

### Pre-announced absences

| Period | Coverage |
|---|---|
| _(none recorded; primary maintainer updates this table when planning leave longer than 14 days)_ | _(n/a)_ |

## Packagist trustee

The trustee account holds publish rights on the `waaseyaa/*` Packagist namespace as an additional publisher. The namespace owner remains the primary maintainer; the trustee designation is an addition, not a transfer, so that the OCAP audit lineage on the namespace stays clean across future trustee turnover.

**Operational guarantee:** The trustee account is 2FA-enabled. Recovery codes for the trustee account are NOT held by the primary maintainer (the trustee is the failover; sharing recovery codes would defeat the purpose). If the trustee account itself becomes unavailable, the primary maintainer designates a replacement trustee via a follow-up commit to this file; this is a `docs(governance):` commit and does not require a charter amendment.

**Inheritance posture:** Future amendments to this file that change the trustee MUST preserve the existing trustee's publish history on Packagist (no `chown`-style ownership cycling). Trustees are additive over time; old trustees may be removed from the Packagist owner list once a new one is operational and confirmed.

## Nation-hosted mirror

Waaseyaa is mirrored read-only from `github.com/waaseyaa/framework` to a Nation-controlled Git forge so that Nations adopting the framework are not infrastructurally dependent on a single SaaS vendor.

| Field | Value |
|---|---|
| Mirror URL | `<<NATION_HOSTED_MIRROR_URL>>` |
| Forge software | (recorded at mirror setup by WP04 of `succession-framework-tier1-publishing-01KSEFV6`; MUST be FOSS — Gitea or Forgejo) |
| Sync direction | GitHub → mirror (read-only on the mirror side in steady state) |
| Sync mechanism | GitHub Actions workflow on push-to-`main`, with a nightly polling-based reconciliation as backup |
| Sync cadence | On every push to `main`; at minimum nightly even if no pushes occurred |
| Mirror trustee | The Packagist trustee account (above) also holds publish rights on the mirror so the recovery procedure can complete without coordinating credentials |

### Recovery procedure if GitHub becomes unavailable

If `github.com/waaseyaa/framework` becomes unavailable (vendor outage, account-level action, vendor disappearance), Nations adopting Waaseyaa proceed as follows:

1. The mirror becomes the new canonical origin. Update `origin` in local clones: `git remote set-url origin <<NATION_HOSTED_MIRROR_URL>>`.
2. The primary maintainer (or trustee in escalation) flips the mirror from read-only to read-write by removing the GitHub-side sync workflow.
3. Releases continue from the mirror with no namespace change — Packagist publish-from-mirror is configured on day one, so `composer require waaseyaa/...` continues to resolve.
4. Downstream consumers are notified via the mirror's news feed and via the Packagist package description.
5. When (if) GitHub access is restored, the maintainer decides whether to re-sync to GitHub as the primary or keep the mirror as the new origin permanently. The decision is recorded as an amendment to this file.

### Mirror forge selection rationale

Forge selection at WP04 time prioritised, in order: (1) Nation-controlled or an existing tenant-controlled host (rules out vendor SaaS even if cheaper); (2) FOSS forge software (Gitea or Forgejo) so future migration stays within the same data-portability envelope; (3) HTTPS-accessible and supports either webhook-driven mirror or polling-based mirror; (4) held under a domain a Nation procurement officer would recognise as Nation-controlled. The chosen forge meets all four criteria.

## Reading order for a new procurement officer

A Nation procurement officer evaluating Waaseyaa for sovereign-workspace adoption reads the framework's continuity posture as follows:

1. **This file.** Confirms who maintains it, what gates enforce its invariants, who can act if the maintainer is unavailable, and where the Nation-hosted continuity copy lives.
2. **[SUCCESSION.md](SUCCESSION.md).** Confirms the multi-tier continuity narrative across Tier 0 (already in place) through Tier 4 (long-horizon governance vehicle).
3. **[`.kittify/charter/charter.md`](.kittify/charter/charter.md), DIR-006.** Confirms that the gates listed in "Tier 0 substrate inventory" above are constitutionally binding — removal requires a charter amendment with explicit rationale, not just a code change.
4. **Spot-checks of `bin/check-*` source.** Confirms the gates do what this file says they do.

After step 4 the procurement officer has, without reading the framework's source code, verified the framework's continuity claim.
````

## §2 — `SUCCESSION.md` (WP02 — full file content)

Create new file `/SUCCESSION.md` at the repo root with exactly this content. No implementer substitutions.

````markdown
# Succession

This file documents the Waaseyaa framework's continuity story across five tiers. Tier 0 is already in place. Tier 1 is published by the mission that produced this file (`succession-framework-tier1-publishing-01KSEFV6`). Tiers 2–4 are deferred to future missions whose timing is governed by adoption signals rather than calendar dates.

Companion document: see [MAINTAINERS.md](MAINTAINERS.md) for the current maintainer roster and Tier 0 substrate inventory that this narrative builds on.
Constitutional source: see DIR-006 in [`.kittify/charter/charter.md`](.kittify/charter/charter.md) for the codified-gates-as-trust-substrate directive that anchors the trust posture across all five tiers.

## Why this file exists

Nations adopting Waaseyaa on owned hardware will ask "what happens if Russell stops" during procurement. This file is the answer, published before the question is asked. The answer is multi-tier by design: the near-term tiers (0 and 1) cost nothing to declare and are operational today; the mid-term tiers (2 and 3) earn their place as adoption grows; the long-term tier (4) is a governance vehicle whose shape is sketched but whose selection is intentionally deferred to the community it will serve.

The framework's continuity is **managed by codified infrastructure, not by individual people**. That posture is what makes the tiered model honest: every tier above 0 inherits an organisation that has already standardised how change happens, so successors do not invent the rules — they run `composer verify` and CI enforces them.

## Tier 0 — Trust substrate (already in place)

**Shape:** Codified infrastructure that enforces the framework's invariants on every commit. CI gates (`bin/check-*`), spec discipline (`docs/specs/*` + `tools/drift-detector.sh`), agent onboarding (`bimaaji:install` ships skills to seven major AI clients), the GPL-2.0-or-later license commitment (DIR-008), and the recorded ADRs and audits.

**Who acts:** The primary maintainer plus every contributor whose PR has to pass the gates. The gates themselves enforce the floor, so even a one-time contributor inherits the framework's conventions automatically.

**Trigger to next tier:** Tier 0 is the floor. It does not transition out; Tier 1 is added on top of it.

**What Nations get:** A procurement-defensible answer on its own. The framework's invariants are machine-verifiable; a procurement officer reads [MAINTAINERS.md](MAINTAINERS.md) "Tier 0 substrate inventory" and inspects the named files. No source-code archaeology required.

## Tier 1 — Practical pre-conditions (published by this mission)

**Shape:** Tier 1 makes Tier 0 procurement-legible by publishing four artifacts:

1. [MAINTAINERS.md](MAINTAINERS.md) — current maintainers, Tier 0 substrate inventory with file pointers, decision authority, escalation procedure.
2. This file ([SUCCESSION.md](SUCCESSION.md)) — the multi-tier continuity narrative.
3. A Packagist namespace trustee — a second account with publish rights on `waaseyaa/*`, designated alongside the primary maintainer. Protects against the "primary maintainer loses Packagist access" scenario. Recorded in [MAINTAINERS.md](MAINTAINERS.md) "Current maintainers" and "Packagist trustee" sections.
4. A Nation-hosted mirror repo — `github.com/waaseyaa/framework` mirrored to a Nation-controlled FOSS Git forge (Gitea or Forgejo). Protects against the "GitHub disappears" scenario. Recorded in [MAINTAINERS.md](MAINTAINERS.md) "Nation-hosted mirror" section, including the recovery procedure.

**Who acts:** Primary maintainer in steady state. Trustee in escalation per the escalation procedure in [MAINTAINERS.md](MAINTAINERS.md).

**Trigger to next tier:** Tier 2 activates when (a) framework v1.0 has shipped AND (b) an existing tenant leadership is ready to charter a stewards committee. Neither is required; Tier 1 is sustainable indefinitely if Tier 2 is not yet needed.

**What Nations get:** A documented, multi-tier continuity plan in writing. The framework's near-term continuity is rehearsed (escalation procedure is explicit); the framework is mirrored on Nation-controlled infrastructure; release publishing is not bottlenecked through a single human.

## Tier 2 — Near-term institutional continuity (post-v1.0)

**Shape (sketched; not yet committed):** an existing tenant (Ontario Indigenous AI & Technical Council — where the primary maintainer already works) charters Waaseyaa as a maintained framework with formal governance. A stewards committee assumes merge authority on `main`. The primary maintainer steps into an advisor / architect role: design review, long-term vision, security escalation, but not every PR. The trustee designation (Tier 1) is folded into the stewards committee membership.

**Who acts:** an existing tenant-chartered stewards committee. Decision authority is committee-based; the committee charter (to be drafted in `docs/governance/stewards-charter.md` by a future mission) defines roles, voting, conflict resolution.

**Trigger to next tier:** Tier 3 activates when (a) Tier 2 is operational AND (b) a funded engagement (Nation adoption or grant) provides the resources to hire the first contractor.

**What Nations get:** Framework maintenance is no longer single-maintainer-dependent. Decision authority is held by a chartered committee with documented governance. The framework's continuity transitions from "rehearsed escalation" to "ongoing committee operation".

**Deferred to:** A future mission filed when v1.0 has shipped and an existing tenant leadership is ready to charter the committee. No implementation commitment in the present mission.

## Tier 3 — Mid-term contributor pool (1–3 years post-v1.0)

**Shape (sketched; not yet committed):** A NorthOps-trained contractor pipeline maintains the codebase under stewards-committee direction. A published per-package owner roster (`docs/governance/package-owners.md` — to be created by a future mission) records bus-factor, current owner, contact, and onboarding status per package. High-risk single-author packages (flagged by a CI bus-factor job — design doc Bridge Mechanism #4, also deferred) are prioritised for contractor onboarding.

**Who acts:** Stewards committee (Tier 2) plus contracted maintainers. Per-package owners hold first-look review authority on PRs touching their package; stewards retain merge authority on `main`.

**Trigger to next tier:** Tier 4 activates when (a) Tier 3 is operational with at least three contracted maintainers AND (b) Nation adoption signals warrant a long-term governance vehicle independent of an existing tenant.

**What Nations get:** Framework maintenance is no longer committee-bottlenecked at the merge boundary. Per-package owners absorb routine review load; stewards focus on cross-package and strategic decisions. Bus-factor is published and managed.

**Deferred to:** A future funded-engagement mission. The bus-factor CI job is queued as a separate mission ahead of Tier 3 because it informs Tier 3 prioritisation.

## Tier 4 — Long-term governance vehicle (3–5+ years, as adoption grows)

**Shape (sketched; three options under consideration; not yet committed):**

- **Option 4A — Indigenous-tech foundation.** A new foundation chartered to govern Waaseyaa and adjacent Indigenous-tech infrastructure. Nation-elected governance, FN-centered. License stays GPLv2 (or dual). Funding via membership dues and grants. Slowest to launch (3–5 years) and highest cost ($50K–$200K legal/governance), but maximum Indigenous-tech credibility and Nation-accountable governance.
- **Option 4B — Apache Software Foundation incubation.** Submit Waaseyaa to Apache as an incubated project. Faster to launch (1–2 years to apply, 2–3 years incubation) and zero launch cost (Apache hosts). License compatibility: Apache prefers ALv2 so a sovereignty-impact analysis covering relicensing would be required (per DIR-008 amendment process). Global OSS credibility; U.S.-model governance.
- **Option 4C — Steward board under existing entity.** an existing tenant-hosted permanent steward board with Nation-accountable membership. Faster than 4A (2–3 years) and low cost (~$10K governance docs). License unchanged. Community-embedded credibility but not standalone.

**Who acts:** Whichever vehicle is selected (4A / 4B / 4C). The selection itself is a Nation-engaged decision, not a maintainer decision.

**Trigger to next tier:** Tier 4 is the long-horizon governance home. There is no Tier 5.

**What Nations get:** Long-term institutional home for the framework that outlives any individual person, any individual organisation, or any individual hosting decision. The framework's continuity becomes structurally independent of its origin.

**Deferred to:** A future long-horizon governance mission. The selection between 4A, 4B, and 4C is itself work — `docs/governance/foundation-options.md` (to be created by a future mission) documents the tradeoffs and tracks the selection process.

## Procurement-facing narrative

> Waaseyaa's continuity is managed by codified infrastructure, not by individual people. The `bin/check-*` family enforces the framework's conventions on every commit; spec discipline plus a drift detector keeps documentation in sync with code; contributor onboarding ships automatically to seven major AI coding assistants. Today the framework is maintained by Russell Jones (Pilot Nation A Anishnawbek / an existing tenant) with this substrate underneath. A Packagist namespace trustee and a Nation-hosted mirror repo are operational — see [MAINTAINERS.md](MAINTAINERS.md). The framework's near-term institutional home (post-v1.0) is an existing tenant, where the primary maintainer already works; the mid-term maintainer pool draws from NorthOps-trained contractors; the long-term governance vehicle is intended to be an Indigenous-tech foundation with Nation-elected governance, with Apache incubation and an an existing tenant-hosted steward board as fallback options. Nations adopting Waaseyaa on owned hardware are adopting infrastructure with a documented, multi-tier continuity plan — not a single-maintainer project.

## Glossary

- **Tier 0:** Codified trust substrate already in place. See [MAINTAINERS.md](MAINTAINERS.md) "Tier 0 substrate inventory".
- **Tier 1:** Published continuity floor — `MAINTAINERS.md`, this file, Packagist trustee, Nation-hosted mirror. Operational today.
- **Tier 2:** an existing tenant-chartered stewards committee. Deferred to post-v1.0.
- **Tier 3:** NorthOps-trained contractor pool with per-package owner roster. Deferred to funded engagement.
- **Tier 4:** Long-term governance vehicle (Indigenous-tech foundation, Apache incubation, or an existing tenant-hosted steward board). Deferred to long-horizon governance mission.
- **DIR-006:** The charter directive that declares the `bin/check-*` family the procurement-legible surface of the framework's invariants. See [`.kittify/charter/charter.md`](.kittify/charter/charter.md).
- **OCAP:** Ownership, Control, Access, Possession — the First Nations data-sovereignty framework that Waaseyaa's architecture is designed to honour. The succession framework preserves OCAP audit lineage as the top-priority constraint at every tier transition.
````

## §3 — Packagist trustee designation (WP03 — operational + documentation steps)

WP03 has two parts. The documentation part edits `MAINTAINERS.md` to substitute `<<TRUSTEE_PACKAGIST_ACCOUNT>>` with the chosen trustee Packagist username and to fill the "Affiliation" cell. The operational part configures the Packagist owner list.

**Trustee selection criteria** (Russell selects at execution time):

- Active Packagist account.
- 2FA-enabled.
- Held by an individual or organisation Russell trusts to publish security fixes on `waaseyaa/*` if the primary maintainer is unavailable for more than 14 days.
- Candidate categories (not exhaustive; Russell's call): an existing tenant technical lead; a long-term external contributor with publish history on related namespaces; an academic-institution partner.
- The chosen account preserves OCAP audit lineage on the namespace — it is an additional publisher, not a transfer of ownership.

**Operational steps** (Russell or implementer with Russell's credentials):

1. Confirm the chosen trustee has accepted the designation and has a 2FA-enabled Packagist account.
2. On packagist.org: log in as the primary maintainer; visit each `waaseyaa/*` package's "Maintainers" tab; add the trustee account by Packagist username. Start with `waaseyaa/framework`; then iterate through the other published `waaseyaa/*` packages (the canonical list is the repository's published package set as of the WP-execution date).
3. Spot-check on packagist.org/packages/waaseyaa/framework that the trustee appears in the maintainer list.

**Documentation step:**

1. In `MAINTAINERS.md`, replace the two occurrences of `<<TRUSTEE_PACKAGIST_ACCOUNT>>` with the chosen Packagist username.
2. In the "Current maintainers" table, fill the "Affiliation" cell for the trustee row with the trustee's affiliation (e.g. "an existing tenant", "Independent contributor", or the academic-institution name). The placeholder text `(recorded at trustee designation by WP03 of ...)` is replaced with the actual affiliation.
3. Commit per NFR-001 commit-message convention.

**Verification:**

- `grep -c "<<TRUSTEE_PACKAGIST_ACCOUNT>>" MAINTAINERS.md` returns `0`.
- Reviewer confirms the Packagist owner-list entry on packagist.org/packages/waaseyaa/framework matches the trustee account name in `MAINTAINERS.md`.

## §4 — Nation-hosted mirror setup (WP04 — operational + documentation steps)

WP04 has two parts. The documentation part edits `MAINTAINERS.md` to substitute `<<NATION_HOSTED_MIRROR_URL>>` and fill the "Forge software" cell. The operational part configures the mirror.

**Forge selection criteria** (Russell selects at execution time):

- Nation-controlled or an existing tenant-controlled host (not a SaaS subdomain even if cheaper).
- FOSS forge software (Gitea or Forgejo).
- HTTPS-accessible URL.
- Supports webhook-driven mirror push from GitHub Actions OR polling-based mirror pull.
- Held under a domain a Nation procurement officer would recognise as Nation-controlled (e.g. `git.<oiatc-or-nation-domain>.ca`).
- Minimises vendor lock-in (the framework can move to a different FOSS forge later without changing data portability — git is git).

**Operational steps** (Russell or implementer with forge admin credentials):

1. On the chosen forge: create an organisation `waaseyaa` (or equivalent namespace).
2. Create a repository `framework` (or equivalent) configured as a read-only mirror of `github.com/waaseyaa/framework`.
3. Configure sync:
   - **Preferred:** GitHub Actions workflow in the framework repo on push-to-`main` that pushes refs to the mirror via a deploy key held in the GitHub Actions secret store.
   - **Fallback:** the forge's built-in "mirror from external repo" feature with at-minimum-nightly polling cadence.
4. Confirm initial sync completes: `git ls-remote <mirror-url>` returns the same `main` ref as `git ls-remote https://github.com/waaseyaa/framework`.
5. Grant the Packagist trustee account publish rights on the mirror so the recovery procedure can complete without coordinating credentials.

**Documentation step:**

1. In `MAINTAINERS.md` "Nation-hosted mirror" section, replace `<<NATION_HOSTED_MIRROR_URL>>` (two occurrences: the table cell and the recovery procedure step 1) with the chosen mirror URL.
2. Fill the "Forge software" cell with either `Gitea` or `Forgejo` and the version (e.g. `Forgejo 8.0`).
3. Confirm the "Mirror forge selection rationale" paragraph reflects the criteria actually applied; no other edit is needed if the criteria match the spec.
4. Commit per NFR-001 commit-message convention.

**Verification:**

- `grep -c "<<NATION_HOSTED_MIRROR_URL>>" MAINTAINERS.md` returns `0`.
- `git ls-remote <chosen-mirror-url>` returns the same `main` ref as `git ls-remote https://github.com/waaseyaa/framework`.
- Reviewer confirms the mirror is read-only from the mirror side in the steady state (test that an unauthorised push from a developer's clone fails; only the GitHub Actions deploy key or the polling sync mechanism can write).

## §5 — README.md pointer (folded into WP01)

In `README.md`, locate the first prose section after the badge/header block. Immediately before that section, insert exactly:

```markdown
> Governance: see [MAINTAINERS.md](MAINTAINERS.md) for the current maintainer roster and [SUCCESSION.md](SUCCESSION.md) for the framework's continuity plan across Tiers 0–4.
```

The insertion is one blank line above and one blank line below the line so the blockquote renders cleanly.

If the documented anchor (immediately after badge/header block, before first prose section) cannot be located unambiguously in the current `README.md`, the implementer STOPS and surfaces the discrepancy rather than improvising.

## Verification gate (run after each WP, in lane worktree)

After WP01:

- `test -f MAINTAINERS.md && test -f /home/fsd42/dev/waaseyaa/MAINTAINERS.md`
- `grep -c "Tier 0 substrate inventory" MAINTAINERS.md` → `1`
- `grep -c "MAINTAINERS.md" README.md` → at least `1`
- `grep -c "SUCCESSION.md" README.md` → at least `1`

After WP02:

- `test -f SUCCESSION.md`
- `grep -cE "^## Tier [01234]" SUCCESSION.md` → `5`
- `grep -c "MAINTAINERS.md" SUCCESSION.md` → at least `1`
- `grep -c "DIR-006" SUCCESSION.md` → at least `1`

After WP03:

- `grep -c "<<TRUSTEE_PACKAGIST_ACCOUNT>>" MAINTAINERS.md` → `0`
- The chosen trustee account name appears in MAINTAINERS.md "Current maintainers" table.
- Reviewer confirms Packagist owner-list update via packagist.org/packages/waaseyaa/framework.

After WP04:

- `grep -c "<<NATION_HOSTED_MIRROR_URL>>" MAINTAINERS.md` → `0`
- The chosen mirror URL appears in MAINTAINERS.md "Nation-hosted mirror" section.
- `git ls-remote <mirror-url>` returns the same `main` ref as GitHub.

After all four WPs:

- `grep -cE "TBD|TODO|_placeholder_|<placeholder>|<<[A-Z_]+>>" MAINTAINERS.md SUCCESSION.md` → `0`

## Reviewer focus

- **Tone consistency:** Both new files speak in the same authoritative voice as the charter (MUST / MUST NOT, concrete consequences, no marketing prose, no hedging in normative clauses).
- **DIR-006 cross-reference accuracy:** Both files cite DIR-006 by directive number and link `.kittify/charter/charter.md`.
- **Tier 0 substrate inventory matches reality:** Every `bin/check-*` and `bin/audit-*` and `tools/drift-detector.sh` entry maps to a file that exists in the repo; the one-line descriptions reflect what each gate actually does (spot-check three at random).
- **Trustee documentation matches Packagist reality:** The account name in MAINTAINERS.md is the same account listed on packagist.org/packages/waaseyaa/framework.
- **Mirror documentation matches forge reality:** The URL in MAINTAINERS.md resolves; `git ls-remote` returns the expected `main` ref; the mirror is read-only from the mirror side in steady state.
- **Cross-link integrity:** MAINTAINERS.md links to SUCCESSION.md, SUCCESSION.md links to MAINTAINERS.md, both link to `.kittify/charter/charter.md`, README.md links to both.
- **Preference-order honoured:** Trustee designation preserved OCAP lineage (additive publish rights, not transfer); mirror runs on FOSS forge software; no `bin/check-*` or `tools/drift-detector.sh` was modified.
