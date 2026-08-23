# FW-WORKTREE-COORDINATION-v1 — concurrent worktree custody

- Parent: `9fd6096a8ac5624e82b9c1874cda0149864260c8`
- Contract: `docs/specs/worktree-coordination.md`
- Forge mirror: `waaseyaa/framework#2522`
- Authority: repository tooling, exact Git objects, local lease records, tests,
  and review evidence; no merge, release, deployment, or cleanup authority

## Sequence

1. Specify a read-only-first inventory and explicit lease protocol shared by
   Framework, Sheguiandah, and Anokii worktrees.
2. Capture adversarial fixtures for dirty, detached, active-process, stale,
   residue, and concurrent-evidence states.
3. Implement inventory, lease, immutable cleanup-plan, and revalidated cleanup
   application commands.
4. Verify the exact candidate locally and through hosted review without
   removing any existing developer worktree.

## Decisions

- A lease record is stored in the repository's Git common directory, never in
  the tracked worktree. Releasing a lease preserves its ownership and intended
  lifecycle record while removing the active-removal block.
- Inventory never mutates a repository. Cleanup planning writes only the
  explicitly named manifest outside the repository; cleanup application is a
  separate, explicit command.
- Unknown ownership, an active lease, dirty state, active Git operation, live
  process reference, detached unique commit, custody lifecycle, stale metadata,
  or unregistered residue is protected.
- Cleanup uses only exact absolute paths and `git worktree remove` without
  `--force`. It revalidates every planned fact immediately before mutation.
- IDE workspace migration is unnecessary. Agents may execute commands in an
  isolated absolute worktree while leaving the visible IDE checkout unchanged.

## External interlock

Implementation, tests, commits, push, and a draft pull request are authorized
for this work unit. Removing pre-existing worktrees, deleting refs, merging,
tagging, releasing, deploying, and changing repository settings are not.
