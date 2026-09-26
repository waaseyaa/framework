# Security triage during an audit

A finding is security-sensitive when it could break one of the invariants in
the repository's `SECURITY.md` through a realistic framework or
supported-profile path, or falls in one of its reportable classes: for
example authorization bypass, cross-community access, credential compromise,
disclosure of data a principal shouldn't read, authority kept after it should
be lost, code execution, release or artifact compromise, durable integrity
loss, unbounded parsing or resource use, or a control that silently
downgrades. When in doubt, treat it as security-sensitive until tier-A
verification rules it out.

Treat it that way from the moment a lane or verifier flags it; don't wait
until the record is written.

## Steps

1. **Flag and number.** The orchestrator gives the lead an ID in the
   `<PKG>-SEC-NNN` series as soon as it is flagged, so no public text ever
   carries its specifics under another ID.
2. **Verify at tier A.** Two independent verifiers. At least one traces
   reachability from every production source of the principal or request
   (HTTP middleware, bearer and session paths, CLI, jobs, agent tools, MCP)
   and names which consumers are exposed, with their configuration. A
   hand-built principal proves the code path, not the exposure. When the
   verifiers disagree on whether it is security-sensitive, keep the SEC ID and
   let the maintainer decide; never move it to a public ID before triage.
3. **Write the private brief.** The discovering orchestrator writes it, from
   the verified evidence, before the public record is final. Never delegate it
   to a late subagent transcription: a subagent's write of exploit specifics
   can be cut short, and the brief is the one artifact triage can't rebuild
   cheaply.
4. **Keep it in durable private custody.** A location the maintainer
   designates, outside every repository, worktree and temporary or session
   directory, one file per audit or per finding, with a named holder. Ask for
   the location when none is designated. A session scratch directory is only a
   staging area. Never a commit, a public issue, a PR body, the ledger or the
   coverage index. Make it a verified copy: an inventory with a SHA-256 per
   file, checked against the copy, and the directory's access list checked.
   Keep the staging originals until the audit and its PR are complete.
5. **Publish a safe summary.** See "Public rows" below.
6. **Redaction check.** Before the record and ledger are final, the
   orchestrator, or a critic given the brief, reads both for anything that
   localizes the issue (see below) and removes it. The ledger validator
   enforces the mechanical part: no string in a security row, however nested,
   cites a file, line or symbol, and no other ledger entry or record line
   names a security ID next to one. Method names in prose, request shapes and
   sibling mechanism detail still need the read.
7. **File when authorized.** File the private report through the route in
   `SECURITY.md` (the framework's private vulnerability reporting) as soon as
   the maintainer authorizes it, independent of any other slice or milestone.

## Public rows

Every security finding appears in public text as one row, in the package
findings table when the audited package owns it, or in the cross-package table
when another package does:

- its SEC ID;
- one plain sentence naming the class of issue, such as "a mutation refusal
  can disclose stored data before authorization";
- one owning package (co-owners stay in the brief);
- severity "withheld", confidence and evidence level as verified;
- destination "private report (framework advisory; owner `waaseyaa/<pkg>`)";
- affected consumers "withheld".

The record may also say that a brief exists and who holds it, without its
location.

## The private brief

One section per finding, headed "PRIVATE - do not commit or publish":

- ID, source finding or lead, owning package and co-owners, provisional severity;
- what happens, with file:line;
- the reproduction: the principal, request or call, and the observed result;
- reach: every production path checked, which are open, which are closed and
  why (and whether "closed" is by design or by accident);
- consumer exposure: each known consumer, its configuration, exposed or not;
- the probe directories that reproduce it;
- acceptance: the discriminating test that proves the fix, including a
  negative control;
- residual risk, and anything left unassessed for triage to start with.

The owning package's audit receives the brief through the maintainer, not
through the ledger.

## What localizes a finding

Keep all of these out of public text, including the ledger:

- the file:line, method name or spec step of the vulnerable branch;
- request shapes, payloads, headers, principals, permissions or identifiers
  from the reproduction;
- probe file names, case names or output files that reproduce it; in the
  ledger, a security finding's probes are a count only;
- roster, checklist, evidence or ledger cross-references that tie a SEC ID to
  a file, method or checklist item;
- mechanism detail in a sibling public finding that, read next to the safe
  summary, points at the same branch or the conditions that trigger it;
- a public citation that, read next to the safe summary, points at the branch
  (for example the spec step documenting the same ordering).

The safe summary may name the owning package and the class of issue. That is
enough for a reader to know a private report exists.

## Milestones

An audit can be **assessed** with security findings open when each has been
verified at tier A, has a private brief in durable custody, a named owning
package and the advisory route. Filing the report needs authority but no
milestone: file it as soon as authority is given. **Repair ready** checks that
it has been filed.
