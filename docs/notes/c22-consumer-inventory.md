<!-- no-changelog: read-only investigation note; inventory only, no behavior change -->
# C-22 Consumer Inventory — Removing the Legacy `SqlEntityStorage` Save Engine

**Status:** investigation only (no code changed). Snapshot date: 2026-06-30.
**Reproduce any count below** with the `rg` commands noted inline — do not treat
the numbers as durable; re-run against source.

> **Update (2026-06-30):** the prerequisite identified in the bottom line —
> giving `EntityRepository` an access-checked query surface — has since landed
> (additive). `EntityRepositoryInterface::getQuery()` now returns the same
> access-checked `SqlEntityQuery` as `SqlEntityStorage::getQuery()`, wired with
> the same kernel access handler and proven at parity
> (`tests/Integration/PhaseN/EntityQueryAccessCheck/RepositoryStorageQueryParityTest.php`).
> The consumer migration (§1/§2) and the engine deletion remain future work; both
> engines still run. The inventory below stands as the migration map.

## Background

`entity-storage` exposes two parallel write/read engines:

| Engine | Wired via | Role |
|---|---|---|
| `SqlEntityStorage` (`packages/entity-storage/src/SqlEntityStorage.php`) | `EntityTypeManager::getStorage($id)` | **Legacy.** `load`/`loadMultiple`/`loadByKey`/`save`/`delete` **plus** the access-checked `getQuery(): EntityQueryInterface` (`SqlEntityQuery`). |
| `EntityRepository` (`packages/entity-storage/src/EntityRepository.php`, `@api`) | `EntityTypeManager::getRepository($id)` | **Canonical.** `find`/`findMany`/`findBy`/`save`/`saveMany`/`delete`/revisions/translations. **No `getQuery()`, no account-filtered read.** |

Both are wired **directly** by the kernel's `EntityTypeManagerFactory`
(`packages/foundation/src/Kernel/EntityTypeManagerFactory.php:70–140`) via two
inline closures — a storage factory that `new SqlEntityStorage(...)` and a
repository factory that builds `EntityRepository` over `SqlStorageDriver`.

## The load-bearing fact: access-checked query is legacy-engine-only

**`EntityRepository` has no access-checked read/query path.** Its interface
(`packages/entity/src/Repository/EntityRepositoryInterface.php`) exposes only:

```
find($id, $langcode, $fallback)      findBy($criteria, $orderBy, $limit)
findMany($ids, ...)                  count($criteria)   exists($id)
save/saveMany/delete/deleteMany      + revision/translation methods
```

None of `find*`/`count` take an `AccountInterface`, and the backing
`SqlStorageDriver::findBy()` (`packages/entity-storage/src/Driver/SqlStorageDriver.php:311`)
does raw criteria matching with **no** `EntityAccessHandler`. The only
account-context on the repository is `setAccountContext()` — and that is
**write-side revision authorship only**, not read filtering:

> `EntityRepository.php:181` — *"AccountInterface::id() is int|string; revision_author is an int uid."*

By contrast the access filter lives entirely in `SqlEntityQuery`
(`packages/entity-storage/src/SqlEntityQuery.php`):

> `:34–39` — *"Access checking is enabled by default … `EntityAccessHandler::check($entity, 'view', $account)` … Callers MUST bind an account via `setAccount()`"*
> `:714` — fail-closed: `if ($this->accessCheckEnabled && $this->account === null)` → throws `MissingQueryAccountException`.

`SqlEntityStorage::getQuery()` (`:1204`) is what threads the kernel's
`EntityAccessHandler` into that query (`:1219`).

**Conclusion: the access-checked list/query surface is genuinely legacy-only.**
Any consumer calling `getStorage()->getQuery()->setAccount()->execute()` cannot
be moved to `getRepository()` today — the repository has nothing equivalent.
This is the blocker.

---

## 1. Query surface (the blocker set)

Every production `->getQuery()` on a storage handle. Search:
`rg -n -- '->getQuery\(' packages/*/src`.

### 1a. Access-sensitive — rely on `setAccount()` account filtering

| File:line | Package (layer) | Notes |
|---|---|---|
| `packages/api/src/JsonApiController.php:78,94,178,623` | api (L4) | **Primary blocker.** Public REST list/count/collection. `setAccount($this->account)` when authenticated, `accessCheck(false)` only as system fallback. The framework's user-visible read path. |
| `packages/genealogy/src/Service/GenealogyFamilyService.php:28` | genealogy (dist-ext) | Conditional `setAccount($account)` / `accessCheck(false)`. |
| `packages/genealogy/src/Service/GenealogyPedigreeService.php:31,54,241` | genealogy (dist-ext) | Same conditional-account pattern. |

