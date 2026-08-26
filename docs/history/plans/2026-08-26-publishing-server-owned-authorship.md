# FW-PUBLISHING-AUTHORSHIP-01 — server-owned draft authorship

## Change record

- Stable id: `FW-PUBLISHING-AUTHORSHIP-01`
- Parent candidate: `a9fab5067f50524bd0cc7d70149208e8231d82c1`
- Forge mirror: `waaseyaa/framework#2588`
- Consumer evidence: `jonesrussell/sheguiandah-waaseyaa#132`

## Problem

`ContentPublisher::createDraft()` records the acting uid in `SaveContext`,
which owns revision authorship, but does not populate an authored entity's
owner field. Since #2516 correctly applies the entity-view policy to publisher
reads, a creator with only `view own unpublished content` creates an authorless
draft and then receives indistinguishable `NOT_FOUND` from get, revisions, and
preview.

## Decision

`ContentTypeDescriptor` gains an optional `authorField`. It is application
schema declaration, not client input. When declared:

1. the field must be a non-empty machine name distinct from the status field;
2. it must not appear in `writableFields`;
3. `createDraft()` requires an authenticated positive integer actor id;
4. the publisher stamps that id into the entity before the first save;
5. `SaveContext::withActorUid()` continues to record the same id as revision
   author through the existing independent storage boundary; and
6. the server-owned author participates in the idempotency fingerprint so one
   publisher cannot replay another publisher's authored create response.

Descriptors without authorship retain their current behaviour. No view policy,
capability, update rule, projection, or generic entity contract changes.

## Verification plan

- Descriptor rejects an empty, status-owned, or client-writable author field.
- A descriptor-declared authored draft persists entity author and revision
  author as the acting uid.
- The creator passes access-checked get, list, revisions, working-copy preview,
  and exact-revision preview; another principal with the same capability does
  not.
- An opaque/non-numeric actor is refused before any authored draft is written.
- Reusing an authored-create idempotency key as a different actor conflicts
  rather than replaying the first actor's response.
- Existing descriptor call sites remain source compatible.
- Publishing package tests, split Unit/Integration/Architecture suites, full
  preflight, static analysis, and dead-code gates pass.

## Deliberate descopes

- No `administer nodes` or broad unpublished-view permission.
- No inference of entity ownership from revision metadata after persistence.
- No client-controlled author id and no edit-time author reassignment.
- No universal author field for entity types that do not model authorship.
