# Succession

This file documents the Waaseyaa framework's continuity story across five tiers. Tier 0 is already in place. Tier 1 is published by the mission that produced this file (`succession-framework-tier1-publishing-01KSEFV6`). Tiers 2–4 are deferred to future missions whose timing is governed by adoption signals rather than calendar dates.

Companion document: see [MAINTAINERS.md](MAINTAINERS.md) for the current maintainer roster and Tier 0 substrate inventory that this narrative builds on.
Constitutional source: see DIR-006 in [`docs/governance/charter.md`](docs/governance/charter.md) for the codified-gates-as-trust-substrate directive that anchors the trust posture across all five tiers.

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

**Trigger to next tier:** Tier 2 activates when (a) framework v1.0 has shipped AND (b) OIATC leadership is ready to charter a stewards committee. Neither is required; Tier 1 is sustainable indefinitely if Tier 2 is not yet needed.

**What Nations get:** A documented, multi-tier continuity plan in writing. The framework's near-term continuity is rehearsed (escalation procedure is explicit); the framework is mirrored on Nation-controlled infrastructure; release publishing is not bottlenecked through a single human.

## Tier 2 — Near-term institutional continuity (post-v1.0)

**Shape (sketched; not yet committed):** OIATC (Ontario Indigenous AI & Technical Council — where the primary maintainer already works) charters Waaseyaa as a maintained framework with formal governance. A stewards committee assumes merge authority on `main`. The primary maintainer steps into an advisor / architect role: design review, long-term vision, security escalation, but not every PR. The trustee designation (Tier 1) is folded into the stewards committee membership.

**Who acts:** OIATC-chartered stewards committee. Decision authority is committee-based; the committee charter (to be drafted in `docs/governance/stewards-charter.md` by a future mission) defines roles, voting, conflict resolution.

**Trigger to next tier:** Tier 3 activates when (a) Tier 2 is operational AND (b) a funded engagement (Nation adoption or grant) provides the resources to hire the first contractor.

**What Nations get:** Framework maintenance is no longer single-maintainer-dependent. Decision authority is held by a chartered committee with documented governance. The framework's continuity transitions from "rehearsed escalation" to "ongoing committee operation".

**Deferred to:** A future mission filed when v1.0 has shipped and OIATC leadership is ready to charter the committee. No implementation commitment in the present mission.

## Tier 3 — Mid-term contributor pool (1–3 years post-v1.0)

**Shape (sketched; not yet committed):** A NorthOps-trained contractor pipeline maintains the codebase under stewards-committee direction. A published per-package owner roster (`docs/governance/package-owners.md` — to be created by a future mission) records bus-factor, current owner, contact, and onboarding status per package. High-risk single-author packages (flagged by a CI bus-factor job — design doc Bridge Mechanism #4, also deferred) are prioritised for contractor onboarding.

**Who acts:** Stewards committee (Tier 2) plus contracted maintainers. Per-package owners hold first-look review authority on PRs touching their package; stewards retain merge authority on `main`.

**Trigger to next tier:** Tier 4 activates when (a) Tier 3 is operational with at least three contracted maintainers AND (b) Nation adoption signals warrant a long-term governance vehicle independent of OIATC.

**What Nations get:** Framework maintenance is no longer committee-bottlenecked at the merge boundary. Per-package owners absorb routine review load; stewards focus on cross-package and strategic decisions. Bus-factor is published and managed.

**Deferred to:** A future funded-engagement mission. The bus-factor CI job is queued as a separate mission ahead of Tier 3 because it informs Tier 3 prioritisation.

## Tier 4 — Long-term governance vehicle (3–5+ years, as adoption grows)

**Shape (sketched; three options under consideration; not yet committed):**

- **Option 4A — Indigenous-tech foundation.** A new foundation chartered to govern Waaseyaa and adjacent Indigenous-tech infrastructure. Nation-elected governance, FN-centered. License stays GPLv2 (or dual). Funding via membership dues and grants. Slowest to launch (3–5 years) and highest cost ($50K–$200K legal/governance), but maximum Indigenous-tech credibility and Nation-accountable governance.
- **Option 4B — Apache Software Foundation incubation.** Submit Waaseyaa to Apache as an incubated project. Faster to launch (1–2 years to apply, 2–3 years incubation) and zero launch cost (Apache hosts). License compatibility: Apache prefers ALv2 so a sovereignty-impact analysis covering relicensing would be required (per DIR-008 amendment process). Global OSS credibility; U.S.-model governance.
- **Option 4C — Steward board under existing entity.** OIATC-hosted permanent steward board with Nation-accountable membership. Faster than 4A (2–3 years) and low cost (~$10K governance docs). License unchanged. Community-embedded credibility but not standalone.

**Who acts:** Whichever vehicle is selected (4A / 4B / 4C). The selection itself is a Nation-engaged decision, not a maintainer decision.

**Trigger to next tier:** Tier 4 is the long-horizon governance home. There is no Tier 5.

**What Nations get:** Long-term institutional home for the framework that outlives any individual person, any individual organisation, or any individual hosting decision. The framework's continuity becomes structurally independent of its origin.

**Deferred to:** A future long-horizon governance mission. The selection between 4A, 4B, and 4C is itself work — `docs/governance/foundation-options.md` (to be created by a future mission) documents the tradeoffs and tracks the selection process.

## Procurement-facing narrative

> Waaseyaa's continuity is managed by codified infrastructure, not by individual people. The `bin/check-*` family enforces the framework's conventions on every commit; spec discipline plus a drift detector keeps documentation in sync with code; contributor onboarding ships automatically to seven major AI coding assistants. Today the framework is maintained by Russell Jones (Sagamok Anishnawbek / OIATC) with this substrate underneath. A Packagist namespace trustee and a Nation-hosted mirror repo are operational — see [MAINTAINERS.md](MAINTAINERS.md). The framework's near-term institutional home (post-v1.0) is OIATC, where the primary maintainer already works; the mid-term maintainer pool draws from NorthOps-trained contractors; the long-term governance vehicle is intended to be an Indigenous-tech foundation with Nation-elected governance, with Apache incubation and an OIATC-hosted steward board as fallback options. Nations adopting Waaseyaa on owned hardware are adopting infrastructure with a documented, multi-tier continuity plan — not a single-maintainer project.

## Glossary

- **Tier 0:** Codified trust substrate already in place. See [MAINTAINERS.md](MAINTAINERS.md) "Tier 0 substrate inventory".
- **Tier 1:** Published continuity floor — `MAINTAINERS.md`, this file, Packagist trustee, Nation-hosted mirror. Operational today.
- **Tier 2:** OIATC-chartered stewards committee. Deferred to post-v1.0.
- **Tier 3:** NorthOps-trained contractor pool with per-package owner roster. Deferred to funded engagement.
- **Tier 4:** Long-term governance vehicle (Indigenous-tech foundation, Apache incubation, or OIATC-hosted steward board). Deferred to long-horizon governance mission.
- **DIR-006:** The charter directive that declares the `bin/check-*` family the procurement-legible surface of the framework's invariants. See [`docs/governance/charter.md`](docs/governance/charter.md).
- **OCAP:** Ownership, Control, Access, Possession — the First Nations data-sovereignty framework that Waaseyaa's architecture is designed to honour. The succession framework preserves OCAP audit lineage as the top-priority constraint at every tier transition.