*(genealogy is a distribution-extension, not framework substrate — DIR-004.)*

### 1b. Internal/system — `accessCheck(false)`, only need the query builder

These bypass the access gate; they depend on `getQuery()` purely for
`condition()/execute()`, **not** for access filtering. A repository-side
criteria query (or a new unchecked query surface) would satisfy them.

| File:line | Package (layer) |
|---|---|
| `packages/seo/src/SitemapGenerator.php:100` | seo (L3) |
| `packages/seo/src/Llms/LlmsTxtGenerator.php:109` | seo (L3) |
| `packages/workflows/src/DomainValidationListener.php:133` | workflows (L3) |
| `packages/northcloud/src/Sync/NcSyncService.php:144` | northcloud (L3) |
| `packages/messaging/src/MessagingAccessPolicy.php:138` | messaging (L3) |
| `packages/relationship/src/RelationshipValidator.php:272` | relationship (L2) |
| `packages/relationship/src/RelationshipDeleteGuardListener.php:36,42` | relationship (L2) |
| `packages/oidc/src/ClientRegistry/OidcClientSeeder.php:123` | oidc (L1) |
| `packages/oidc/src/ClientRegistry/OidcClientLookup.php:28` | oidc (L1) |
| `packages/api/src/Controller/OidcClientController.php:205` | api (L4) |
| `packages/genealogy/src/Ssr/GenealogySsrController.php:162,171` | genealogy (dist-ext) — `accessCheck(false)` then conceals per-person via gate |
| `packages/cli/src/Handler/SearchReindexHandler.php:57` | cli (L6) |
| `packages/cli/src/Handler/EntityListHandler.php:26` | cli (L6) |
| `packages/field/src/Classification/Job/HoldScanJob.php`, `RedactJob.php`, `RetentionScanner.php`, `PurgeJob.php` | field (L1) |

**Query-surface tally:** ~20 callsites / ~11 packages. Only **JsonApiController**
(framework) and **genealogy** (dist-ext) truly depend on account-filtered
results; every other query is a system-context `accessCheck(false)` that needs
only a query *builder*, not the access filter.

---

## 2. Clean-migration set (read/write only → mechanical `getRepository()` swap)

`getStorage()->load/loadMultiple/loadByKey/save/delete` with **no** `getQuery()`.
Every method has a 1:1 repository equivalent (`load→find`,
`loadMultiple→findMany`, `loadByKey→findBy`, `save→save`, `delete→delete`).

**Read (load*):** `attachment` (`AttachmentDownloadRouter.php:54`,
`ParentDelegatedAccessPolicy.php:60`), `ai-vector`
(`SemanticIndexWarmer.php:84,237,281`, `SearchController.php:58,172`), `search`
(`EntitySearchAccessChecker.php:53`), `migration`
(`ContentModelRegistrar.php:103`), `engagement` (`EngagementAccessPolicy.php:103`),
`field` (`ClassificationLabelRegistry.php:43` + classification jobs),
`oidc` (`Userinfo/UserinfoController.php:79`, `OidcServiceProvider.php:164,289`),
`ssr` (`SsrPageHandler.php:139,765`, `AppControllerMethodInvoker.php:220`),
`graphql` (`Resolver/EntityResolver.php:57,172,214,232,240`,
`ReferenceLoader.php:97`, `Schema/SchemaFactory.php:228`), `routing`
(`EntityParamConverter.php:61`), `api` (`DiscoveryApiHandler.php:175`,
`TranslationController.php:182,250,304,382`, `FieldAutoSaveController.php:92`),
`foundation` (`HttpKernel.php:424` — loads the session user),
`genealogy` access policies + SSR controller loads.

**Write (save/delete):** `auth` (`TwoFactorService.php:77,111,121,139` +
`Controller/*` register/verify/reset), `field`
(`Job/RedactJob.php:130` save, `Job/PurgeJob.php:148` delete), `api`
(`JsonApiController.php` create/update/delete, `TranslationController.php`).

These packages span L1–L6 but the swap is mechanical. Note `JsonApiController`
and the classification jobs appear in **both** this set and the query set — they
must migrate reads+writes *and* keep a query surface, so they cannot fully leave
`getStorage()` until §1 is solved.

---

## 3. `EntityStorageFactory` situation

`packages/entity-storage/src/EntityStorageFactory.php` — the "second construction
site" — is **not wired in production**. Search
`rg -n 'EntityStorageFactory' --glob '!vendor/**'` finds it referenced only by:

