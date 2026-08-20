# ADMIN-REVISION-RECOVERY-01 — generic entity revision recovery

- Parent: `cf4bd663ae5fa96b11683e48fdd487b6214ee192`
- Parent tree: `6b31570ffc4c854166099a39da28fda8349b8cfc`
- Contract: `docs/specs/admin-spa.md`
- Forge mirror: `waaseyaa/framework#2464`
- Authority: local source changes, synthetic verification, and an unmerged review candidate only

## Outcome

The package-owned generic entity editor can inspect a selected saved revision
through the same record and field-access policies as the ordinary detail read,
compare it with the working copy, request an application-authoritative preview
for that exact revision when one is available, and restore its content only by
copying it forward into a new conflict-checked draft.

## Sequence

1. Specify exact-revision read and restore envelopes; retain failing tests for
   authorization, field filtering, non-disclosure, and concurrent refusal.
2. Delegate exact reads to `loadRevision()` and restores to `rollback()` with
   both the observed mutation token and observed latest revision id.
3. Define an optional exact-revision preview authority.
4. Reuse one package-owned history workspace from full and embedded editors.
5. Record the contract, changelog, generated distribution, and exact evidence.

## Interlocks

- Metadata history remains content-free.
- Revision content is serialized only after record-view and per-field checks.
- Restore requires update authority and both observed fences, copies forward,
  and never changes workflow/publication state directly.
- Preview is omitted without an application authority and is bound to the
  exact selected saved revision when present.
- Rollback audit remains content-free and identifies actor, record, source,
  result, action, and outcome.
- Page-builder recovery is unchanged and must not be forked.

No merge, publication, release, deployment, production operation, secret
mutation, or repository-settings change is authorized by this record.

## Evidence disposition

The candidate records focused host/Admin tests, type/lint gates, deterministic
Node 24 distribution proof, preflight results, and exact commit/tree identity.
