# FW-PUBLISHING-DRAFT-PROJECTION-01 — published projection survives ordinary draft saves

Status: implementing  
Anchor mirror: waaseyaa/framework#2562  
Parent candidate: `ee08759fa1016d25485fd51c3ee228bd2d16cf4d`

## Intent

Keep the served base-row projection on the live published revision while an
editor continues to save ordinary drafts. Generic public reads (`find()`,
`findMany()`, access-checked listings) must match `loadPublishedRevision()`.
Do not invent a storage rule that treats pointer presence as discipline
(Playbook H).

## Decisions

1. `ContentPublisher` is the editorial mutation door. After
   `loadPublishedRevision()` is non-null, `updateDraft()` asserts the working-copy
   revision, clears `SaveContext::withExpectedRevisionId()`, arms default-revision
   discipline, and forces a new revision.
2. Same-state published working copies fork `workflow_state` to `draft` (the
   shipped editorial initial state) so a bound workflow cannot auto-republish
   the edit. Illegal edges fail closed at PRE_SAVE.
3. Unbound `publish()` pins `published_revision_id` through
   `EntityRepository::promotePublishedRevision()`, which applies complete
   promotion after `BeforeRevisionPointerMoveEvent` so the base row is rewritten
   from the selected revision without a workflows subscriber.
4. `setPublishedRevision()` stays pointer-only unless a subscriber (or
   `promotePublishedRevision()`) applies default-revision semantics.
5. `save(..., validate: false)` still honors the discipline flag when it is
   set (`DefaultRevisionDisciplineTest`). Import/bootstrap callers that do not
   arm the flag keep tip-tracking writes.
6. Unbound `unpublish()` asserts the working-copy token the surface hands out.
   A diverged draft is not saved onto the base row. `clearPublishedRevision()`
   sets served `status=0` and nulls `published_revision_id`, leaving the
   published snapshot as the base `revision_id`. After the pointer is gone,
   later drafts tip-track.
7. Later unbound `publish()` restores a working copy forked to
   `workflow_state=draft` back to the served state's id, then promotes the
   save-hydrated revision id.
8. `loadRevision()` does not overlay live bundle-subtable columns onto a
   revision that is not the base pointer. `shouldCreateRevision()` duck-checks
   `isNewRevision()` so trait-only types honor `setNewRevision(true)`.

## Invariants

- Live published pointer + `updateDraft()` → new working revision; base
  `revision_id === published_revision_id`; `find()` title equals
  `loadPublishedRevision()` title.
- Explicit later `publish()` moves both the pointer and the served row to the
  selected working revision and restores a forked `workflow_state`.
- `publish()` → `updateDraft()` → `unpublish()` accepts the working-copy
  token, does not leak draft bytes into `find()`, and leaves
  `loadPublishedRevision()` null.
- No published pointer → draft saves still update the base row.
- Pointered but undisciplined repository save still writes the base row.

## Verification evidence

- `ContentPublisherTest` projection, republish, unpublish-after-draft,
  never-published, stale-revision, workflow-state restore, and Playbook-H
  negative-control methods.
- `DefaultRevisionDisciplineTest` column-stored working-copy hydration and
  `clearPublishedRevision()`; `EntityRepositoryOptimisticLockingTest`
  trait-only `setNewRevision(true)` on `revisionDefault: false`.
- `DefaultRevisionDisciplineTest::promote_published_revision_rewrites_the_base_row_without_a_workflows_subscriber`
  and the existing `validate: false` / Playbook-H storage tests.
- Focused PHPUnit and `php bin/check-pr-preflight` on this candidate; exact SHA
  and hosted checks are recorded on the pull request.
