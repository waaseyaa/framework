# Phase 3 - Shadow-Collision Guard + Duplicate-Registration DX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close Phase 3 of the groups-extraction arc by making duplicate entity-type registration a deterministic, provenance-aware error with migration-grade DX, while also capturing the real-DB diagnostic gap called out in `framework#1313` without over-claiming what the guard can catch.

**Execution gate:** Phase 3 is **not further gated**. Phase 1 and Phase 2 both landed on `main` on 2026-04-20, and the packaged-form CI lane now exists. The next executor should not invent an extra prerequisite branch or wait-state beyond this plan's own merge-gated tasks.

**Status snapshot (2026-04-20):**
- `framework#1313` is still open and still describes two asks: a clear duplicate-registration error and a real-DB diagnostic when bundle-field registration reaches a missing-subtable state.
- The current framework collision check lives in `Waaseyaa\Entity\EntityTypeManager::registerEntityType()`, but it throws a generic `InvalidArgumentException` with no registrant provenance. `ProviderRegistry` only knows the provider class outside the throw site, so today's error cannot name both registrants cleanly.
- Minoo `main` no longer re-registers `group` / `group_type` in `AppServiceProvider`; that historical collision was already stripped during adoption. The concrete migration case that remains is **shadow-class residue**: `src/Entity/Group.php`, `src/Entity/GroupType.php`, and the still-importing tests and call sites that later phases will remove.
- The canonical reconciliation record extending ADR 0001 is currently `docs/history/superpowers/specs/2026-04-19-groups-reconciliation-adr.md`. The old `docs/decisions/0001-group-polymorphism.md` path is still drifted and should not be used as a source of truth.

**Architecture:** Phase 3 is primarily a **registration-lifecycle guard**, not a schema or storage refactor. The deterministic collision point is when `EntityTypeManager` persists a definition into its registry: by then the manager knows the incoming type id and class, and the framework can be taught to know who registered the already-present definition. That is the right place to distinguish "same id registered twice" from "consumer attempted to shadow a framework-owned canonical class with a different class." The real-DB diagnostic ask from `#1313` is related but separate: `addBundleFields()` itself is too early to decide that a subtable is "missing" because subtable materialization happens later on schema/storage resolution. If that notice remains in scope, it must hook at a later deterministic point.

**Tech Stack:** PHP 8.4, PHPUnit 10/11, framework kernel boot via anonymous subclass + `publicBoot()`, `EntityTypeManager`, `ServiceProvider`, `ProviderRegistry`, packaged-form consumer harness under `tests/PackagedForm/skeleton/`, GitHub Actions `CI / packaged-form`.

---

## File Structure

**Modify (PR 1 - provenance-aware collision guard):**
- `packages/entity/src/EntityTypeManager.php` - replace the generic duplicate-registration branch with a dedicated collision exception path.
- `packages/entity/src/EntityTypeManagerInterface.php` - extend the registration signature only if provenance must be surfaced at the interface level.
- `packages/foundation/src/ServiceProvider/ServiceProvider.php` - preserve registrant provenance at `entityType()` capture time.
- `packages/foundation/src/Kernel/Bootstrap/ProviderRegistry.php` - pass provider provenance into registration instead of discovering it after the exception.

**Create (PR 1 - dedicated exception):**
- `packages/entity/src/Exception/EntityTypeRegistrationCollisionException.php` - named exception for duplicate-id / shadow-collision failures.

**Create or modify (PR 1 - unit coverage):**
- `packages/entity/tests/Unit/EntityTypeManager*Test.php` - unit coverage for duplicate registration and provenance-aware messages.

**Create (PR 2 - real-kernel integration coverage):**
- `tests/Integration/Phase25/EntityTypeCollisionIntegrationTest.php` - boots the real kernel path via anonymous subclass + `publicBoot()`, exercises provider-driven collisions, and proves the edge-case non-collision path still works.

**Create or modify (PR 2 - real-DB diagnostic if retained):**
- `packages/entity-storage/tests/Integration/` or `tests/Integration/Phase25/` coverage for the `#1313` notice ask, but only if the implementation hooks at a deterministic post-schema point.

