# #2985 audit lane — access-policy enforcement and community awareness

**Anchor:** #2985 · **Pinned commit:** `1d4dcec49e0e697f73d95687d80c06876d8dfcc6`
**Branch / worktree:** `audit/2985-access-policy-awareness` — `fw-2985-access-policy-audit`
**Mode:** read-only. No runtime file modified; no repair implemented.

## Method and evidence standard

Four research lanes traced the surface; every load-bearing claim was re-verified
against source by the lane owner. CONFIRMED = read in the code; HYPOTHESIS =
inference. **All findings are CODE EVIDENCE — nothing was executed.**

This lane was commissioned by the tenancy lane, which ended at an unexamined
surface: every claim that "the access policy would catch it" was unsupported.
The answer is definitive and is A-2 below.

### Coverage matrix

| Area | Reviewed | Depth |
|---|---|---|
| All 24 `#[PolicyAttribute]` classes | Yes | Each read in full |
| Gate mechanics: discovery, composition, call sites | Yes | Full read |
| Field-level access + `FieldReadLevel` guard | Yes | Full read |
| Docs, ADRs, issue history | Yes | Contract quotes + scoped search |
| `ProtectedEntityReadPolicyInterface` / `ProtectedFieldReadPolicyInterface` | **Partial** | Identified as a second surface; bodies not audited — see Residual |
| Executed proof | **No** | Out of scope |

## A-1 — CONFIRMED: plain repository reads apply no access check at all

`EntityRepository::find()` (`:442-496`), `findMany()` (`:505-540`) and
`findBy()` (`:551-561`) contain **no** reference to `accessHandler`, `check()`, or
`isAllowed()`. `find()` is `readDriverRow()` → `hydrate()` → return. They return
every matching row unconditionally.

Enforcement exists on exactly one storage path: `EntityRepository::getQuery()`
binds a handler (`:596-598`) and `SqlEntityQuery::execute()` throws
`MissingQueryAccountException` before touching the database when no account is
bound (`accessCheckEnabled` defaults to `true`, "fail-closed per FR-005 / C-006"),
then filters per row via `check($entity, 'view', $account)->isAllowed()`, dropping
Neutral rows.

Every other surface enforces by **hand-written per-call-site invocation**:
JSON:API's `index()`/`show()`/`store()`/`update()`/`destroy()`, GraphQL's
`EntityResolver` via `GraphQlAccessGuard`, and `EntitySearchCandidateResolver`.
All four production surfaces reviewed do call it — and `JsonApiRouter` constructs
the handler non-nullable with a non-nullable principal, so the guarded branches
always fire over real HTTP. But there is no structural guarantee that a *new*
call site, or any direct use of `find()`, would.

This is the correct statement of the framework's posture: **deny-by-default holds
for the query path and for surfaces that remember to check; there is no automatic
backstop on plain entity reads.**

## A-2 — CONFIRMED: the access layer was never specified as a tenancy backstop

`docs/specs/access-control.md`'s "Enforcement Layers" table has four rows — route,
entity handler, entity query, field — and **no tenant or community row**. Row
scoping is delegated to storage, in `entity-system.md` §"Community Scoping
(Multi-tenancy)". Closed **#1094** designed it that way from inception, and closed
**#2320** states it plainly: community tenancy "is enforced only by the
base-table storage driver".

The structural reason is decisive. `AccessPolicyInterface::access()` receives an
`AccountInterface`, whose surface is `id()`, `hasPermission()`, `getRoles()`,
`isAuthenticated()`. **Tenancy is not on the decision surface.** A policy cannot
check community without reaching outside its own contract.

So the tenancy lane's finding sharpens: it is not that a backstop is missing by
oversight — **no backstop was ever intended, and the interface is shaped so one
cannot be added without changing it.** A caller holding a valid permission but the
wrong tenant passes every access check by construction.

## A-3 — CONFIRMED: 24 policies, none community-aware, and one that reads as if it is

