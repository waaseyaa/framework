# Groups-extraction arc close — phased implementation plan

**Date:** 2026-04-19
**Status:** design (pre-implementation)
**Fixed sequence:** framework#1315 → framework#1313 → minoo#741
**Arc-additional scope:** two ADR-driven reconciliations (HasCommunityInterface placement, GroupType entity-key naming) executed inside the #741 slot.

---

## Context

Postmortem of the alpha.148–152 adoption chain (Minoo adopting `waaseyaa/groups`) surfaced that the framework's in-tree test suite stayed green across four consecutive releases while packaged-form behavior regressed silently. Source-tree co-resolution masked the kernel-path gaps. The three issues in scope close the arc:

- **framework#1315** — packaged-form CI exercising the kernel path as a composer-installed consumer sees it. Harness everything downstream validates against.
- **framework#1313** — shadow-collision guard: registry rejects or errors clearly on duplicate content-type registration. Turns a convention into an enforced constraint.
- **minoo#741** — remove Minoo's `App\Entity\Group` and `App\Entity\GroupType` shadow classes. 11 test files still import them.

The fixed sequence is non-negotiable: #1315 before #1313 because the guard must be validated on the real consumer path; #1313 before minoo#741 because the shadows exist as a workaround for the guard.

### Reconciliations gating minoo#741

1. **`HasCommunityInterface` placement.** Minoo's shadow `Group` implements it via `HasCommunityTrait`; canonical `Waaseyaa\Groups\Group` does not. Must be resolved before the shadow can be dropped.
2. **`GroupType` entity-key naming.** Framework: `['id' => 'id', 'label' => 'label']`. Minoo shadow: `['id' => 'type', 'label' => 'name']`. Must be reconciled before callers switch to the canonical type.

### Verified state (2026-04-19)

- `src/Entity/Group.php` and `src/Entity/GroupType.php` present in Minoo.
- 11 test files import `App\Entity\Group*`:
  - Integration (3): `GroupsBaselineTest`, `GroupBundleRoutingTest`, `SeedContentTest`
  - Unit (8): `GroupAccessPolicyTest`, `BusinessControllerTest`, `CopyrightFieldTest`, `GroupHasCommunityTest`, `GroupTest`, `GroupTypeTest`, `FeedItemFactoryTest`, `FeedAssemblerTest`
- Canonical `Waaseyaa\Groups\GroupsServiceProvider` registers `group` (keys include `langcode`) and `group_type` (keys `id`, `label`) with zero pre-registered bundles.

---

## Phase 0 — Scope the reconciliations (decisions, not code)

**Repo:** neither. Workspace-local deliverable.
**Deliverable:** standalone ADR markdown file authored at `docs/history/superpowers/specs/2026-04-19-groups-reconciliation-adr.md`. **Not committed via PR in either repo.** User copies it manually into the cross-product docs folder alongside ADR 0001.

### Decision 1 — HasCommunityInterface placement

- **(recommended) Domain adapter service in Minoo.** `App\Domain\GroupCommunity` with `hasCommunity(Group $g): bool` and `communityFor(Group $g): ?Community`. Deletes interface + trait. Every `instanceof HasCommunityInterface` callsite migrates to the service. Single location encodes the field-name→entity mapping. Trivially mockable in tests. Intent-revealing at callsites.
- **(rejected) Field-presence checks on every callsite.** Same scope as the adapter (same callsites, same interface/trait removal) but trades capability-via-class for stringly-typed field access. The literal `'community'` becomes a magic constant every caller must know; every caller repeats the `hasField(...) && get(...)?->getEntity()` dance; future reshape of the field ripples through every callsite. Same failure mode as shadows, re-encoded as string literals.
- **(quick) Boundary decorator in Minoo.** Repository returns `App\Entity\CommunityAwareGroup` wrapping canonical `Group`. Keeps existing `instanceof` checks working. **Cost:** two types referring to the same row; runtime decoration must be remembered at every load path. Re-introduces a shadow variant at runtime.
- **(do not) Lift the interface into framework.** Violates the Waaseyaa-doesn't-import-Minoo rule (CLAUDE.md).

**Recommendation:** domain adapter.

### Decision 2 — GroupType entity-key naming

- **(clean, per ADR) Minoo migrates data columns.** Rename `group_type.type`→`id`, `group_type.name`→`label`. One-time cost, permanently aligned. Smell eliminated: two-sources-of-truth on the same logical key.
- **(quick) Teach framework `EntityType` to accept overridable key names per consumer.** Speculative generality; no second consumer needs it today. **Cost:** pollutes framework surface with a hook for one caller's preference.

**Recommendation:** migrate Minoo.

### Exit criterion