**Modify (PR 3 - migration guidance):**
- `UPGRADING.md` if introduced as the repo-wide upgrade ledger, or an equivalent migration note file if the executor finds a clearly better established location.
- `CHANGELOG.md` only if the repo's release-note practice requires a short pointer for the new guard.

**Does not touch:** schema shape, `waaseyaa/groups` canonical entity definitions, Minoo code, Phase 5 `HasCommunityInterface` reconciliation, or Phase 6 `GroupType` entity-key reconciliation.

---

## Why This Exists

Phase 1 closed the kernel-path blind spot. Phase 2 closed the packaged-artifact blind spot. What remains is a **consumer-migration blind spot**: when a downstream app keeps shadow classes or re-registers a canonical type, the framework currently fails with low-provenance DX at the exact moment the developer most needs directed guidance.

The Minoo adoption sequence showed the shape clearly:

1. `Waaseyaa\Groups\GroupsServiceProvider` became the canonical owner of `group` and `group_type`.
2. Consumer shadow classes and historical registrations created ambiguity about which class was authoritative.
3. The framework could fail loudly, but not **helpfully**: the message did not name the already-registered owner, the incoming registrant, or the migration path.

Phase 3 turns that ambiguity into a contract:

1. The registry records who registered each entity type.
2. Duplicate ids fail deterministically at registration time.
3. Shadow-class attempts get a more specific message than same-class duplicate re-entry.
4. Consumers are pointed at the migration path rather than left to infer it from a generic "already registered" error.

This phase is intentionally narrow. It does not remove shadow classes from Minoo; it makes their re-introduction or continued registration impossible to miss and easier to unwind.

---

## Hook Choice

**Recommended hook:** `EntityTypeManager::registerEntityType()` / `persistDefinition()`, with explicit registrant provenance passed in from provider registration.

**Why this hook is correct:**
- Collision is defined on the registry key (`entityTypeId`), and that key is only authoritatively owned at registration time.
- The manager has both sides of the comparison available or can be taught to: existing definition + incoming definition.
- The manager is already the place that rejects duplicate ids today, so the behavior stays where callers expect it.
- `ProviderRegistry` knows the provider class but only at orchestration time. Passing provenance in preserves determinism without using `debug_backtrace()`.

**Why not hook only in `ProviderRegistry`:**
- That would cover provider-driven collisions but miss non-provider callers that still use `EntityTypeManager` directly in tests or app code.
- It would split collision semantics across multiple entrypoints instead of keeping one source of truth in the registry.

**Why not hook the `#1313` notice at `addBundleFields()`:**
- `addBundleFields()` is pre-materialization. Healthy boots legitimately have no `{base}__{bundle}` subtable yet at the moment bundle fields are registered.
- Emitting "missing subtable" at that point would false-positive on the normal kernel path.
- If the notice remains in scope, it must fire at first deterministic schema/write contact instead: after `ensureTable()`/storage resolution or on a save-path mismatch, not at registration.

---

## Collision Semantics

Phase 3 needs two explicit collision cells with different DX:

1. **Duplicate registration, same canonical class**
   - Shape: entity type id already registered, incoming class is the same as the existing class, registrants differ or the same registrant re-enters.
   - Example: provider A and provider B both register `group` with `Waaseyaa\Groups\Group::class`, or the same provider registers it twice.
   - Outcome: fail with a duplicate-registration message naming both registrants. This is usually stale registration or boot duplication, not class shadowing.

2. **Shadow collision, different class for same id**
   - Shape: entity type id already registered, incoming class differs from the existing class.
   - Example: framework already owns `group` as `Waaseyaa\Groups\Group::class`; a consumer attempts to register `group` as `App\Entity\Group::class`.
   - Outcome: fail with a shadow-collision message naming the canonical class, the conflicting consumer class, and the migration path ("drop the shadow registration; migrate callers to the canonical type / later reconciliation path").