All 24 `#[PolicyAttribute]` classes were read in full. **None** references
`community_id`, `CommunityScope`, or any per-row scope field.

The only "tenant" strings in any policy are `platform.admin`, `tenant.admin` and
`tenant.member` in `NoteAccessPolicy` — **RBAC role names** checked via
`getRoles()`, not a scoped id compared against the row. Any account holding
`tenant.member` sees every note regardless of which tenant it belongs to. A policy
that reads as tenant-aware while providing no data isolation is worth naming
explicitly, because it is the shape a reader would most plausibly mistake for
enforcement.

Nine policies do compare an owner field to the caller — `authorId`, `uid`,
`owner_uid`, `account_id`, `blocker_id`, `user_id`, plus `MessagingAccessPolicy`'s
participation-row lookup. The per-row comparison pattern a community-aware policy
would need already exists; only the dimension is absent. *(reported.)*

## A-4 — CONFIRMED: Neutral polarity is three-way, not two-way

| Mechanism | Gate used | Neutral means |
|---|---|---|
| Entity `EntityAccessHandler::check()` | `isAllowed()` | **deny** |
| Field `FieldAccessPolicyInterface` via `checkFieldAccess()` | `!isForbidden()` (`:145`, `:191`, `:212`) | **allow** |
| Protected field read via `FieldReadGuard` | `isAllowed()` (`:83`, `:102`) | **deny** |

The entity-vs-field asymmetry is documented in both interfaces' docblocks. The
third is not: **two field-level mechanisms coexist with opposite polarity**, and
neither `FieldAccessPolicyInterface`, `EntityValueReadGuardInterface`, nor
`FieldReadGuard` cross-references the other. A reader of
`FieldAccessPolicyInterface`'s "additive, open-by-default" contract could
reasonably conclude that all field checks are open-by-default. They are not.

## A-5 — CONFIRMED: deny-by-default is a caller convention, not a structural guarantee

`EntityAccessHandler::check()` seeds `AccessResult::neutral('No policy provided an
opinion.')` and `orIf()`s each applicable policy, returning immediately on the
first Forbidden. Whether Neutral denies is decided entirely by which accessor the
caller uses. `AccessResult` exposes both `isAllowed()` and `isForbidden()`, and
nothing prevents a caller writing `!isForbidden()` for an entity-level check.

Every production entity-level call site examined uses `isAllowed()`, so the
guarantee currently holds — but it is discipline, not type-enforced. A new call
site using the field-level idiom on an entity check would silently flip an
unpoliced type from deny-by-default to open-by-default.

Discovery is keyed by **class**, not entity type, so two policies claiming the
same type both run and are combined by `orIf()` — no collision handling, and
a single Forbidden is decisive.

## A-6 — CONFIRMED: registered and unregistered entity types default fields oppositely

`EntityReadRuntime.php:174-177` and `:218`:

```php
$registeredEntityType ? FieldReadLevel::Internal : FieldReadLevel::Public
```

A registered type's **undeclared** fields default to `Internal` — which throws
`FieldReadDenied` unconditionally, with no policy consulted. An unregistered or
fixture class's undeclared fields default to `Public` — returned with no guard at
all.

Combined with A-4's third polarity: a `Protected` field with no registered
protected-read policy also **throws**, because `checkProtectedFieldRead()`
aggregates to Neutral and the guard tests `isAllowed()`. That is fail-closed and
correct, but it means "no policy" produces an exception rather than a denial —
relevant to open **#2159**, where a scaffolded `status` field lacking
`authorizationInput` makes anonymous published reads return nothing.

## A-7 — CONFIRMED (favourable): the listing fast path is unreachable in production

This resolves a residual the cache lane flagged — that a policy misdeclaring
`SUPPORTS_LISTING_FAST_PATH` would drop `user.id`/`user.roles` from the listing
cache key and leak across users.

