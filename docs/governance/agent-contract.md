# Cross-Agent Operating Contract

This is the canonical operating contract for every automated agent working in
the Waaseyaa Framework repository. Harness-specific files such as `AGENTS.md`,
`CLAUDE.md`, and `.claude/rules/` may add routing or tool guidance, but they may
not weaken or contradict this contract.

## Precedence and transparency

1. Platform safety, system, and user instructions remain highest authority.
2. This contract governs shared repository workflow and authorization.
3. `CLAUDE.md` owns the architecture map, subsystem routing, and repository
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

- Read this contract, `CLAUDE.md`, the relevant `docs/specs/` contracts, and
  `docs/specs/workflow.md` before substantive work.
- Inspect the working tree before editing. Preserve user and concurrent-agent
  changes. Use a separate worktree when the active checkout is dirty or serves
  another work unit.
- Run repository Git commands through `bin/git`. Never use `git stash`; commit
  recoverable work to a temporary branch instead.
- Use temporary directories for experiments, generated scratch projects,
  archive extraction, and commands with incidental writes.
- When a harness relocates the agent workspace root (Cursor
  `move_agent_to_root` and equivalents), that step typically fetches
  `origin/<current-branch>` in the destination. A brand-new local-only branch
  name fails that fetch. Preferred sequence: create the worktree on an
  already-remote tip (`origin/main` or another published branch), relocate the
  agent root if needed, then create the feature branch inside the worktree.
  Alternative: create the feature branch first, `bin/git push -u origin HEAD` so
  the remote branch exists, then relocate. Until the branch is on `origin`,
  keep editing via absolute paths in the worktree rather than relocating.
  Install worktree `vendor/` (or otherwise satisfy local gates) before the
  first push if pre-push preflight requires it.

## Change workflow

- Anchor substantive work to a stable, repository-portable change record.
  Forge issue and PR numbers may mirror that identity but are not the authority.
- Work design-first and test-first: record the expected contract, capture a
  failing regression test, implement the smallest coherent change, then prove
  it green.
- Keep one review candidate per work package. Add an appropriate
  `changes/unreleased/<issue>.<slice>.<type>.md` fragment for the governed
  release compiler.
- Intermediate unpublished or branch commits may be recoverable checkpoints;
  do not claim they are individually release-ready. Qualification binds the
  review-candidate head. Ordinary merges to `main` use the governed squash
  auto-merge path. The release-cut workflow preserves its exact gated SHA as
  a distinct boundary; see `docs/cookbook/commit-qualification.md`.
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

## Evidence and canonical state

- Derive status, counts, versions, and policy from current canonical sources;
  do not repeat stale summaries or hard-code volatile GitHub ruleset counts.
- GitHub and `origin/main` are the canonical publication state. Local branches
  and worktrees are candidates until pushed and accepted through governance.
- Verification is authoritative only when it binds the exact candidate,
  command, inputs, and supported runner. Run local pipelines with
  `set -o pipefail` where pipelines are used.
- Run the split Unit suite with
  `php -d memory_limit=1G ./vendor/bin/phpunit --testsuite Unit --no-coverage`.
  Follow `CLAUDE.md` and CI for additional scoped gates.

## Publication and operations

- Every required branch-protection check must pass on the exact PR head. Query
  the live ruleset/check state; never rely on a documented numeric count.
- Merge only through the repository's governed auto-merge path. Never merge to
  `main` while a release split or fan-out is running.
- A merge does not imply authority to tag, release, split packages, deploy, or
  mutate production. Those are separately authorized operations.
- Report what changed, what was verified, and any remaining authority boundary
  or uncertainty. Do not represent agent review as human approval.

## Harness adapters

- `AGENTS.md` is the Codex and generic-agent entrypoint.
- `CLAUDE.md` is the Claude Code architecture and orchestration entrypoint.
- Root `.claude/rules/` contains Claude-specific supplemental mechanics.
- `packages/foundation/.claude/rules/` is the canonical source distributed to
  consumer applications; `skeleton/.claude/rules/` must be an exact mirror.