**Non-collision edge case:**
- Consumer registers a brand-new entity type id that the framework does not provide.
- Example: `community`, `cultural_group`, or any app-owned type unrelated to `group`.
- Outcome: registration remains valid and unchanged.

**Opt-out posture:**
- Default posture is **no opt-out**. Intentional override of a framework-owned entity type id is a smell, not an extension point.
- The plan should state this explicitly in both code comments and migration guidance so future work does not quietly add an `allowOverride` escape hatch.
- If a future product truly needs override semantics, that should be a separate ADR and a new contract, not an implicit parameter hidden inside this phase.

---

## Error Class and Message Contract

**Recommendation:** introduce `Waaseyaa\Entity\Exception\EntityTypeRegistrationCollisionException`.

**Why a dedicated class:**
- This is not a generic runtime failure; it is a specific configuration / registration contract violation.
- Callers and tests can target it directly instead of matching generic `InvalidArgumentException` strings.
- It gives room for helper constructors or error codes without bloating `EntityTypeManager`.

**Suggested message shape:**

For same-class duplicate:

```text
[ENTITY_TYPE_DUPLICATE] Entity type "group" is already registered by Waaseyaa\Groups\GroupsServiceProvider using Waaseyaa\Groups\Group. Duplicate registration attempted by App\Provider\AppServiceProvider using the same class. Drop the duplicate registration.
```

For shadow collision:

```text
[ENTITY_TYPE_SHADOW_COLLISION] Entity type "group" is already registered by Waaseyaa\Groups\GroupsServiceProvider using canonical class Waaseyaa\Groups\Group. Conflicting registration attempted by App\Provider\AppServiceProvider using App\Entity\Group. If this was a consumer shadow, drop the registration and migrate callers to the canonical type; see the Phase 3 upgrade note.
```

**DX requirements:**
- Include the entity type id.
- Include the already-registered provider class when known.
- Include the already-registered entity class.
- Include the conflicting provider class when known.
- Include the conflicting entity class.
- Include a short migration pointer, not just a failure statement.

Unknown-provenance fallback is acceptable for direct non-provider tests, but the framework path must supply both registrants.

---

## Packaged-Form Coverage Recommendation

**Recommendation:** do **not** add a second standing packaged-form assertion as part of Phase 3's merge-gated implementation.

**Reasoning:**
- The collision itself is deterministic in the in-tree registry and better exercised by a real-kernel integration test that intentionally boots conflicting providers.
- The existing packaged-form fixture is a minimal green-path consumer. Forcing it to contain a broken duplicate registration would turn the standing downstream harness into a failure fixture.
- Phase 2's packaged-form job should still be used for **release-gated verification** after the Phase 3 alpha ships: it proves the guard did not regress the normal consumer path.

**What to do instead:**
- Keep merge-gated coverage in unit + real-kernel integration tests.
- Use the existing packaged-form lane as the release-gated smoke cell after publication.
- If a future regression proves duplicate-registration behavior differs only after packaging, add a dedicated packaged-form collision fixture then. Do not pre-emptively complicate the harness now.

---

## Consumer Migration Guidance

Phase 3 should ship a short upgrade note aimed at downstream consumers:

- If you register an entity type id already provided by a framework package, registration now fails with a dedicated collision exception.
- If you were shadowing `group` / `group_type`, drop the duplicate registration instead of overriding the canonical type.
- Minoo is the concrete example:
  - live duplicate registration is already gone on `main`,
  - but shadow classes and test imports still exist,
  - and later phases of the arc provide the `HasCommunityInterface` and `GroupType` migration path.

**Location recommendation:** create a top-level `UPGRADING.md` if no equivalent file exists at execution time. This repo currently has no obvious upgrade ledger, so introducing one is cleaner than burying the only consumer migration note in a phase-specific doc.

---

## Ordering Rationale (Do Not Reshuffle)

1. **Provenance-aware collision exception lands first.**
   - Everything else depends on the registry carrying enough context to distinguish duplicate re-entry from shadow collision.
2. **Real-kernel integration coverage lands second.**
   - The core DX must be proven through the same provider/kernel path consumers actually hit.
