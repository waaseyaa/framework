# S1-FW-DB-03 implementation plan

Change record: `S1-FW-DB-03`  
Parent: `4f0eaeaa69733f7cf6fb3c91d5a0a98c354ff72d`  
Parent tree: `d0564935a578238ddd23ef73250867a64540137d`

## Decisions

- Use one universal aggregate mutation authority, never revision IDs as the
  general concurrency authority.
- Split create/update/delete intent and refuse omission-based existing writes.
- Claim aggregate version before transactional write-capable events.
- Keep tombstones to prevent delete/recreate ABA.
- Make every supported mutation surface carry the same opaque token.
- Replace fixed-TTL scheduler locks with renewable leases, stable domains,
  global fences, deterministic occurrences, and guarded sinks.
- Treat queue dispatch as enqueue, not task completion.
- Preserve forge neutrality. GitHub may mirror progress but is not required.

## Work packages

1. Bind the reviewed design and complete mutation/lease inventory to the exact
   DB-02 predecessor.
2. Retain executable failures for aggregate update/delete/batch/history and
   scheduler acquire/renew/fence/occurrence boundaries.
3. Add DB-02-managed mutation and lease schemas plus public token/lease value
   objects and transactional authority repositories.
4. Convert the repository boundary and internal storage coordinator/driver
   paths.
5. Convert JSON:API, Admin, GraphQL, MCP/AI, CLI, workflow, publishing,
   migration, translation, and batch surfaces.
6. Convert scheduler and queue ownership, including all five unstable overlap
   closures.
7. Run two-process race, overrun, ambiguity, crash, fault, packaged-form, and
   exact Sheg compatibility proofs.
8. Reconcile independent review and seal the exact candidate evidence.

## Deferrals

- Multi-node/PostgreSQL certification remains H1 work.
- Releases, deployments, production mutation, backup/restore, and recovery
  exercises remain separately authorized.
- Finding closure waits for exact downstream Sheg proof.
