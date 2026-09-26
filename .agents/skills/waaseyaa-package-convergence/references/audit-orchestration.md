# Running an audit with agents

How to run one package audit with parallel agents and still produce a record
people can review. The method is in the skill; this is the execution model.

Parallel agents need authorized multi-agent scope (see the agent contract).
Without it, run the lanes and the two tier-A lenses one after another, and
record the reduced independence in the scorecard and on each tier-A finding.

## Size the run first

Size by source lines of code, inbound consumer packages and applications,
and intake items (see the skill's step 1), not only by file count. Write the
lanes and budget in the scorecard before any agent starts.

| Package | Guide | Lanes | Agents | Time | Human record |
| --- | --- | --- | --- | --- | --- |
| Small | up to about 1,500 source lines, few consumers, little intake | 3 or 4 | about 50 | about 2 hours | about 400 lines |
| Medium | up to about 6,000 source lines, or many consumers, or security intake | 4 or 5 | about 75 | about 4 hours | about 400 lines |
| Large | beyond that | bounded passes by sub-area, each sized as above | per pass | per pass | one index record under 150 lines, plus one record per pass |

These budgets are provisional. They come from one calibration run and are to
be revised from the scorecards of the next audits (FW-PACKAGE-CONVERGENCE-01).

**Checkpoint after consolidation.** Project the run:
`lanes + 1 + 2 × tier-A findings + ceil(tier-B findings ÷ 3) + tier-C groups + leads + intake re-verifications + tie-breaks (estimate) + 3`
(writer, critic, one repair). When the projection is more than twice the
budget, stop and report it to the maintainer with the scorecard before
continuing. An overrun is a calibration result, not a failure to hide.

## Lanes

Default lanes for a small package:

1. **Charter, consumers and history.** The charter questions, inbound
   consumers (framework packages and downstream applications, read-only),
   documentation drift, the intake list, existing issues and prior audits.
   Revalidate every open issue against current code.
2. **Contracts and behavior.** The domain-contracts and http-ui-contracts
   profiles, as selected: invariants, lifecycle, access and refusal paths.
3. **Persistence, runtime and distribution.** The persistence-execution,
   kernel-runtime and distribution profiles, plus the public surface.
4. **Tests and gates.** What each test proves, discriminators for the most
   important assertions (mutation or preloaded-copy runs), and the mechanical
   gates.

For a medium package, split lane 2 or lane 3 by profile, up to five lanes.
Every lane is read-only on the repository and on consumer checkouts, writes
probes only to scratch, labels synthetic evidence as synthetic, and returns
structured output with the finding fields from the template, including
attribution.

## Consolidate

One agent merges the lane outputs into the ledger's findings:

- dedupes findings by root cause, never by file;
- assigns stable IDs and fills attribution: discovered in, owned by,
  co-owners, affected consumers, blocks this assessment;
- splits a finding with two separable repairs into one finding per owner,
  cross-linked; a finding whose owner depends on an open decision names the
  decision;
- groups test and documentation gaps into one finding per coherent repair
  slice (for example "staff-directory tests don't discriminate the capability,
  pagination and duplicate-roster rules"), not one finding per missing test;
- assigns each finding its verification tier;
- lists lane conflicts and unresolved uncertainties with what would settle
  them, instead of picking a side.

## Verify by tier

Severity and security decide the tier first.

| Tier | Applies to | Verification |
| --- | --- | --- |
| A | security-sensitive; medium or higher; removes or deprecates public surface; changes authority (who may read or mutate data) | two independent verifiers: one re-derives the evidence and re-runs or rewrites the probe, one tries to refute (intended and documented? overstated for real consumers? owned elsewhere? does the acceptance discriminate?) |
| B | low, including grouped test and documentation findings rated low | one verifier doing both jobs; closely related low findings (same mechanism or file) may share one verifier, at most three per verifier |
| C | info | one verifier per group of info findings checks a sample of at least three claims, or every claim when the group is smaller; when any sample fails, every claim in the group is verified |

- **Tie-break** on any material disagreement between tier-A verifiers: whether
  the finding exists, whether it is security-sensitive, its owning package,
  its disposition, or a severity difference that crosses the medium line or
  changes the finding's tier, disposition or destination. When a
  tie-break can't settle it, record it as a maintainer decision.
- **Re-tier** a finding when verification changes its severity: raised to
  medium or higher, it gets a second verifier.
- **Findings created after verification** (from leads, intake or refutation
  checks) get their tier's verification too.
- **Refuted leads:** one agent checks every refutation once. In the groups
  calibration this reopened 2 of the 65 it checked.
- Apply corrections as verifiers report them: mechanism, severity, citations,
  owner and acceptance. Record each reversal in the scorecard.

## Close the leads

Every open lead ends as a finding, a merge into a finding, a refutation, or a
handoff to the package that owns it. Before handing a lead off, check whether
it blocks this assessment; a blocking lead is investigated or turned into a
maintainer decision. A security lead is always investigated. A non-blocking,
non-security lead owned by another package can be handed off without
investigation: it goes in the ledger's handoffs and becomes intake for that
package's audit.

## Write and check

- The orchestrator writes the private security brief itself, from verified
  evidence ([security triage](security-triage.md)).
- Assemble the structured ledger from the structured lane, verifier and lead
  outputs with a script where possible, then render the human record from the
  ledger within the size budget. Don't hand-write both.
- One critic checks the finish criteria, the ledger against the template's key
  list, citations (sample about 8), the size budget and, holding the brief,
  the redaction rules. At most two repair rounds, each re-checking only the
  repaired items.

## Scorecard

Keep one per audit, in the record and in the ledger, for at least the next
few audits after a method change:

- elapsed time, agent count and subagent tokens, per phase (from the workflow
  harness's usage report; write "unavailable" when there is none);
- source files and lines, consumers, intake items and profiles applied;
- findings by severity, package-owned and cross-package counted separately;
- verification reversals: findings refuted, severity changes, mechanism
  corrections, refutations reopened, findings added after verification;
- human record length in lines and words, and ledger size;
- probes written, and probes retained in the repository;
- open decisions, uncertainties, and findings without a filed issue.

## Calibration baseline

The groups audit (2026-09-25, 13 source files, 1,063 source lines, 5
profiles) ran the v1 method with no budget and set the baseline this model is
sized against:

- 121 agents (7 lanes, 1 consolidation, 98 two-lens verifications, 8 leads,
  1 refutation check, 1 writer, 3 critics and 2 repairs), about 20M subagent
  tokens and about 4.3 hours;
- 49 consolidated findings became 55 after leads and refutation checks, 4 of
  them security; verification refuted none, lowered 13 severities (3 medium
  to low, 10 low to info) and corrected mechanisms in two findings, one of
  which exposed the highest-value security finding; the refutation check
  reopened 2 of the 65 refutations it checked (68 in the final record);
- a 2,420-line human record against the ai-vector record's 365.

Two-lens verification of every finding and one finding per missing test drove
most of the cost. Tiers and grouping target both. Condensing the same results
into the v2 format produced a 392-line record, which shows the format fits.
The ledger it produced was about 460 KB: keep ledgers lean (verdicts and
corrections, not verifier transcripts), since they are data for later audits
and checks, not review reading.