3. **Migration guidance lands third.**
   - Once the behavior and test contract are stable, write the operator-facing note that downstream consumers will need.
4. **Published-alpha verification is last and release-gated.**
   - This is the same circularity rule Phase 2 used: the first real packaged-form proof against a released alpha cannot gate the merge that creates the behavior.

---

## Task 1 - PR 1: Add provenance-aware collision exception in the registry

**Files:**
- Modify: `packages/entity/src/EntityTypeManager.php`
- Modify: `packages/entity/src/EntityTypeManagerInterface.php` only if the provenance argument must be public API
- Modify: `packages/foundation/src/ServiceProvider/ServiceProvider.php`
- Modify: `packages/foundation/src/Kernel/Bootstrap/ProviderRegistry.php`
- Create: `packages/entity/src/Exception/EntityTypeRegistrationCollisionException.php`
- Create or modify: `packages/entity/tests/Unit/*`

**Branch:** `feat/1313-entity-type-collision-exception`

- [ ] **Step 1: Create branch from main**

```bash
cd /home/fsd42/dev/waaseyaa
git checkout main
git pull --ff-only
git checkout -b feat/1313-entity-type-collision-exception
```

- [ ] **Step 2: Record registrant provenance instead of inferring it after the fact**

Extend the registration path so the manager can know who registered each definition.

Recommended shape:
- `ServiceProvider::entityType()` captures `static::class` alongside the `EntityTypeInterface`.
- `ProviderRegistry` forwards that registrant class when calling `EntityTypeManager`.
- `EntityTypeManager` stores provenance keyed by entity type id next to the definition.

Do **not** use `debug_backtrace()` to infer the registrant. The call stack is not the contract.

- [ ] **Step 3: Introduce `EntityTypeRegistrationCollisionException`**

Create a dedicated exception class under `packages/entity/src/Exception/`.

Recommended constructor inputs:
- entity type id
- existing registrant class (nullable)
- existing entity class
- incoming registrant class (nullable)
- incoming entity class

It may expose named constructors such as `duplicate()` and `shadowCollision()` if that keeps `EntityTypeManager` readable.

- [ ] **Step 4: Replace the generic duplicate branch with two explicit collision cells**

In `EntityTypeManager::persistDefinition()`:
- if the id is new, register normally;
- if the id already exists and the class matches, throw the duplicate-registration variant;
- if the id already exists and the class differs, throw the shadow-collision variant.

Expected outcome: registry behavior is deterministic and messages are provenance-aware.

- [ ] **Step 5: Add unit coverage**

Add tests covering:
1. duplicate id, same class, different registrants -> duplicate-registration exception
2. duplicate id, different class -> shadow-collision exception
3. new id, new class -> registration succeeds

Message assertions must include both registrants and both classes where known. This is a DX contract, not just an exception-type contract.

- [ ] **Step 6: Run local validation**

At minimum:

```bash
composer validate
composer phpstan
./vendor/bin/phpunit packages/entity/tests/Unit
```

Expected: green. If API changes force broader test fallout, stop and surface the public-surface consequence instead of hand-waving it.

- [ ] **Step 7: Commit and push**

PR title should make clear that this PR is the registry/provenance layer, not the integration layer.

**Merge posture:** merge-gated. This is the foundational behavior change for the rest of Phase 3.

---

## Task 2 - PR 2: Add real-kernel integration coverage (and the real-DB diagnostic if retained)

**Files:**
- Create: `tests/Integration/Phase25/EntityTypeCollisionIntegrationTest.php`
- Optionally create or modify: additional integration coverage for the `#1313` real-DB notice

**Branch:** `test/1313-shadow-collision-kernel-path`

- [ ] **Step 1: Create branch from main (after PR 1 is merged)**

```bash
cd /home/fsd42/dev/waaseyaa
git checkout main
git pull --ff-only
git checkout -b test/1313-shadow-collision-kernel-path
```

- [ ] **Step 2: Write a real-kernel collision test using the Phase 1 harness pattern**

Boot through anonymous subclass + `publicBoot()` only.

