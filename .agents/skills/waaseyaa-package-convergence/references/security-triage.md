# Security triage during an audit

A security finding is one where a real principal could gain authority, read
data it shouldn't, or keep authority it should have lost. Treat it as
security-sensitive from the moment a lane or verifier flags it; don't wait
until the record is written.

## Steps

1. **Flag and number.** The orchestrator gives the lead an ID in the
   `<PKG>-SEC-NNN` series as soon as it is flagged, so no public text ever
   carries its specifics under another ID.
2. **Verify at tier A.** Two independent verifiers. At least one of them
   traces reachability from every production source of the principal or
   request (HTTP middleware, bearer and session paths, CLI, jobs, agent tools,
   MCP) and names which consumers are exposed, with their configuration. A
   hand-built principal proves the code path, not the exposure.
3. **Write the private brief.** The orchestrator writes it, from the verified
   evidence, before the public record is final. Never delegate it to a late
   subagent transcription: a subagent's write of exploit specifics can be cut
   short, and the brief is the one artifact triage can't rebuild cheaply.
4. **Keep it outside the repository.** A private local file, or the private
   advisory draft once filing is authorized. Never a commit, a public issue,
   a PR body, the ledger or the coverage index.
5. **Publish a safe summary.** The record's security rows give the ID, one
   plain sentence on the class of issue, the owning package, severity
   "withheld", and "private report" as the destination.
6. **Redaction check.** Before the record is final, a reviewer holding the
   brief reads the public record and the ledger for anything that localizes
   the issue (see below) and removes it.

A cross-package security finding is owned by the package with the defect. The
discovering record lists only its ID and owning package.

## The private brief

One section per finding, headed "PRIVATE - do not commit or publish":

- ID, source finding or lead, owning package, provisional severity;
- what happens, with file:line;
- the reproduction: the principal, request or call, and the observed result;
- reach: every production path checked, which are open, which are closed and
  why (and whether "closed" is by design or by accident);
- consumer exposure: each known consumer, its configuration, exposed or not;
- the probe directories that reproduce it;
- acceptance: the discriminating test that proves the fix, including a
  negative control;
- residual risk, and anything left unassessed for triage to start with.

## What localizes a finding

Keep all of these out of public text:

- the file:line, method name or spec step of the vulnerable branch;
- request shapes, payloads, headers, principals, permissions or identifiers
  from the reproduction;
- probe file names or case names that reproduce it;
- a public citation that, read next to the safe summary, points at the branch
  (for example the spec step documenting the same ordering).

The safe summary may name the package and the class of issue ("a mutation
refusal can disclose stored data before authorization"). That is enough for a
reader to know a private report exists.

## Milestones

An audit can be **assessed** with security findings open when each has a
verified private brief, a named owning package and a named advisory route
(`SECURITY.md`). Filing the private report is publication and needs
authority; it belongs to the **repair-ready** milestone.