Two independent reasons it cannot currently happen. The production `GateInterface`
binding is `EntityAccessGate`, which is `final class EntityAccessGate implements
GateInterface` — it does **not** implement `ListingFastPathProbeInterface`, so
`ListingResolver::canUseAccessFastPath()`'s `instanceof` check is false for every
request through real kernel wiring. And **zero** production policies declare the
constant.

The correctness obligation, for the record, is strict: an opting-in policy's
`view` decision must be identical for every possible account — no owner, role,
clearance, or account-dependent branching whatsoever. *(reported; the `instanceof`
gap was verified directly.)*

## A-8 — CONFIRMED: the listing pipeline swallows field-read denials into `null`

`ListingResolver::readField()` (`:732-742`) wraps `$row->get($field)` in
`catch (Throwable) { return null; }` — a blanket catch, not a targeted catch of
`FieldReadDenied`/`MissingFieldReadContext`.

Listing never consults `FieldAccessPolicyInterface`, `checkFieldAccess()`, or
`filterFields()` at all; it inherits the `FieldReadLevel` guard only incidentally,
through that catch-all. The consequence is semantic rather than a disclosure: a
denied or context-missing read becomes indistinguishable from a genuine `null`, so
an `IS_NULL` filter can spuriously match a row whose value was merely unreadable,
and a `NEQ` filter likewise. The value is not leaked; the *filter result* is
wrong. *(reported.)*

## A-9 — CONFIRMED: three parallel or vestigial policy surfaces

1. **The `Gate` class is effectively dead in production.** Its own
   `#[PolicyAttribute]` indexing and `{Type}Policy` naming fallback are reachable
   only in tests: the sole production construction is `new Gate([])` in
   `packages/listing/src/ServiceProvider.php:379`, deliberately empty as a
   fail-closed fallback. The live `GateInterface` is `EntityAccessGate`, which
   delegates entirely to `EntityAccessHandler`.
2. **`Waaseyaa\Access\Attribute\AccessPolicy` has zero production users.** It is
   read by `EntityAccessHandler::resolveBundles()` for bundle filtering, but no
   production class carries it; every real policy uses
   `Waaseyaa\Access\Gate\PolicyAttribute`. Two attributes, one live.
3. **`ProtectedEntityReadPolicyInterface` / `ProtectedFieldReadPolicyInterface`
   are a second policy surface.** Several policies return Neutral unconditionally
   from their classic `access()`/`fieldAccess()` and put their real logic there —
   `CapabilityScopedStaffDirectoryAccessPolicy`, `GenealogyContentAccessPolicy`'s
   field redaction, `UserBlockAccessPolicy`. **Any claim about "what the policies
   enforce" is incomplete without auditing it**, and this lane did not.

## A-10 — CONFIRMED: two stale docblocks on live code

- `FieldReadLevel.php:11` — "WP1 is metadata-only: no entity accessor consults
  this enum yet." It is consulted, by `EntityValueContainer` and `FieldReadGuard`.
- `SqlEntityQuery.php:257` — "the production binding is performed by
  `SqlEntityStorage::getQuery()` (WP03)". `SqlEntityStorage` was deleted in C-22;
  the binding moved to `EntityRepository::getQuery()`.

Both are documentation defects on security-relevant code, where a stale "not yet
active" note is the more dangerous direction.

## Corrected and disproved leads

- **The lane owner's interim claim that "entity reads are deny-by-default at two
  layers" was too broad.** It holds for `getQuery()` and for `EntityAccessHandler`
  when called; it does **not** hold for `find()`/`findMany()`/`findBy()`, which
  apply no check at all (A-1). The corrected statement is in A-1.
- **The cache lane's justification for `ProtectedCacheDimensions` was stale.** That
  lane recorded it as "documented WP4 scaffolding — hard rejection remains WP4",
  citing `entity-field-read-boundary.md:274`. That line sits inside a WP2-tranche
  section; the file's top-of-file status is "**WP4 activated**". The *finding* —
  zero production constructions of `ProtectedCacheDimensions`, re-verified here —
  stands, and is modestly stronger for it: the class is unwired after the phase it
  was scheduled for has shipped, not before.
