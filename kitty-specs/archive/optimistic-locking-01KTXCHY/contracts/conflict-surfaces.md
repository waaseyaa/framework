# Contract: Conflict Surfaces (Agent Tool + JSON:API)

**Mission**: optimistic-locking-01KTXCHY | **Requirements**: FR-005, FR-006, FR-008, NFR-003, C-001, C-004

Applies to `EntityUpdateTool`/`EntityReadTool`/`EntityListTool` in `packages/ai-tools` and `JsonApiController::update()`/`show()` + `JsonApiError` in `packages/api`. Both surfaces translate the storage contract ([conflict-detection.md](conflict-detection.md)); neither implements its own check.

## entity.update tool (FR-005)

1. **Argument**: optional top-level `expected_revision_id` (JSON Schema `integer`, `minimum: 1`). It is an argument, not a value: `revision_id` inside `values` remains refused by `EntityKeyGuard` (kind `revision`) exactly as before — the expectation can never collide with a writable field.
2. **Threading**: when present, the save call is `$repository->save($entity, context: SaveContext::default()->withExpectedRevisionId($n))`. The repository must be the concrete `EntityRepository` (the only SaveContext-capable implementation — interface deliberately not widened); a stated expectation against any other repository implementation returns the `revision_expectation_unsupported` error (clause 4), never a silent plain save.
3. **Conflict error**: `RevisionConflictException` is caught specifically (before the generic `\Throwable` arm) and mapped to the Mission 1 two-block error shape — a human-readable text block plus `['type' => 'json', 'data' => ['error' => 'revision_conflict', 'entity_type' => …, 'id' => …, 'expected' => N, 'current' => M]]`, `isError: true`. `current` is `null` when the entity vanished. Machine-correctable by construction: the agent re-reads (or uses `current` directly), re-diffs, retries.
4. **Unsupported-path error**: storage `\LogicException` rejections (and the clause-2 non-concrete-repository case) map to the same two-block shape with `data.error = 'revision_expectation_unsupported'` and a `reason` string. Distinct from `revision_conflict` — agents must not retry these.
5. **Order preserved**: capability → argument shape → type known → load (404-style error) → entity access `update` → `EntityKeyGuard` refusal → field set → save. The conflict surfaces at save time (after validation — storage contract clause 3); the new argument introduces no new ordering ahead of the access check.
6. **Dry-run parity** (spec edge case): a dry run carrying `expected_revision_id` loads the entity and compares the head; a mismatch returns the byte-identical `revision_conflict` payload of clause 3 (same `data` members, same values for the same world-state); a match returns the existing `would_update` success. A dry run without the argument is byte-identical to today. (The dry-run check is a read-compare — it cannot be authoritative; only the real save's guarded claim is. Documented, not hidden.)
7. **Success payload**: gains `revision_id` = the post-save head (read back from the saved entity), so a chaining agent can state its next expectation without a re-read.
8. **No-expectation invariance**: calls without `expected_revision_id` behave byte-identically to today — same checks, same save call, same payloads (FR-003 at the tool level).

## JSON:API conditional update (FR-006)

9. **Request seam**: the PATCH body's resource-object meta — `data.meta.expected_revision_id` (positive integer). Headers do not reach the controller (`WaaseyaaContext` carries no headers), so `If-Match` is explicitly NOT part of this contract; a future additive change may map `If-Match` onto the same SaveContext seam without altering this body seam.
10. **Validation**: a present-but-invalid value (non-integer, `< 1`) → 400 `Bad Request`. An expectation on a type that is not single-axis revisionable → 422 `Unprocessable Entity` (controller screen; the storage `\LogicException` remains the invariant backstop).
11. **Pipeline**: an expectation-stated PATCH persists through `getRepository()->save($entity, context: …)` — the standard (revision-aware) persistence pipeline. **Consequence, stated plainly: an expectation-stated PATCH on a revisionable type cuts a new revision and dispatches the repository lifecycle events.** Repository validation failures map to 422.
12. **Conflict response**: `RevisionConflictException` → **409** with a single error object: `status '409'`, `title 'Conflict'`, `code 'REVISION_CONFLICT'`, a detail naming type/id/expected/current, and `meta: {expected_revision_id: N, current_revision_id: M}` (`current_revision_id` null when the row vanished). Deterministic and assertable (NFR-003).
13. **`JsonApiError` widening is additive**: a trailing `array $meta = []` ctor param, emitted by `toArray()` only when non-empty — every existing error response is byte-identical. `conflict()` gains optional `code`/`meta` passthrough; the pre-existing `data.id`-vs-uuid 409 keeps its codeless shape (the `code` member is the machine-readable discriminator between the two 409s).
14. **No-expectation invariance**: a PATCH without `data.meta.expected_revision_id` (or without `data.meta` at all) follows the existing `getStorage()->save()` path byte-identically — same checks, same responses, same events (FR-003/SC-003).
15. **Locator honesty**: uuid-routed PATCHes resolve to the real entity id before the save; the conflict payload names the real id, not the request locator.

## Revision exposure on reads (FR-008)

16. **API**: `GET /api/{type}/{id}` (and collection reads) emit `revision_id` as an attribute on revisionable types — already true (the serializer excludes only id/uuid keys), now PINNED by test and documented load-bearing: removing or renaming it is a consumer break.
17. **Tools**: `entity.read` emits a top-level `revision_id` member (duck-typed `getRevisionId()`; omitted for non-revisionable types — absence means "no expectation formable"); `entity.list` items carry the same optional member (entities are already loaded — zero added queries); `entity.list_revisions` is unchanged (already exposes per-revision ids).
18. **Conflict payloads are themselves reads**: both surfaces' conflict bodies carry the current head, so the re-read-and-retry loop can skip a round-trip.

## End-to-end (SC-001)

19. `tests/Integration/AgentRun/DualWriterConflictTest.php` proves the dual-writer story against a kernel-booted repository: writer A reads head R via the read surface; writer B updates (head → R+1); writer A's `entity.update` with `expected_revision_id: R` returns the structured `revision_conflict` error (B's write intact, A's write absent); writer A re-reads, restates, succeeds. The same world exercised without expectations documents today's last-write-wins for contrast.

## Verification

- Unit: `EntityUpdateToolConflictTest` — argument schema; conflict mapping (payload member-by-member); unsupported mapping; dry-run parity (clause 6 byte-comparison); success `revision_id`; no-expectation invariance against the existing tool test fixtures.
- Unit: `EntityToolRevisionExposureTest` — read/list exposure incl. non-revisionable omission.
- Unit: `JsonApiControllerConflictTest` — the full request-state table from data-model.md ("API surfaces"); the 409 body shape; uuid-locator resolution; no-expectation byte-invariance; the `show()` `revision_id` attribute pin. `JsonApiErrorTest` — meta emission/omission.
- Integration: `DualWriterConflictTest` (clause 19).