The test fixture should create a temporary project root with:
- consumer-owned `composer`/provider shape as needed for the kernel path,
- one canonical provider that registers `group`,
- one conflicting provider that attempts duplicate or shadow registration.

Positive collision cells to cover:
1. framework-like provider registers `group` as canonical `Waaseyaa\Groups\Group`
2. consumer provider attempts to register `group` again with `App\Entity\Group`
3. boot fails with `EntityTypeRegistrationCollisionException` and the full message contract

Negative / edge cells:
1. canonical provider alone boots cleanly
2. consumer provider registers a distinct id such as `consumer_group_extension` and boot succeeds

Do **not** introduce named kernel subclasses under `tests/**`.

- [ ] **Step 3: Decide whether the `#1313` notice stays in this phase**

Issue `#1313` also asks for a notice when bundle-field registration reaches a missing-subtable state.

Before implementing, the executor must keep this constraint explicit:
- `addBundleFields()` itself is too early and would false-positive.

If the phase keeps the notice ask, hook it only at a deterministic post-schema point and add a corresponding integration test.

If the executor concludes the notice should be split into a separate follow-up because it is materially distinct from duplicate-registration DX, stop and report rather than guessing. The issue body makes it related, but the code path is different enough that it may justify its own tracked follow-up.

- [ ] **Step 4: Run local validation**

At minimum:

```bash
composer validate
composer phpstan
./vendor/bin/phpunit tests/Integration/Phase25
```

Expected: green. Broader integration failures unrelated to the touched files are not in scope; touched-file coverage is.

- [ ] **Step 5: Commit and push**

PR title should make clear that this is the kernel-path / integration proof of the collision guard.

**Merge posture:** merge-gated. This is the behavior proof for the registry change.

---

## Task 3 - PR 3: Add downstream migration guidance

**Files:**
- Create or modify: `UPGRADING.md` or equivalent migration-note file
- Optionally modify: `CHANGELOG.md` if release-note practice requires a short forward pointer

**Branch:** `docs/1313-shadow-collision-upgrade-note`

- [ ] **Step 1: Create branch from main (after PR 2 is merged)**

```bash
cd /home/fsd42/dev/waaseyaa
git checkout main
git pull --ff-only
git checkout -b docs/1313-shadow-collision-upgrade-note
```

- [ ] **Step 2: Write the migration note**

The note should be short and concrete:
- what changed,
- what exception consumers will now see,
- how to interpret duplicate vs shadow-collision wording,
- what to do if the consumer was shadowing `group` / `group_type`,
- where the later migration path lives (Minoo example; later phases 5 and 6).

Do not over-promise. Phase 3 gives the guard and DX; it does not itself reconcile `HasCommunityInterface` or `GroupType` key naming.

- [ ] **Step 3: Self-check wording against the actual Minoo state**

The note must not claim that Minoo `main` still re-registers `group` / `group_type`; that historical state is gone.
It may say:
- Minoo adoption is the motivating example,
- shadow classes still exist,
- later arc phases remove them.

- [ ] **Step 4: Run local validation**

For a docs-only PR:

```bash
composer validate
composer phpstan
```

If the repo has a docs lint step by then, include it. Otherwise do not invent one.

- [ ] **Step 5: Commit and push**

PR title should make clear that this is migration guidance for the new guard.

**Merge posture:** merge-gated but small. This is the operator-facing completion of the DX work.

---

## Task 4 - Release-Gated Verification: Verify against a published alpha on the packaged-form lane

**Repo:** waaseyaa (operational step, not necessarily a code PR)

- [ ] **Step 1: Merge Tasks 1-3**

All merge-gated work must already be on `main`.

- [ ] **Step 2: Cut the first alpha containing the Phase 3 guard**

This is the first moment the packaged-form lane can exercise the published artifact containing the collision guard.

- [ ] **Step 3: Observe `CI / packaged-form` green against that alpha**

Expected: the normal consumer fixture remains green. This is a regression check that the new guard did not disturb the valid downstream path.

