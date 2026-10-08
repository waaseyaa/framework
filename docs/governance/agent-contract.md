# Cross-Agent Operating Contract

This is the canonical operating contract for every automated agent working in
the Waaseyaa Framework repository. `AGENTS.md` is the single maintainer-agent
entrypoint. Applicable subsystem specs may add guidance within their scope,
but may not weaken or contradict this contract.

## Precedence and transparency

1. Platform safety, system, and user instructions remain highest authority.
2. This contract governs shared repository workflow and authorization.
3. `AGENTS.md` owns the architecture map, subsystem routing, and repository
   commands. Applicable `docs/specs/` files own enduring subsystem contracts.
4. Harness- or path-specific rules may supplement the above within their
   scope. When instructions conflict, stop using the lower-precedence rule and
   report the conflict.

Agents must identify applicable repository guidance when asked. A rule may be
followed silently during routine work, but it must never be concealed from the
maintainer.

## Authorization boundaries

- Permission to inspect, audit, diagnose, or recommend is read-only. It does
  not authorize edits, GitHub mutations, merges, releases, deployments, secret
  changes, or destructive cleanup.
- Permission to implement a change authorizes scoped repository edits and the
  verification needed for that change. It does not by itself authorize merge,
  release, deployment, or unrelated cleanup.
- Tool permission settings describe technical capability, not user authority.
  A broad allowlist or bypass mode never expands the task's scope.
- Delegated agents and external models inherit the same task scope. Their
  recommendations are evidence, not authorization.
- Before a destructive or externally consequential action, verify the exact
  target and confirm that the user's request covers the consequence.

## Starting and isolating work

- Read this contract, `AGENTS.md`, the relevant `docs/specs/` contracts, and
  `docs/specs/workflow.md` before substantive work.
- Inspect the working tree before editing. Preserve user and concurrent-agent
  changes. Use a separate worktree when the active checkout is dirty or serves
  another work unit.
- Select the repository Git entrypoint for the host. On supported POSIX hosts,
  use the repository `bin/git` adapter. On native Windows, where `bin/git` is a
  POSIX-only Bash entrypoint, use the Windows Git executable required by a
  higher-authority user or harness instruction. Record the exact executable
  when host selection matters. Never use `git stash`; commit recoverable work
  to a temporary branch instead.
- Use temporary directories for experiments, generated scratch projects,
  archive extraction, and commands with incidental writes.
- When a harness relocates the agent workspace root (Cursor
  `move_agent_to_root` and equivalents), that step typically fetches
  `origin/<current-branch>` in the destination. A brand-new local-only branch
  name fails that fetch. Preferred sequence: create the worktree on an
  already-remote tip (`origin/main` or another published branch), relocate the
  agent root if needed, then create the feature branch inside the worktree.
  Alternative: create the feature branch first, then push it with the selected
  Git entrypoint (`bin/git push -u origin HEAD` on a supported POSIX host, or
  the authorized Windows Git executable on native Windows) so the remote branch
  exists. Until the branch is on `origin`, keep editing via absolute paths in
  the worktree rather than relocating.
  Install worktree `vendor/` (or otherwise satisfy local gates) before the
  first push if pre-push preflight requires it.

## Maintained infrastructure before custom mechanisms

Across Framework runtime packages, build and maintenance tooling, audits and
skills, use maintained Symfony components for generic mechanisms when they
meet the required behavior. Evaluate suitable components even when they are
not installed. This is the default for new work and the target for existing
home-rolled infrastructure, not merely an optional audit comparison.

Keep Waaseyaa's domain policy, authorization and sovereignty decisions,
lifecycle guarantees and public contracts in Waaseyaa. Prefer direct component
use or the smallest adapter needed to preserve those contracts. Do not build
a parallel framework or a generic wrapper that recreates the component's API.

Before adding or materially extending a custom generic mechanism in any work,
or retaining one during an audit, verify the relevant supported Symfony
version against its source, tests and official documentation. Record the fit,
compatibility and installation
impact, the Waaseyaa behavior that remains, and equivalence tests for success,
refusal, failure and lifecycle behavior. Absence from the lockfile, fewer
dependencies, fewer lines or existing custom tests alone do not justify
maintaining an equivalent implementation.

A custom exception needs an evidenced capability or compatibility gap, an
owner and a review trigger. Temporary migration constraints require a bounded
follow-up with acceptance and a removal condition; they do not establish a
permanent architectural preference. Audits inventory existing reinventions and
route verified replacement opportunities into remediation. Routine fixes
record opportunities outside their scope rather than silently expanding into
unrelated migrations. Reviewers challenge new duplication and unsupported
retention decisions. This rule does not grant implementation or publication
authority beyond the user's task.

## Change workflow

### Alpha Framework convergence

During alpha, do not retain obsolete Framework code solely for backward
compatibility. Replace superseded paths and update current callers in the
authorized slice; remove legacy callbacks, aliases, no-ops, fallbacks and
parallel implementations. Public visibility, `@api`, deprecation metadata or
hypothetical consumers do not establish a retention obligation. The phase
contract is [stability-charter.md](../specs/stability-charter.md) §3.1.
Persisted-data integrity and required external integration contracts remain
separate obligations. This rule does not authorize data loss, external
migrations or deletion outside the assigned scope, and does not set product
repositories' compatibility policies.

An adapter must serve a current boundary with explicit producer and consumer
contracts. If it exists to reconcile inconsistent internal shapes, guessed
defaults, coercions or success-shaped fallbacks, prefer repairing one canonical
contract and its callers. Retention requires evidence of a necessary boundary,
not merely a passing test. Preserve real authorization and refusal semantics.

