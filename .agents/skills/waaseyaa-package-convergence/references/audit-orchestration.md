# Running an audit with agents

How to run one package audit with parallel agents and still produce a record
people can review. The method is in the skill; this is the execution model.

## Size the run first

Pick lanes and a budget from the package's production files and profiles, and
write them in the scorecard before any agent starts.

| Package | Lanes | Agent budget | Time budget | Human record |
| --- | --- | --- | --- | --- |
| Small (up to about 20 production files) | 3 or 4 | about 40 | about 2 hours | about 400 lines |
| Medium (about 20 to 80) | 4 or 5 | about 60 | about 3 hours | about 400 lines |
| Large (more than about 80) | split into bounded passes by sub-area, each sized as above | per pass | per pass | one record, one section per pass |

If the run will exceed twice its budget, stop and report why before
continuing. A budget overrun is a calibration result, not a failure to hide.

## Lanes

Default lanes for a small package:

1. **Charter, consumers and history.** The charter questions, inbound
   consumers (framework packages and downstream applications, read-only),
   documentation drift, existing issues and prior audits. Revalidate every
   open issue against current code.
2. **Contracts and behavior.** The domain-contracts and http-ui-contracts
   profiles, as selected: invariants, lifecycle, access and refusal paths.
3. **Persistence, runtime and distribution.** The persistence-execution,
   kernel-runtime and distribution profiles, plus the public surface.
4. **Tests and gates.** What each test proves, discriminators for the most
   important assertions (mutation or preloaded-copy runs), and the mechanical
   gates.

Split lanes 2 and 3 by profile for a medium package. Every lane is read-only
on the repository and on consumer checkouts, writes probes only to scratch,
labels synthetic evidence as synthetic, and returns structured output with
the finding fields from the template, including attribution.

## Consolidate

One agent merges the lane outputs:

- dedupes findings by root cause, never by file;
- assigns stable IDs and fills attribution: discovered in, owned by, affected
  consumers, blocks this assessment;
- groups test and documentation gaps into one finding per coherent repair
  slice (for example "staff-directory tests don't discriminate the capability,
  pagination and duplicate-roster rules"), not one finding per missing test;
- assigns each finding its verification tier;
- lists lane conflicts with what would settle them, instead of picking a side.

## Verify by tier

| Tier | Applies to | Verification |
| --- | --- | --- |
| A | security-sensitive; medium or higher; removes or deprecates public surface; changes authority | two independent verifiers: one re-derives the evidence and re-runs or rewrites the probe, one tries to refute (intended and documented? overstated for real consumers? owned elsewhere? does the acceptance discriminate?) |
| B | low | one verifier doing both jobs; closely related low findings (same mechanism or file) may share one verifier, at most three per verifier |
| C | info, and grouped documentation gaps | one verifier per group checks a sample of at least three claims, or all of them when the group is smaller; one failed sample means the whole group is verified |

- **Tie-break** only when the two tier-A verdicts disagree on whether the
  finding exists, or on which side of the medium line it falls.
- **Re-tier** a finding when verification changes its severity: raised to
  medium or higher, it gets a second verifier.
- **Refuted leads:** one agent checks every refutation once. In the groups
  calibration this reopened 2 of 65.
- Apply corrections as verifiers report them: mechanism, severity, citations,
  owner and acceptance. Record each reversal in the scorecard.

## Close the leads

Every open lead ends as a finding, a merge into a finding, a refutation, or a
hand-off to the package that owns it. A security lead is always investigated
before the audit is called assessed. A non-security lead owned by another
package can be handed off without investigation: it becomes intake for that
package's audit.

## Write, then check once

- The orchestrator writes the private security brief itself, from verified
  evidence ([security triage](security-triage.md)). Never leave it to a late
  subagent transcription.
- One writer produces the human record and the structured ledger from the
  verified ledger, within the size budget.
- One critic checks the finish criteria, citations (sample about 8), the size
  budget and the redaction rules. At most two repair rounds; stop earlier when
  the critic passes.

## Scorecard

Keep one per audit, in the record and in the ledger, for at least the next
few audits after a method change:

- elapsed time, agent count and subagent tokens, per phase;
- production files and profiles applied;
- findings by severity, package-owned and cross-package counted separately;
- verification reversals: findings refuted, severity changes, mechanism
  corrections, refutations reopened, findings added from leads;
- human record length in lines, and ledger size;
- probes written, and probes retained in the repository;
- open decisions, and findings without a filed issue.

## Calibration baseline

The groups audit (2026-09-25, 13 source files, 5 profiles) ran the v1 method
with no budget and set the baseline this model is sized against:

- 121 agents (7 lanes, 1 consolidation, 98 two-lens verifications, 8 leads,
  1 refutation check, 1 writer, 3 critics and 2 repairs), about 20M subagent
  tokens and about 4.3 hours;
- 49 consolidated findings became 55 after leads and refutation checks, 4 of
  them security; verification refuted none, lowered 12 severities, corrected
  two mechanisms (one correction exposed the highest-value security finding)
  and reopened 2 refutations;
- a 2,420-line human record against the ai-vector record's 365.

Two-lens verification of every finding and one finding per missing test drove
most of the cost. Tiers and grouping target both.