- **The listing fast-path risk is currently unreachable** (A-7), which retires a
  cache-lane residual rather than confirming it.

## Existing issue mapping

| Issue | State | Relation |
|---|---|---|
| **#2815** | open, p1, beta-blocker | A-2 is this lane's deliverable for it: there is no secondary enforcement to lean on, by design. Assigned to `entity-database-search-cache`, **not** identity-access-workflows — confirmed against `issue-coverage.md`. |
| **#2159** | open, p0, beta-blocker | Anonymous published reads returning nothing when a scaffolded `status` field lacks `authorizationInput` — the A-6 fail-closed posture biting a legitimate case. |
| **#2516** | closed | `ContentPublisher` read operations were capability-only and bypassed every entity access policy — precedent that capability enforcement is not entity-policy enforcement, and a prior instance of the A-1 shape. |
| **#1714**, **#1702** | closed | Established the Layer-3 deny-by-default contract (audit C-6/C-7) that `SqlEntityQuery` now implements. |
| **#2064** | closed | Anchor for the field-read boundary; its WP4 status is what corrects the cache lane's citation. |
| **ADR-019** | accepted | MCP tools must enforce access policies — permission enforcement, not row scoping. |
| **#2985** | open | Anchor. |

**No new issue is filed.** A-1 and A-5 are architectural characterisations rather
than defects; A-8 and A-10 are small enough for a maintenance sweep; A-2 is
evidence for #2815.

## Compatibility constraints on any repair

- Adding tenancy to `AccessPolicyInterface`'s decision surface changes a public
  interface implemented by 24 first-party classes and any downstream policy —
  a charter-level surface change, not a local fix.
- The three-way polarity (A-4) is load-bearing: aligning them would flip either
  field access closed or protected reads open. Neither is a safe default change.
- `find()`/`findMany()`/`findBy()` returning unfiltered rows is depended upon by
  internal callers that must read entities the acting principal cannot see
  (bootstrap, migration, audit readers). Gating them wholesale would break those.

## Focused acceptance criteria

1. If community enforcement is ever expected from the access layer, `AccountInterface`
   or the policy contract must carry the dimension — otherwise the storage layer
   remains the only possible enforcement point, and #2815's design should say so.
2. The two field-level mechanisms' opposite polarities are cross-referenced in both
   docblocks (A-4).
3. `ListingResolver::readField()` catches the field-read exceptions specifically,
   so a denied read is distinguishable from a genuine null (A-8).
4. The stale docblocks in A-10 are corrected.
5. Any future opt-in to `SUPPORTS_LISTING_FAST_PATH` requires the production gate
   to implement the probe *and* a test proving the policy's decision is
   account-independent (A-7).

## Residual work not covered

- `ProtectedEntityReadPolicyInterface` / `ProtectedFieldReadPolicyInterface`
  bodies — a second enforcement surface where several policies' real logic lives.
  This is the largest single gap in this lane's coverage.
- Non-`JsonApiRouter` constructions of `JsonApiController` (e.g.
  `GenericAdminSurfaceHost`) that could pass a null handler or account and reach
  A-1's unguarded branches.
- CLI has no `AccountFieldReadScope` binding anywhere in `packages/cli/src`, though
  the guard is installed for CLI boots. No CLI path reading a Protected/Internal
  field was found, so the gap is latent — bounded to the files grepped.
- No executed proof for any finding.

## Recommended next disjoint lane

**The protected-read policy surface** (`ProtectedEntityReadPolicyInterface` /
`ProtectedFieldReadPolicyInterface`). A-9 established that several policies are
no-ops at the classic surface and put their real enforcement there, so the
inventory in A-3 is knowingly incomplete until it is audited. It is the direct
successor to this lane and disjoint from all twelve to date.