ADR markdown file written at `docs/history/superpowers/specs/2026-04-19-groups-reconciliation-adr.md` capturing both decisions and their rationale. **Not committed via PR** — user copies manually into the cross-product docs folder. Zero code changes.

**Standalone PR?** No — workspace-local artifact.
**Ordering flag:** runs in parallel with Phases 1–3. Decision 1 validation only requires a read-only grep over Minoo's `instanceof HasCommunityInterface` callsites.

---

## Phase 1 — framework#1315 part A: in-tree kernel-path integration test

**Repo:** waaseyaa.
**Status:** core assertion already landed as `packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php` (commit `070970de`, 2026-04-19). Phase 1 is the cluster of cleanups and guards around that landed test, not a greenfield test write.

**Scope:** Four artefacts, strictly ordered:
1. **This spec amendment** (docs-only PR, first) — aligns the spec with the already-landed path.
2. **Drain `MinimalTestKernel`** in `tests/Integration/Phase17/KernelBootValidationTest.php` to the real-kernel pattern (anonymous subclass + real `projectRoot` + `config/entity-types.php`), so the architectural guard in artefact 4 has nothing to allowlist.
3. **Deliberate-mutation regression guard** — a targeted unit test for `SqlSchemaHandler`'s registry-fallback branch (`shouldProcessBundles()` + `registeredBundlesFor()`), plus a docblock mutation recipe on the primary materialization test.
4. **Architectural test** rejecting new `extends AbstractKernel | HttpKernel | ConsoleKernel` under `tests/**`. Anonymous classes (the real-kernel pattern) are excluded by design.

The primary materialization test boots via anonymous subclass of `AbstractKernel` that exposes `publicBoot()`, constructs a real on-disk `projectRoot` under `sys_get_temp_dir()` with written `config/waaseyaa.php` + `config/entity-types.php`, registers a test bundle field through `EntityTypeManager::addBundleFields()`, triggers storage resolution, and asserts `sqlite_master` contains `{base}__{bundle}` with the declared columns. A companion test asserts that registering no bundles creates no subtables.

**Why first:** every correctness claim downstream rides on this harness. Without the guards in artefacts 2–4, the five-release bootstrap-variant failure mode can re-enter the suite undetected.

**Code smells called out:**
- **Bootstrap-variant proliferation** was the root smell — parallel "kernel-ish" helpers (mocked kernel, partial boot, in-memory manifest stubs) masked the alpha.148–152 chain. Artefact 4 makes re-introduction a hard error; artefact 2 drains the one pre-existing variant so the guard starts with no allowlist.
- **Registry-fallback branch is implicit.** `SqlSchemaHandler::shouldProcessBundles()` + `registeredBundlesFor()` falls back to `FieldDefinitionRegistry::bundleNamesFor()` only when `bundleEnumerator` is null — the exact shape alpha.148 got wrong. Artefact 3 pins it.

**Exit criteria (merge gates):**
1. `packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php` runs green.
2. Deliberately swapping `bundleNamesFor()` for an always-empty fallback (per the docblock recipe added in artefact 3) makes the primary assertion fail with `kernel_test_widget__gizmo must be materialized` — proves the assertion is load-bearing.
3. `tests/Integration/Phase17/KernelBootValidationTest.php` runs green after the `MinimalTestKernel` drain (artefact 2).
4. The architectural test (artefact 4) runs green with zero allowlisted entries.

**Standalone PR?** No — four PRs, in the order above.

---

## Phase 2 — framework#1315 part B: packaged-form CI job

**Repo:** waaseyaa (workflow + skeleton fixture).
**Scope:** GitHub Actions job that:
1. Creates a minimal consumer skeleton in `tests/PackagedForm/skeleton/` (`composer.json` requiring `waaseyaa/core:^ALPHA`, `config/waaseyaa.php`, one stub service provider registering one bundle field).
2. `composer install` against Packagist, boots kernel, runs migrations, executes one save/read.
3. Fails if subtable isn't materialized or round-trip fails.

**Why distinct from Phase 1:** Phase 1 uses path repos (monorepo-resolved). Phase 2 catches gaps appearing only after Composer-resolved published artifacts — the alpha.148–151 failure mode exactly.

**Code smell called out:** if Phase 2's skeleton mirrors Phase 1's fixtures, that's drift risk; keep it minimal and Packagist-only.

**Exit criterion:**
1. CI job green against most recent published alpha.
2. Deliberate-mutation test (publish a broken alpha to a scratch tag, confirm fail).

**Standalone PR?** Yes, but **first green run is release-gated.** PR merge → release alpha → CI proves green. Document the release-and-verify step in the PR description.

---

