# Running an audit with agents

How to size and verify a package audit without duplicating investigation.
The method is in the skill; this is the execution model.

Parallel agents need authorized multi-agent scope (see the agent contract).
Review and verification roles are always delegated to subagents, whether they
run concurrently or serially. Their prompts, evidence, and verdict format must
work with any available subagent implementation and must not select a model or
vendor. Without authorization to delegate those roles, leave their evidence
pending; the orchestrator must not replace them with self-review.

## Size the run first

Size by source lines of code, inbound consumer packages and applications,
and intake items (see the skill's step 1), not only by file count. Write the
lanes and budget in the scorecard before any agent starts.

Start with one investigator for a bounded package. The topics below are
coverage responsibilities, not a mandatory agent roster. Split investigation
only when independent boundaries, risk or volume justify it and parallel scope
is authorized. Size large packages as bounded sub-area passes with one index
record, rather than repeating whole-package intake in each lane.

Record a run-specific time/token budget when those measurements are available,
the required verification roles and the uncertainties that could expand work.
Do not use the historical 50/75-agent estimates as targets or evidence of
quality. Reuse investigators and batch related evidence; preserve independent
verification where the tier requires it.

**Checkpoint after consolidation.** Estimate remaining boundary batches,
verifier work, probes and open decisions against the run's budget. Report a
material projected overrun before expanding the run. Narrow or sequence passes
without silently dropping coverage or lowering verification tiers. Record
unfinished coverage honestly when the agreed limit cannot accommodate it.

## Lanes

Coverage responsibilities, which one investigator may cover:

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

When splitting work, prefer boundaries within topic 2 or 3 over overlapping
whole-package passes. Every lane is read-only on the repository and on consumer checkouts, writes
probes only to scratch, labels synthetic evidence as synthetic, and returns
structured output with the finding fields from the template, including
attribution.

## Lane write isolation

A read-only instruction is not a control; in the groups calibration a writer
agent edited skill files it was told not to touch. Check it mechanically
around every agent run, with the skill's `scripts/lane-integrity.php`:

1. Before the run, snapshot every checkout and directory the agents can reach,
   allowing only this run's output area:
   `php <skill>/scripts/lane-integrity.php snapshot --out=<file outside every root> --git=<audit worktree> --git=<skill source worktree> --git=<each consumer checkout> --tree=<scratch> --tree=<each installed skill copy> --allow=<scratch>/<run>`.
   Keep the `sha256:` line it prints in the orchestrator's own context; the
   snapshot file is refused inside an allowed area.
2. After the run: `php <skill>/scripts/lane-integrity.php verify --snapshot=<file> --sha256=<digest>`.
   Exit 0 means nothing changed outside the allowed areas.
3. Exit 1 lists the changes; exit 2 means the check couldn't run or can't be
   trusted (a Git command failed, a root is gone, the snapshot doesn't match
   its digest). Either way, stop. Don't use output that depends on the changed
   paths, find the agent in the transcripts, and report it to the maintainer.
   Don't revert anything without the maintainer's approval; verify never
   repairs.

The check compares content, not metadata: HEAD, branch, refs, stash, local
config and the worktree list; the working-tree and index diffs; a hash of
every file `git status` reports (tracked changes, both sides of a rename,
untracked files); and a hash of every file under a `--tree` root. A second
write to an already-dirty file, or a same-size rewrite with the old mtime
restored, is a change.

Give each lane its own output directory under the run's area, and never put
the method's own files (the skill source or installed copies) inside an
allowed area. A change reported under a shared checkout's refs, worktree list
or stash can come from another session; confirm before attributing it.

Name a writable temporary directory inside each lane's area in its prompt,
even for a read-only lane. Agents cache fetched issues and command output in
files whatever the prompt says: in the groups citation check, eight lanes told
to write nothing added 83 cache files elsewhere in the scratch directory. The
check caught it, but only a named place keeps that from stopping a run.

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

Batch findings that share a contract, composition root or reproduction setup.
Two independent tier-A verifiers may each cover the same bounded batch; each
finding still needs both verdicts and its own evidence/refutation. Do not
dispatch a fresh pair for every finding merely to increase agent count. Keep
security custody and genuinely different expertise boundaries separate.

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
- One critic checks the finish criteria, citations (sample about 8), the size
  budget and, holding the brief, the redaction rules. The ledger's shape is
  checked by `php bin/lib/package-audit-ledger.php`, not by the critic. At most two repair rounds, each re-checking only the
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
