# Repository, specification and framework reconciliation

Use this during intake and closeout for every package, and when coordinating
the framework-wide program. Package files alone are not the audit boundary.
Reuse existing records and evidence; supplement older audits instead of
restarting them. Record missing access or unsearched surfaces as coverage gaps.

## Find the existing work before creating more

At program intake, enumerate all open repository issues and PRs with complete
pagination, and the items in the active GitHub Project. Record repository,
timestamp, queries and limits. Search relevant closed issues, merged PRs and
their discussions for earlier decisions, purported fixes and reopened defects.
For a package, filter that inventory by names, namespaces, capabilities,
consumers and existing audit IDs, not just its label or directory name.

Read each relevant issue's acceptance and material comments against current
code, specs and landed evidence. Classify it as an unresolved defect, partially
repaired, satisfied, duplicate, superseded, intentional future feature, or
out-of-scope work with an owner. Reuse its identity for the same defect. A
closed issue is not proof of a fix; an open one is not proof of a current bug.
Do not create a competing umbrella or close a parent on partial acceptance.

Use the ledger's existing `issue_reconciliation` entries for issue claims,
observed state, disposition and evidence. Record inventory completeness and
search scope in `checklists`/`evidence_runs`; put access gaps in `not_reviewed`.
GitHub changes remain a separately authorized delivery action. A proposed
closure is not a completed closure.

## Audit specifications as requirements

Inventory relevant specs, ADRs, change records, previous audits, package and
root READMEs, cookbooks, upgrade notes, examples, agent guidance and skill
resources. Search beyond the package directory, including historical trees
when they contain a decision or reference relevant to current behavior.

For each document, name its owner and current role: live contract, draft,
explanatory guide, required evidence, or superseded material. Check that the
claimed authority, lifecycle, symbols, commands and examples are still true.
Age alone does not establish obsolescence; a recent document can be wrong.

Trace important requirements in both directions:

| Requirement or decision | Canonical spec section | Implementation and consumers | Discriminating test/evidence | Disposition or gap owner |
| --- | --- | --- | --- | --- |
| A named invariant | One current authority | Real composition path | Success plus applicable refusal/failure | satisfied / repair / revise / retire / decision |

Include undocumented implemented behavior, unimplemented promises, conflicting
specs, stale closed-issue deferrals and acceptance criteria without witnesses.
An implementation/spec mismatch is a finding, not permission to rewrite the
spec to bless the implementation. Resolve intended behavior first. Approved
contract changes precede or accompany their bounded repair, with a failing
regression or another discriminating acceptance check. Update callers and docs
in that repair. Do not turn SDD into retrospective descriptions of whatever
code happens to do.

Use existing `checklists`, `decisions`, `findings` and `remediation_plan` fields
for this traceability. No second ledger or schema is needed. Keep the human
record concise; identify all related documents in the ledger evidence.

## Prune superseded material

Prefer deleting obsolete specs, duplicate plans and misleading examples from
the working tree once their current obligations have been reconciled. Git
history preserves earlier text. Do not create another archive or retain a
redirect stub solely to avoid deleting a file.

Before deletion, establish the successor or explicit retirement decision,
carry forward any still-valid requirement or decision rationale, and check
inbound links, skill routing, code generation, corpus manifests, tests and
evidence consumers. Update live references in the same change. Run the owning
manifest/link checks. Remove superseded passages inside otherwise live specs
as well as obsolete whole documents.

Retain a historical file only for a named current obligation, such as a
supported upgrade procedure, durable finding evidence, or a governed artifact
whose exact bytes are still verified. Record that owner and review/removal
condition. A test using an old document as generic sample data is a candidate
for a small purpose-built fixture, not automatic permanent retention. Do not
rewrite frozen evidence to make an old audit appear current: preserve its
bound identity, or migrate the consuming evidence contract explicitly before
removal. Unsettled retention work gets a bounded destination.

## Prove the framework composes

Package assessment and layer checks cannot establish whole-framework design
quality. The program owns a small, risk-based set of cross-package journeys,
linked to existing acceptance tests and programs rather than duplicated:

- fresh installation, site contract, schema/config activation and application boot;
- authenticated create/read/update/delete through API, storage, access and audit;
- publication, listing/search/cache visibility after mutation and deletion;
- job/notification delivery through retry, restart, duplicate work and failure;
- generated and upgraded applications, curated metapackages, optional capability
  presence/absence and production installs without development dependencies.

For each applicable journey, record the participating packages, canonical
owner for each shared contract, supported installation/host profile, source
and lock identity, success and refusal/failure evidence, and remaining gaps.
Exercise transaction ownership, event order, authorization context, cache
invalidation, lifecycle/reset boundaries and error propagation where involved.
Use terminal effects and discriminators, not only mocked peer calls.

Trace suspected dead chains from composition roots across package and named
downstream repository boundaries. A chain of mutually referencing unused
classes can look alive to a symbol search. Reflection, generated code and
public extension consumers need explicit evidence; unavailable downstream
checkouts limit the deletion claim. Accepted cycles and allowlists need a
current rationale and owner, not just an unchanged baseline.

## Closeout and the clean-board standard

Refresh issue, PR, document and Project inventories after repairs. Reconcile
every in-scope item to landed evidence or remaining work; detect missing items,
duplicates, open issues marked Done and closed issues still shown as active.
Preserve readiness, delivery status, priority, ordering and release membership
as distinct fields under their owning contracts. Board fields do not prove
acceptance or authorize issue closure.

Framework-wide remediation is complete when confirmed framework defects and
required convergence repairs are resolved with acceptance evidence, supported
composition journeys are qualified, and live specs/docs agree with that
outcome. Intentional future features remain in a separately triaged backlog.
An accepted residual requires an explicit maintainer decision and rationale;
moving a defect to Later or renaming it a feature does not resolve it.

Report assessment coverage, repair completion, qualification, document cleanup
and actual GitHub reconciliation separately. Existing package milestones stay
intact: an assessed package can have repairs outstanding. Do not use completion
of the assessment phase, a clean-looking board, or a green static graph as a
claim of whole-framework convergence or release readiness.