- its own file,
- a `@see` docblock in `Backend/BackendRegistrar.php:27`,
- two test files (`EntityStorageFactoryAccessWiringTest`, `PipelineInvariantTest`).

The live kernel path (`EntityTypeManagerFactory`) constructs `SqlEntityStorage`
via an **inline closure**, not this factory. `EntityStorageFactory` also carries
scaffolding for the never-activated `EntityStorageCoordinator` multi-backend
fan-out (`getCoordinator()`, `@api`, "WP02+/WP04/WP10"). **It can be retired**
alongside `SqlEntityStorage` — the only cost is deleting the two unit/integration
tests that exercise it. (Confirm the coordinator scaffolding is not depended on
elsewhere before deleting; `BackendRegistrar` is `@internal` and only doc-linked.)

---

## 4. Test-side construction

Tests instantiate `SqlEntityStorage` directly (not via the manager) in ~15
integration suites (`tests/Integration/Phase{4,5,11,12,14,15,26}`,
`tests/Integration/{DBAL,GraphQL,EntityStorage,PhaseN/EntityQueryAccessCheck}`,
`packages/{genealogy,api,entity-storage}/tests`). Search
`rg -n 'new SqlEntityStorage' --glob '!vendor/**'`. These are fixtures, not
runtime consumers, but every one is a compile-time reference that must be
rewritten or deleted when the class goes. The `EntityQueryAccessCheck` phase
suite specifically tests the access-checked query semantics — that behavior must
be re-homed (see bottom line) before those tests can move.

---

## Bottom line

**Removing `SqlEntityStorage` is a LARGE migration, gated by one thing: the
access-checked query surface.**

- The **read/write consumers** (§2, the majority — dozens of callsites across
  L1–L6) are a **mechanical** `getStorage()→getRepository()` swap; `EntityRepository`
  already covers every load/save/delete method they use.
- The **single biggest obstacle** is that `getStorage()->getQuery()` — the
  account-filtered `SqlEntityQuery` with `EntityAccessHandler` — has **no
  equivalent on `EntityRepository`/`SqlStorageDriver`** (confirmed: the repository
  read path takes no account and runs no access handler). One framework consumer,
  **`packages/api/src/JsonApiController.php`**, depends on it for user-visible REST
  access filtering, plus genealogy (dist-ext). The ~13 other query callsites only
  use `accessCheck(false)` and need just a query *builder*.
- **`EntityStorageFactory` is already dead in production** and retires for free.

**Prerequisite before deletion:** give `EntityRepository` an access-checked query
surface (e.g. `EntityRepository::getQuery(): EntityQueryInterface` that threads
the same `EntityAccessHandler`, or a `findBy(..., account:)` overload). Once that
exists, JsonApiController + genealogy move over, the `accessCheck(false)` callers
follow trivially, the §2 read/write callers are a mechanical sweep, and
`SqlEntityStorage` + `EntityStorageFactory` + their fixtures can be deleted.
Without it, the engine cannot be removed.

---

## 5. WP1 update (2026-07-01): behavior-identity harness findings

`tests/Integration/PhaseN/EntityStorageEngineParity/` now pins four
cross-engine divergences discovered while building the harness (see
CHANGELOG `[Unreleased]` for the full writeup). Two are load-bearing for the
WPs below:

- **Timestamp/clock gap (risk for WP3).** `EntityRepository` has no clock and
  never auto-populates `created`/`changed`-shaped fields; `SqlEntityStorage`
  does, via its injected `EntityClockInterface`. **Before migrating any §2
  write consumer in WP3, check whether the entity type being written has a
  `created`/`changed`-shaped field that the consumer expects the storage
  layer to auto-populate** (rather than setting it itself). If so, either the
  consumer must set the timestamp explicitly before calling
  `getRepository()->save()`, or this gap needs a fix first — do not assume
  the WP3 sweep is purely mechanical for such consumers.
- **Event-model gap (risk for WP2/WP3, already known).** Confirmed exact
  mechanics: `EntityRepository` additionally fires `BeforeSaveEvent`/
  `AfterSaveEvent`; a `BeforeSaveEvent` subscriber may throw
  `AbortOperationException` to veto the save (no write occurs, no
  `AfterSaveEvent`). Per the WP2 risk check, grep for listeners on these two
  events and confirm no to-be-migrated write caller's correctness depends on
  them NOT firing.

Two are informational only (confirmed NOT to block current consumers):

