# Upgrade Notes: Same-State Pointer Moves Now Require an Into-State Transition Permission

**Introduced in:** CW-v1 WP-2 task 2.6 (#1920, branch `feat/cw-v1-wp2`).
**Subsystem:** `packages/workflows/src/Listener/WorkflowPointerMoveGuard.php`.
**Spec linkage:** [`../specs/content-workflow.md`](../specs/content-workflow.md) ("Pointer-move guard reconciliation").

---

## Summary

For workflow-bound entity types, a revision-pointer move that does not change
the effective workflow state (implied from-state === to-state, e.g. promoting
a forward draft while the published pointer already sits on a
`published`-stamped revision, or rolling back to an earlier revision in the
same state) was previously an **unconditional pass-through** in
`WorkflowPointerMoveGuard`. It now requires, when an acting account context
exists, that the account hold the permission of **at least one transition
targeting that state** (any-of over all transitions into the state). A null
account context (CLI, queue, bootstrap) remains allowed, unchanged.

## Who is affected

Authenticated callers of `EntityRepository::rollback()`,
`setCurrentRevision()`, or `setPublishedRevision()` on workflow-bound content
who hold **no** transition permission into the revision's state: those calls
now throw `TransitionDeniedException` (`REASON_PERMISSION`) instead of
silently succeeding. Grant the relevant `use <workflow> transition <id>`
permission (e.g. `use editorial transition publish` for moves among
`published`-stamped revisions) to restore access.