## Phase 3 — framework#1313: shadow-collision guard + duplicate-registration DX

**Repo:** waaseyaa.
**Scope:**

- **A. Shadow-collision guard.** `addBundleFields()` against a bundle whose `{base}__{bundle}` subtable doesn't exist → `LoggerInterface::notice()`. CI against a real DB surfaces missing-migration.
- **B. Duplicate-registration DX.** Registering an already-registered entity type id → exception naming both registrants (canonical provider + consumer provider) with a shadow-removal hint:

  > "Entity type 'group' is already registered by `Waaseyaa\Groups\GroupsServiceProvider`. Your registration in `<ConsumerServiceProvider>` is duplicate. If you were shadowing the canonical type, drop this registration."

### Clean vs. quick

- **Clean:** record the registrant's provider class at registration time. `EntityTypeManager::addEntityType()` accepts optional `registeredBy`, populated automatically by `ServiceProvider::entityType()` via `static::class`. No behavior change for existing callers.
- **Quick:** hardcode current registrant via `debug_backtrace()`. **Cost:** backtrace-driven error messages are fragile to refactor and couple error semantics to call-stack shape.

**Code smells called out:**
- Silent overwrite on duplicate registration (root smell this phase fixes).
- No provenance tracking on registered types tempts backtrace hacks; don't.

**Exit criteria:**

*Merge gates (required before PR merge):*
1. Unit test: duplicate registration throws; message contains both provider class names verbatim.
2. Integration test **using Phase 1's kernel harness**: missing-subtable `addBundleFields()` emits the expected `notice()` log.

*Post-release verification (release-gated; mirrors Phase 2):*
3. Merge PR → cut alpha → Phase 2's packaged-form CI runs against the new alpha → confirm green. **Not a merge blocker** — treating it as one would be circular: Phase 2 CI cannot exercise these changes until an alpha containing them is published.

**Standalone PR?** Yes. Exits 1–2 gate the merge; exit 3 is post-release verification on the same release-gated track as Phase 2's first green run (see Ordering flags).

---

## Phase 4 — minoo#741 part A: framework-side reconciliation code

**Repo:** waaseyaa (only if Phase 0 decisions require it).
**Conditional:**
- Decision 1 picked **domain adapter**: no framework change. Skip.
- Decision 2 picked **migrate Minoo columns**: no framework change. Skip.
- If either decision unexpectedly picked the speculative-generality framework-side path: land the minimal framework change here, validated by Phase 1 harness.

**Expected outcome:** empty phase given the recommended decisions.
**Exit criterion:** decision-specific; reference the Phase 0 ADR.
**Standalone PR?** Yes (likely skipped; retained for legibility).

---

## Phase 5 — minoo#741 part B: reconcile Minoo data + call sites

**Repo:** minoo.
**Scope:**

1. **Migration:** rename `group_type.type`→`id`, `group_type.name`→`label`. Update seed fixtures. Add rollback.
2. **HasCommunityInterface removal (Decision 1 = domain adapter):**
   - Introduce `App\Domain\GroupCommunity` service with `hasCommunity(Group $g): bool` and `communityFor(Group $g): ?Community`.
   - Service internally reads the `community` bundle-field (registered on the Minoo group bundles that need it via `addBundleFields()`). Field name, resolution, null handling live **only** inside the service.
   - Migrate every `instanceof HasCommunityInterface` callsite to the service: `$groupCommunity->hasCommunity($g)` for predicates, `$groupCommunity->communityFor($g)` for retrieval.
   - Delete `App\Entity\HasCommunityInterface` and `HasCommunityTrait`.
3. Tests that existed only to verify interface/trait mechanics (e.g., `GroupHasCommunityTest`) rewrite to assert `GroupCommunity` service behavior or delete if obsolete.

**Code smells called out:**
- Scattered `instanceof` checks for domain capabilities — the smell the adapter removes.
- Any callsite that guards against `null` community *after* a positive `instanceof HasCommunityInterface` is a "type says safe, runtime says otherwise" smell — fix via the service.
- Stringly-typed `$group->get('community')` access leaking outside `GroupCommunity` would reintroduce the failure mode the adapter prevents. The post-migration grep assertion (below) guards this.

**Exit criterion:**
1. Migration applies cleanly on fresh DB and prod-shaped DB; rollback clean.
2. `grep -r 'HasCommunityInterface\|HasCommunityTrait' src/ tests/` returns zero matches.
3. `grep -rn "get('community')" src/ tests/` returns matches bounded to `App\Domain\GroupCommunity` only.
4. Minoo's pre-push hook suite (composer-policy, phpstan, phpunit unit+integration) green.

**Standalone PR?** Yes.

---

