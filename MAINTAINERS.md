# Maintainers

This file enumerates the Waaseyaa framework's current maintainers, the Tier 0 trust substrate that underwrites the framework's continuity claim, and the decision and escalation authority that governs day-to-day operations.

Companion document: see [SUCCESSION.md](SUCCESSION.md) for the multi-tier continuity narrative this file's roster operationalises.
Constitutional source: see DIR-006 in [`.kittify/charter/charter.md`](.kittify/charter/charter.md) for the codified-gates-as-trust-substrate directive that this file makes procurement-legible.

## Current maintainers

| Role | Name / account | Affiliation | Scope |
|---|---|---|---|
| Primary maintainer | Russell Jones (`jonesrussell`) | Sagamok Anishnawbek / OIATC | Merge authority on `main`; release authority on every `waaseyaa/*` Packagist package; charter amendment authority per the charter's Amendment Process. |
| Packagist trustee | `<<TRUSTEE_PACKAGIST_ACCOUNT>>` | (recorded at trustee designation by WP03 of `succession-framework-tier1-publishing-01KSEFV6`) | Publish rights on `waaseyaa/*` namespace; activated if the primary maintainer becomes unavailable per the escalation procedure below. The trustee is an additional publisher; the namespace owner remains the primary maintainer. |

<!-- WP03 DEFERRAL (succession-framework-tier1-publishing-01KSEFV6):
     The <<TRUSTEE_PACKAGIST_ACCOUNT>> marker above is intentionally left unsubstituted.
     Trustee selection requires Russell Jones to designate a specific Packagist account.
     Selection criteria (from plan.md §3): active Packagist account, 2FA-enabled, held by
     an individual or organisation Russell trusts to publish security fixes on waaseyaa/*
     if the primary maintainer is unavailable for more than 14 days.
     Candidate categories: OIATC technical lead; long-term external contributor with publish
     history on related namespaces; academic-institution partner.
     Operational steps for Russell: log in to packagist.org as primary maintainer, visit each
     waaseyaa/* package's "Maintainers" tab, add the trustee account by Packagist username.
     Once decided, replace <<TRUSTEE_PACKAGIST_ACCOUNT>> with the Packagist username and
     fill the Affiliation cell with the trustee's affiliation (removing this comment block).
     Activity log: WP03 executed 2026-05-25. Trustee selection deferred to author-controlled
     session. No marker substitution performed by implementer. -->

## Tier 0 substrate inventory

*(as of 2026-05-25)*

The following codified gates are the framework's **procurement-legible trust substrate** per DIR-006. They run in CI on every commit. A Nation procurement officer can verify the framework's invariants by reading the list below and inspecting each named file — they do not need to read the codebase to confirm the framework enforces what it claims.

**CI gates (`bin/check-*`):**

- [`bin/check-composer-policy`](bin/check-composer-policy) — Enforces Composer manifest policy across all 62 packages: no wildcard `waaseyaa/*` constraints, no `@dev` outside root manifest, `config.sort-packages` true on all first-party manifests, internal constraints pinned to current tag.
- [`bin/check-package-layers`](bin/check-package-layers) — Enforces the 7-layer architecture: each package may only `require` packages in its own layer or lower. Prevents upward imports (e.g. Foundation depending on Entity).
- [`bin/check-dead-code`](bin/check-dead-code) — Fails on new unreferenced symbols via PHPStan + `phpstan-dead-code-baseline.neon`. Baseline reduced from 1,341 → 66 entries; gate has been fail-on-new since PR #1504.
- [`bin/check-getquery-bindings`](bin/check-getquery-bindings) — Fails on new `getQuery()->...->execute()` callsites that have neither `->setAccount()` nor `->accessCheck(false)` in the chain. Prevents unguarded auth bypass via `tools/getquery-bindings-baseline.txt` exemption list (every exemption carries an inline reason).
- [`bin/check-ingestion-defaults`](bin/check-ingestion-defaults) — Validates ingestion-defaults fixture-pack contract; prevents drift in the `defaults/ingestion.*` envelope shape consumers depend on.
- [`bin/check-no-secrets`](bin/check-no-secrets) — Scans the repo for secret patterns; fails on any match outside the documented allow-list.
- [`bin/check-openapi`](bin/check-openapi) — Spectral-lints the hand-maintained agent-run sub-spec `packages/api/openapi.yaml` (3 SSE endpoints + the `pending_approval` payload shape) for YAML/OpenAPI structural validity. It is a syntax/shape lint of that one document — it does NOT introspect the route table, diff against live routes, or enforce route/schema parity across the full HTTP API surface.
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
- **Charter amendments:** Follow the Amendment Process documented in [`.kittify/charter/charter.md`](.kittify/charter/charter.md). An amendment is a governed change: it gets its own anchoring GitHub issue and a written plan, and the amending PR links both; the trustee MAY initiate an amendment in escalation but MUST follow that documented flow.
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

When the primary maintainer returns, the trustee MUST hand back authority by posting a "primary maintainer resumed" note to the same channels used at escalation time. If the primary maintainer does not return within 90 calendar days of the escalation start and no Tier 2 (OIATC stewards committee) is yet operational, the trustee MUST file a `charter-exception` issue documenting the extended escalation and the plan to either re-activate the primary maintainer or accelerate Tier 2 setup.

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

<!-- WP04 DEFERRAL (succession-framework-tier1-publishing-01KSEFV6):
     The <<NATION_HOSTED_MIRROR_URL>> markers above (Mirror URL table cell and recovery
     procedure step 1) are intentionally left unsubstituted. Mirror setup requires Russell
     Jones to select and configure a Nation-controlled FOSS Git forge in an author-controlled
     session. Selection criteria (from plan.md §4): Nation-controlled or OIATC-controlled host;
     FOSS forge software (Gitea or Forgejo); HTTPS-accessible URL; supports webhook-driven or
     polling-based mirror; held under a domain a Nation procurement officer would recognise as
     Nation-controlled. Minimises vendor lock-in — git data portability preserved.
     Operational steps for Russell: create org 'waaseyaa' on chosen forge, create repo
     'framework' as read-only mirror of github.com/waaseyaa/framework, configure sync
     (preferred: GitHub Actions workflow; fallback: forge built-in polling), confirm initial
     sync via git ls-remote, grant Packagist trustee account publish rights on mirror.
     Once configured, replace both <<NATION_HOSTED_MIRROR_URL>> occurrences with the mirror
     URL, fill the "Forge software" cell (e.g. 'Forgejo 8.0'), and remove this comment block.
     Activity log: WP04 executed 2026-05-25. Mirror URL selection deferred to author-controlled
     session. No marker substitution performed by implementer. -->

### Recovery procedure if GitHub becomes unavailable

If `github.com/waaseyaa/framework` becomes unavailable (vendor outage, account-level action, vendor disappearance), Nations adopting Waaseyaa proceed as follows:

1. The mirror becomes the new canonical origin. Update `origin` in local clones: `git remote set-url origin <<NATION_HOSTED_MIRROR_URL>>`.
2. The primary maintainer (or trustee in escalation) flips the mirror from read-only to read-write by removing the GitHub-side sync workflow.
3. Releases continue from the mirror with no namespace change — Packagist publish-from-mirror is configured on day one, so `composer require waaseyaa/...` continues to resolve.
4. Downstream consumers are notified via the mirror's news feed and via the Packagist package description.
5. When (if) GitHub access is restored, the maintainer decides whether to re-sync to GitHub as the primary or keep the mirror as the new origin permanently. The decision is recorded as an amendment to this file.

### Mirror forge selection rationale

Forge selection at WP04 time prioritised, in order: (1) Nation-controlled or OIATC-controlled host (rules out vendor SaaS even if cheaper); (2) FOSS forge software (Gitea or Forgejo) so future migration stays within the same data-portability envelope; (3) HTTPS-accessible and supports either webhook-driven mirror or polling-based mirror; (4) held under a domain a Nation procurement officer would recognise as Nation-controlled. The chosen forge meets all four criteria.

## Reading order for a new procurement officer

A Nation procurement officer evaluating Waaseyaa for sovereign-workspace adoption reads the framework's continuity posture as follows:

1. **This file.** Confirms who maintains it, what gates enforce its invariants, who can act if the maintainer is unavailable, and where the Nation-hosted continuity copy lives.
2. **[SUCCESSION.md](SUCCESSION.md).** Confirms the multi-tier continuity narrative across Tier 0 (already in place) through Tier 4 (long-horizon governance vehicle).
3. **[`.kittify/charter/charter.md`](.kittify/charter/charter.md), DIR-006.** Confirms that the gates listed in "Tier 0 substrate inventory" above are constitutionally binding — removal requires a charter amendment with explicit rationale, not just a code change.
4. **Spot-checks of `bin/check-*` source.** Confirms the gates do what this file says they do.

After step 4 the procurement officer has, without reading the framework's source code, verified the framework's continuity claim.