- A `json`-typed core field colliding with a real base-table column is
  correctly encoded by `SqlEntityStorage` but silently mangled by
  `EntityRepository`/`SqlStorageDriver`. No first-party entity type has this
  collision today (real columns only exist for entity-key fields).
- The sql-column translatable layout has no `EntityRepository` equivalent.
  No first-party entity type uses sql-column for a translatable type.

---

## 6. WP3 update (2026-07-01): consumer migration complete

Every production `getStorage()`-based `load()`/`loadMultiple()`/`create()`/
`save()`/`delete()`/`loadByKey()` callsite from §1 and §2 above has been
migrated to the equivalent `getRepository()` method. Confirmation: the
dead-code gate (`bin/check-dead-code`) now reports `SqlEntityStorage::create`/
`load`/`loadByKey`/`loadMultiple`/`save`/`delete` and
`EntityStorageFactory::getStorage` as unused — baselined in
`phpstan-dead-code-baseline.neon` pending deletion in WP4. The WP1 harness
(`tests/Integration/PhaseN/EntityStorageEngineParity/`) stayed green
throughout.

Two forks surfaced mid-sweep and were resolved (both confirmed with the
requester before implementing, per the "stop and surface a fork" standing
rule):

1. **`create()` has no repository equivalent.** 8 production callsites called
   `getStorage()->create($values)` (field-default application + hydration, no
   I/O). Resolved by adding `EntityRepositoryInterface::create()` /
   `EntityRepository::create()`, backed by the SAME
   `EntityInstantiator::applyFieldDefinitionDefaults()` logic
   `SqlEntityStorage::create()` already used (extracted to the shared
   `EntityInstantiator` class rather than reimplemented) — no drift possible
   between the two engines' defaulting behavior. Parity test:
   `CreateFieldDefaultsParityTest.php` (folded into the WP1 harness).
2. **JSON:API no-expectation PATCH was on a documented revision-free
   contract.** `JsonApiController::update()`'s two save branches diverged:
   expectation-stated → `getRepository()->save(..., context: ...)`
   (revision-aware); no-expectation → `getStorage()->save()` (revision-less),
   per `kitty-specs/archive/optimistic-locking-01KTXCHY/contracts/conflict-surfaces.md`
   clause 14 (FR-003/SC-003). Resolved by unifying both branches onto
   `getRepository()->save()` — a no-expectation PATCH on a revisionable type
   now cuts a revision, simply skipping the `SaveContext` conflict-detection
   guard. Verified no other consumer depended on the revision-free behavior:
   the AI-tools `entity.update` surface already saved unconditionally through
   `getRepository()->save()` for both paths, so this change brings the REST
   and MCP-tool write surfaces into alignment rather than introducing a new
   asymmetry. The archived contract doc, `docs/specs/api-layer.md`, and
   `JsonApiControllerConflictTest` were updated to match.

`loadByKey()` (5 callsites: `ForgotPasswordController`, `RegisterController`,
`ClassificationLabelRegistry`, `GenericAdminSurfaceHost` ×3 via a new shared
`findByIdOrUuid()` helper) has no repository equivalent either, but needed no
design decision — each callsite became a bounded
`getQuery()->accessCheck(false)->condition($key, $value)->range(0, 1)->execute()`
+ `find()`, mirroring `SqlEntityStorage::loadByKey()`'s own internal
implementation exactly.

`SessionMiddleware`, `BearerAuthMiddleware`, `NoteIngester`,
`OidcClientSeeder`, and `OidcClientLookup` had constructors typed on
`EntityStorageInterface` (or both interfaces); all narrowed to a single
`EntityRepositoryInterface` parameter.

WP4 (delete `SqlEntityStorage` and `EntityStorageFactory` entirely) is now
unblocked: harness green, zero remaining production references confirmed by
the dead-code gate.

---

## 7. WP4 update (2026-07-01): legacy engine deleted

`packages/entity-storage/src/SqlEntityStorage.php` and
`EntityStorageFactory.php` are deleted. `EntityRepository` is the framework's
only persistence engine.

**Kept, not removed (verified before deleting anything):**