## Phase 6 — minoo#741 part C: migrate the 11 test files

**Repo:** minoo.
**Scope:** rewrite imports from `App\Entity\Group` / `App\Entity\GroupType` to `Waaseyaa\Groups\Group` / `Waaseyaa\Groups\GroupType`:
- Integration (3): `GroupsBaselineTest`, `GroupBundleRoutingTest`, `SeedContentTest`
- Unit (8): `GroupAccessPolicyTest`, `BusinessControllerTest`, `CopyrightFieldTest`, `GroupHasCommunityTest` (if it still exists after Phase 5), `GroupTest`, `GroupTypeTest`, `FeedItemFactoryTest`, `FeedAssemblerTest`

Any test constructing `new App\Entity\Group([...])` with shadow entity-key names (`type`, `name`) adapts to framework keys (`id`, `label`). Phase 5's migration must land first.

**Code smell called out:** if multiple tests instantiate Group via raw array values, that's a smell. Centralize into a `GroupFactory` only if ~3+ tests benefit; otherwise direct rewrite.

**Exit criterion:**
1. All 11 files compile and run green.
2. No `App\Entity\Group` / `App\Entity\GroupType` imports remain under `tests/`.

**Standalone PR?** Yes.

---

## Phase 7 — minoo#741 part D: delete the shadow classes

**Repo:** minoo.
**Scope:** delete `src/Entity/Group.php` and `src/Entity/GroupType.php`. Remove leftover `AppServiceProvider` registrations. #1313's duplicate-registration error now names the failing file exactly if anything tries to re-register.

**Exit criterion:**
1. `find src/ -name 'Group.php' -o -name 'GroupType.php'` returns empty.
2. Full pre-push hook suite green.
3. Manual smoke: boot minoo locally, load a `business` group page, confirm rendering.

**Standalone PR?** Yes — separate from Phase 6 so the delete is atomic and cleanly revertable.

---

## Cross-cutting code smells called out

1. **Shadow-by-subclass** (root condition this arc dismantles). Lesson generalizes: bundle-fields or domain service, never shadow subclass.
2. **Capability-via-class** (`instanceof DomainInterface` on framework-owned entities). Unfixable at the class level once the canonical class is upstream.
3. **Stringly-typed field access as a replacement for capability-via-class** — trades one fragile coupling for another. Prefer an intent-revealing domain service at the access boundary.
4. **Bootstrap-variant proliferation** in the test suite (root cause of the five-release chain). Phase 1 drains the one pre-existing variant (`MinimalTestKernel` in `tests/Integration/Phase17/KernelBootValidationTest.php`) and installs an architectural assertion that rejects new `extends AbstractKernel | HttpKernel | ConsoleKernel` under `tests/**`. The arch-test starts with an empty allowlist; any future "just one more variant" lands a red test.
5. **Backtrace-driven error messages.** Tempting in Phase 3's quick path; rejected.
6. **Two-sources-of-truth on key naming.** Fixed in Phase 5.
7. **Silent overwrite on duplicate registration.** Fixed in Phase 3.

---

## Ordering flags

- **No conflicts with the fixed #1315 → #1313 → minoo#741 sequence.**
- **Phase 0 runs in parallel with Phases 1–3.** Decisions depend only on ADR + Minoo grep, not on harness/guard.
- **Release-gated verification** (Phase 2 first green run; Phase 3 exit criterion 3): both require a published alpha, not a PR merge. Phase 2's PR is mergeable on scaffolding alone; verifying it requires cutting an alpha. Phase 3's PR merges on exits 1–2 (merge gates); exit 3 confirms post-merge, post-release on Phase 2's harness. These two are the only release-gated items in the arc.
- **Phase 4 likely empty** given recommended decisions. Retained for legibility.
- **Phases 5/6/7 are three Minoo PRs in strict order.** Split keeps data migration, test migration, and file-deletion independently reviewable and revertable.

---

## Phase → Issue mapping

| Phase | Repo | Issue | PR count |
|---|---|---|---|
| 0 | (neither) | — (prerequisite) | 0 (workspace file) |
| 1 | waaseyaa | framework#1315 | 4 (spec amendment, MinimalTestKernel drain, deliberate-mutation guard, arch-test) |
| 2 | waaseyaa | framework#1315 | 1 (release-gated verification) |
| 3 | waaseyaa | framework#1313 | 1 |
| 4 | waaseyaa | minoo#741 prereq | 0 (expected empty) |
| 5 | minoo | minoo#741 | 1 |
| 6 | minoo | minoo#741 | 1 |
| 7 | minoo | minoo#741 | 1 |

**Total expected PRs:** 6 (7 if Phase 4 turns out non-empty).
