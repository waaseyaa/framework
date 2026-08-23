# Worktree coordination

Status: REVIEW CANDIDATE

Related: `docs/specs/workflow.md`, `docs/governance/agent-contract.md`,
`docs/change-records/FW-WORKTREE-COORDINATION-v1.md`

## Purpose

Concurrent agents and humans need one repository-owned way to answer which Git
worktrees exist, who owns them, whether any process or evidence workflow still
uses them, and which exact paths may be removed. The protocol is conservative:
uncertainty protects data rather than converting it into cleanup authority.

## Lease registry

Each repository stores `waaseyaa-worktree-leases.json` in its Git common
directory. It is untracked and shared by linked worktrees. A record binds the
repository common directory, canonical absolute worktree path, branch or
detached commit, owner/task, creating agent, acquisition timestamp, intended
lifecycle, opaque lease id, and optional release timestamp.

Acquisition is atomic and refuses a second active lease for the same path.
Release requires the opaque lease id and retains the record as ownership
evidence. Leases never expire automatically; an absent agent cannot silently
turn active evidence into disposable state.

## Inventory

Inventory accepts one or more explicit repository paths and is read-only. Its
JSON and human views report registered and recorded paths, branch/detached HEAD,
commit, staged/modified/untracked state, upstream ahead/behind, detached commits
unreachable from refs, active Git operations, live process cwd/file-descriptor
references, filesystem presence, registry state, protection reasons, and
cleanup eligibility.

The following always protect a path:

- missing or unknown ownership;
- active lease or process reference;
- staged, modified, or untracked content;
- merge, rebase, cherry-pick, revert, or bisect state;
- detached commits unreachable from repository refs;
- custody or retained lifecycle;
- registered metadata without a directory, or recorded directory no longer
  registered;
- any inventory or command error that prevents a fact from being established.

## Cleanup protocol

`cleanup plan` is the default cleanup operation. It writes a new canonical JSON
manifest only to an explicit absolute output path. Each item binds repository,
exact absolute worktree path, HEAD, branch/detached state, released lease id,
and the inventory fingerprint. Shell syntax, globs, relative paths, unresolved
variables, the repository's primary worktree, and protected entries are refused.

`cleanup apply` is separately explicit. It verifies the manifest identity and
re-inventories each item. Any drift aborts before that item. Removal invokes
`git worktree remove -- <exact-path>` without force. After every call it checks
registration and filesystem state and reports one of:

- removed: deregistered and absent;
- failed-no-change: still registered and still present;
- partial-residue: deregistered but still present;
- partial-metadata: registered but absent.

It never stashes, resets, cleans, checks out, deletes refs, creates tags, prunes,
or escalates permissions.

## IDE boundary

Agent execution is path-scoped, not IDE-checkout-scoped. A task can run commands
directly in its isolated absolute worktree. No checkout or workspace migration
is required, and a migration prompt must not be answered by stashing,
committing, or discarding unrelated changes.