- [ ] **Step 4: Record release-gated verification in the issue / follow-up PR description**

This step is release-gated, not merge-gated. Do not block the code PRs on a published artifact that does not yet exist.

---

## Defect Classes This Catches

Phase 3 is specifically meant to catch these classes:

1. **Duplicate registration of the same entity type id.**
   - Whether same class or different class, the registry now rejects the second registration deterministically.
2. **Consumer shadowing of a framework-owned canonical entity.**
   - Example: trying to register `group` as `App\Entity\Group` after `Waaseyaa\Groups\GroupsServiceProvider` already owns it.
3. **Low-provenance migration failures.**
   - The message now points at both registrants and the migration path instead of surfacing only "already registered."
4. **Regression of the green-path consumer boot after the guard ships.**
   - The release-gated packaged-form check proves the guard does not break legitimate downstream installs.

---

## What Phase 3 Does Not Catch

Future readers must not treat this guard as a universal safety net. It does **not** replace:

1. **Kernel-path or packaged-form bundle-subtable coverage.**
   - That remains Phase 1 + Phase 2 territory.
2. **Minoo's remaining shadow-class cleanup.**
   - Phase 3 makes shadow re-registration a hard error; it does not itself delete `src/Entity/Group.php` or `src/Entity/GroupType.php`.
3. **`HasCommunityInterface` reconciliation.**
   - That is a later consumer-side migration path, not a framework registry concern.
4. **`GroupType` entity-key reconciliation.**
   - Same: later migration work, not a registry guard.
5. **Every possible registration foot-gun.**
   - The guard keys on duplicate entity type ids and the optional real-DB diagnostic from `#1313`. It does not validate all schema compatibility or migration ordering errors by itself.

---

## Known Pitfalls

- **Do not use `debug_backtrace()` for registrant provenance.** That couples the DX contract to call-stack shape and makes refactors dangerous.
- **Do not hook the real-DB diagnostic at `addBundleFields()` itself.** That point is pre-materialization and would false-positive on healthy boots.
- **Do not quietly add an override flag.** Intentional shadowing is a smell; if someone wants override semantics, they need a new ADR and an explicit extension contract.
- **Do not write tests that bypass the real kernel path for provider-driven collisions.** The integration proof must use the anonymous-subclass + `publicBoot()` pattern from Phase 1.
- **Do not write migration guidance against stale Minoo assumptions.** Minoo `main` no longer has live duplicate registration in `AppServiceProvider`; the remaining migration case is shadow-class residue and test imports.

---

## Forward References

Phase 3 is the DX prerequisite for the later consumer cleanups:

- **Phase 5 - `HasCommunityInterface` reconciliation**
- **Phase 6 - `GroupType` entity-key reconciliation**

Those phases depend on Phase 3 existing so a Minoo consumer that still tries to shadow or re-register canonical group types gets an actionable framework error instead of an opaque failure.

This plan does **not** re-plan those phases. It only names the dependency.

---

## Exit Criteria

**Merge-gated:**
1. Duplicate registration throws `EntityTypeRegistrationCollisionException`.
2. Same-class duplicate and different-class shadow collision each have distinct, asserted message shapes.
3. Real-kernel integration coverage proves the guard fires on provider-driven boot and allows genuinely new consumer ids.
4. Migration guidance exists in `UPGRADING.md` or a clearly equivalent location.

**Release-gated:**
5. The first published alpha containing the Phase 3 guard is observed green on the existing packaged-form lane.

---

## Suggested PR Sequence

| Task | Scope | PR count | Merge blocker? |
|---|---|---|---|
| 1 | Provenance-aware collision exception in registry | 1 | Yes |
| 2 | Real-kernel integration coverage (and `#1313` notice only if deterministically scoped) | 1 | Yes |
| 3 | Migration guidance / upgrade note | 1 | Yes |
| 4 | Published-alpha verification on packaged-form lane | 0-1 | Release-gated only |

**Phase 3 total expected PRs:** 3 merge-gated PRs, plus 1 release-gated verification step if repo settings or release notes need a tiny follow-up.