- `EntityStorageInterface` — `RevisionableStorageInterface extends` it as
  dormant public API (`docs/public-surface-map.php`), and several test
  doubles implement it directly (`StorageBackedStubRepository`,
  `InMemoryEntityStorage`, `relationship`'s `StubEntityStorage`).
- `SqlEntityQuery`, `BundleSubtableGateway`, `SqlStorageDriver`,
  `EntityInstantiator`, `EntityStorageCoordinator` — all genuinely shared;
  `EntityRepository` constructs and uses each independently, not merely by
  inheriting from `SqlEntityStorage`.
- `EntityTypeManager::getStorage()` / `EntityTypeManagerInterface::getStorage()`
  — kept as a dormant "bring your own `EntityStorageInterface`" extension
  seam. The kernel (`EntityTypeManagerFactory`) no longer supplies a
  `storageFactory` closure, and `EntityType::fromClass()`'s `$storageClass`
  default changed from `Waaseyaa\EntityStorage\SqlEntityStorage` to `''` —
  `getStorage()` now throws for every first-party entity type unless a
  caller wires their own storage class/factory. Not a supported path;
  scheduled-for-removal territory, kept only because removing the interface
  method would be a needless breaking change to a still-valid extension
  point.

**Test-suite consequences:**

- The WP1 behavior-identity harness (`tests/Integration/PhaseN/EntityStorageEngineParity/`)
  is deleted — its entire purpose was pinning cross-engine parity during the
  WP1–WP4 migration window; with one engine there is nothing left to compare.
- Bundle-subtable and query-routing coverage previously exercised through
  `SqlEntityStorage` was **ported**, not dropped — e.g.
  `packages/entity-storage/tests/Unit/EntityRepositoryBundleFieldsTest.php`
  replaces the deleted `SqlEntityStorageBundleFieldsTest.php`, swapping
  `$storage->create()/save()/load()/loadMultiple()` for
  `$repository->create()/save()/find()/findMany()` one-for-one. The final
  scope grew beyond `packages/entity-storage/tests/`: a repo-wide grep found
  ~55 test files total (top-level `tests/Integration/**` plus several other
  packages) that directly instantiated `SqlEntityStorage` for fixture
  seeding; all converted the same way. Several files were renamed for
  clarity once they no longer tested `SqlEntityStorage` at all (e.g.
  `SqlEntityStorageTest.php` → `EntityRepositorySqlCrudTest.php`,
  `SqlEntityStorageBundleQueryRoutingTest.php` →
  `EntityRepositoryBundleQueryRoutingTest.php`).
- One accepted coverage/behavior loss: `SqlEntityStorage`'s load-side
  `[MISSING_BUNDLE_SUBTABLE]` notice (its private `mergeBundleSubtableRow()`/
  `mergeBundleSubtableRowsBatch()`) had no `EntityRepository` equivalent —
  `EntityRepository`'s load path silently skips a missing bundle subtable
  with no operator-facing notice. The **save-side** notice
  (`BundleSubtableGateway::logMissingSubtableOnSave()`) is the more
  load-bearing one — it signals an actual data-placement decision (values
  folded into `_data` instead of the subtable) — and is preserved and still
  tested. The load-side notice was purely diagnostic (the entity still loads
  correctly either way) and is not replaced.
- **Two real bugs found and fixed while converting fixtures, not mechanical
  migration:**
  - `SqlStorageDriver::splitForWrite()` routed values to base-table columns
    purely by schema-column existence, with no awareness of a field's
    `FieldStorage::Data` hint. `SqlEntityStorage::splitForStorage()` used to
    consult the field registry to keep such fields in `_data` even when a
    stale legacy column lingered (the K2 write/read symmetry fix, issue
    #1308) — `SqlStorageDriver` never got this fix, so deleting
    `SqlEntityStorage` would have silently regressed it. Fixed by adding an
    optional `?FieldDefinitionRegistryInterface $fieldRegistry = null`
    constructor param to `SqlStorageDriver`, wired by the kernel's
    `EntityTypeManagerFactory`.
  - `EntityRepository::doSave()`'s new-entity id-backfill branch checked
    `(string) ($entity->id() ?? '')` to detect "no id yet." `User::id()`
    overrides the base accessor to satisfy `AccountInterface`'s "anonymous
    is `0`, never `null`" contract, so a brand-new `User` produced `id() ??
    '' === '0'`, never the empty string the backfill branch looked for —
    the branch silently never fired, and a freshly-saved `User` kept
    `uid = 0` in memory (colliding with the `AnonymousUser` sentinel) even
    though the database row got a real autoincrement id. This bug predates
    C-22 entirely; it surfaced only because this WP's fixture conversions
    routed many more entity-creation paths (including `User`) through
    `EntityRepository::save()` for the first time. Fixed by reading the id
    key from the entity's raw `toArray()` value bag instead of the
    polymorphic `id()` accessor.
- `docs/specs/entity-system.md` "The legacy save engine is gone (C-22)" §
  rewritten for the single-engine reality.