Initial package audits must disposition unused paths/state, repeated logic,
competing authorities and adapter necessity. A file roster, dependency graph
or green static gate is insufficient structural evidence. Supplement existing
audits where this evidence is missing; do not restart unaffected audit work.

### Specification convergence and repository hygiene

Audit the specifications as well as the implementation. Trace current
requirements through their canonical spec, real consumers and discriminating
acceptance evidence. Resolve conflicting or missing intended behavior before
repairing it; do not silently change the spec to match a defect. Reconcile
existing issues and related documents before creating new work.

During authorized documentation cleanup, prefer deleting superseded specs,
plans and examples over accumulating historical copies. Preserve still-valid
requirements and decision rationale in the current authority, update inbound
references and generated/corpus inputs, and validate their consumers. Git
history normally supplies the historical record. Retention needs a named
current obligation and owner, such as supported upgrade guidance or verified
audit evidence. Frozen evidence must not be rewritten to imply fresh proof;
migrate its consuming contract before deletion when required. Historical/read-
only labels prevent accidental use or revision, not deliberate reviewed
retirement. Age alone is not a deletion criterion.

- Anchor substantive work to a stable, repository-portable change record.
  Forge issue and PR numbers may mirror that identity but are not the authority.
- When the user has authorized an end-to-end delivery boundary, design and
  planning are checkpoints inside that scope. Continue through each already-
  authorized stage, including implementation, review, pull request,
  qualification, and landing, unless a material unresolved decision, blocker,
  or authority boundary requires user input.
- Work design-first and test-first: record the expected contract, capture a
  failing regression test, implement the smallest coherent change, then prove
  it green.
- Keep one review candidate per work package. Add an appropriate
  `changes/unreleased/<issue>.<slice>.<type>.md` fragment for the governed
  release compiler.
- Intermediate unpublished or branch commits may be recoverable checkpoints;
  do not claim they are individually release-ready. Qualification binds the
  review-candidate head. During the solo-maintainer sprint, reviewed batches
  may fast-forward directly to `main`; a pull request remains available but is
  not an acceptance prerequisite. The release-cut workflow preserves its exact
  gated SHA as the hard publication boundary; see
  `docs/cookbook/commit-qualification.md`.
- Review spec impact explicitly. Update enduring contracts when behavior or
  architecture changes. If a change set affects no specs, no trailer is
  required, and one is not accepted as a spec acknowledgement — the drift
  detector has nothing to acknowledge, so it warns and discards the trailer
  rather than rejecting the change. If a change set affects a spec you
  intentionally leave unchanged, carry a `spec-reviewed:` commit trailer
  naming that exact affected spec path and the reason, in the grammar
  `spec-reviewed: docs/specs/<name>.md - <reason>`.
- Respect package layers and existing boundary checkers. Any exemption belongs
  in the explicit allowlist with a concise rationale and a boundary test.
- An independent review requirement does not authorize parallel implementation
  or multi-agent delivery. Select a review method that remains within the
  user's authorized scope and preserves an independent perspective on the
  immutable candidate.

## Evidence and canonical state

- Derive status, counts, versions, and policy from current canonical sources;
  do not repeat stale summaries or hard-code volatile GitHub ruleset counts.
- GitHub and `origin/main` are the canonical publication state. Local branches
  and worktrees are candidates until pushed and accepted through governance.
- Verification is authoritative only when it binds the exact candidate,
  command, inputs, and supported runner. Run local pipelines with
  `set -o pipefail` where pipelines are used.
- Prefer the governed preflight receipt when it reports `reused exact identity`.
  Treat `hosted-required` and `not-applicable` as explicit non-pass states and
  retain the owning hosted check. Do not rerun an unchanged gate merely to
  replace reusable evidence, and do not cite a local receipt as hosted or
  release qualification.
- A manifest entry declared `hosted-only` is ownership metadata. Governed local
  preflight never launches it, including on Linux. A maintainer may run its
  command explicitly on a capable local host for diagnosis, but that result is
  not hosted evidence and cannot replace the named owning check. Continue
  supported local evidence, retain the incomplete verdict, and send the exact
  candidate to the named hosted owner.
- Follow [the local testing policy](../local-testing-policy.md) when selecting
  verification scope and reusing evidence. A full local suite is not a default
  baseline or a per-commit requirement. When the test plan requires the split
  Unit suite, run it with
  `php -d memory_limit=1G ./vendor/bin/phpunit --testsuite Unit --no-coverage`.
  Follow `AGENTS.md` and CI for additional scoped gates.

## Publication and operations

- Query the live ruleset and check state rather than relying on a documented
  count. Hosted CI on ordinary `main` updates is feedback and may be red while
  a tracked repair batch is in progress; record the failure and recovery owner.
- Land reviewed batches by a normal fast-forward push or an optional pull
  request. Never force-push or delete `main`, and never update `main` while a
  release split or fan-out is running.
- When full hosted qualification is green on the exact candidate, a
  fast-forward of that identical SHA to `main` needs the bounded main-feedback
  run, not another full qualification solely because the ref moved. Changed
  source bytes or an applicable release policy require new exact-head proof.
- Tags and package publication remain fail-closed: release-cut must prove the
  exact candidate green through its release gates before it can create a tag.
- A merge does not imply authority to tag, release, split packages, deploy, or
  mutate production. Those are separately authorized operations.
- Report what changed, what was verified, and any remaining authority boundary
  or uncertainty. Do not represent agent review as human approval.

## Agent entrypoint and consumer integrations

- `AGENTS.md` owns Framework maintainer guidance for every agent harness.
- `packages/foundation/.claude/rules/` is the canonical source distributed to
  consumer applications; `skeleton/.claude/rules/` must be an exact mirror.
  These consumer integrations do not define Framework maintainer instructions.
