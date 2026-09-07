# #2985 audit lane — tenancy enforcement (#2815)

**Anchor:** #2985 · **Scoped to:** open beta-blocker #2815 (p1, `area:security`, `area:entity`)
**Pinned commit:** `870c41c01058fb92cb6992cbbfd315f187f50b35`
**Branch / worktree:** `audit/2985-tenancy-enforcement` — `fw-2985-tenancy-audit`
**Mode:** read-only. No runtime file modified; no repair implemented.

## Method and evidence standard

Four research lanes traced the surface; every load-bearing claim was re-verified
against source by the lane owner. CONFIRMED = read in the code; HYPOTHESIS =
inference. **All findings are CODE EVIDENCE — nothing was executed**, and no
cross-community read was reproduced.

The audit's organising distinction, applied to every surface:

- **REPRESENTED** — a column, field, or method names a tenant or community.
- **ENFORCED** — code actually refuses, filters, or denies based on it.

### Coverage matrix

| Area | Reviewed | Depth |
|---|---|---|
| Identity: principal implementations, middleware, fail-closed posture | Yes | Full read |
| Storage: `EntityType` tenancy, schema, all three drivers, repository | Yes | Full read + throw-site census |
| `SqlEntityQuery` / `getQuery()` | Yes | Constructor + full `community_id` census |
| Derived: caches, search, queue, scheduler, audit, broadcast, embeddings | Yes | Read + grep census per package |
| Docs, ADRs, issue history | Yes | Contract quotes + scoped issue search |
| Executed proof of any kind | **No** | Out of scope |
| Every `AccessPolicy` implementation | **No** | See Residual |

## The documented contract

The framework states community scoping as a **security boundary**, not a
convention:

> `docs/specs/entity-system.md:93` — "tenancy is treated as a security boundary"
> `docs/specs/entity-system.md:1458` — "**All entity queries**, revision
> histories, and revision mutations are automatically restricted to the active
> community when a `CommunityContext` is set."

The founding issue **#1094** is explicit that community *is* the tenant: "each
Indigenous community is a fully isolated tenant. Community A must never be able
to query, search, or retrieve Community B's data — not just at the UI level but
at the query level."

This matters for how the findings below should be read: they are gaps against a
stated security intent, not a category error about what community means.

## T-1 — CONFIRMED (serious): the primary query API applies no community isolation

`EntityRepository::getQuery()` constructs `SqlEntityQuery` directly.
`SqlEntityQuery::__construct()` (`packages/entity-storage/src/SqlEntityQuery.php:159-164`)
takes **no** `CommunityScope` parameter — entity type, database, result cache,
field registry, field read scope — and builds SQL straight against
`DatabaseInterface`, bypassing the storage drivers.

The **only** occurrence of `communityId` in the entire file is `:1143`, inside
`accountCacheDimension()` — a **cache-key** dimension, never a `WHERE` condition.

So the same repository exposes two read surfaces with opposite properties:
`find()`, `findMany()` and `findBy()` forward to the driver and are filtered;
`getQuery()` — the query-builder surface used for listing and filtering — is not.

The community dimension is folded into the query's **cache key** but not its
**filter**, so results are cached per-community while the query spans communities.

This contradicts `entity-system.md:1458`'s "all entity queries" guarantee, on a
documented security boundary.

### T-1.1 — the access layer is not a backstop, and was never meant to be

Added after the access-policy lane (`docs/audits/2985-access-policy-audit.md`,
`0af4c2431`) audited the surface this lane deferred.

`access-control.md`'s "Enforcement Layers" table has four rows — route, entity
handler, entity query, field — and **no tenant or community row**. All 24
registered `#[PolicyAttribute]` classes were read in full and **none** is
community-aware. The structural reason is decisive: `AccessPolicyInterface::access()`
receives an `AccountInterface` exposing only `id()`, `hasPermission()`,
`getRoles()`, `isAuthenticated()` — **tenancy is not on the decision surface**, so
a policy cannot check it without changing the contract.

So the accurate statement is stronger than "no default policy defends this": no
backstop was ever specified, closed #1094 designed tenancy as a storage concern
from inception, and closed #2320 restates it — community tenancy "is enforced
only by the base-table storage driver".

**Refinement of the exposure shape.** Entity reads are *not* uniformly open: the
`getQuery()` path throws `MissingQueryAccountException` before touching the
database when no account is bound, and drops Neutral rows per row, so an
unpoliced type is closed on that path. But `find()`, `findMany()` and `findBy()`
apply **no access check at all**. The risk is therefore not "no policy ⇒ open";
it is:

> a policy grants access broadly — published nodes are viewable, `administer
> content` may edit — and community is absent from that grant; or a caller uses a
> plain repository read, which is ungated regardless.

## T-2 — CONFIRMED (serious): queued jobs and scheduled tasks run unscoped

`QueueEnvelopeV1` carries `?string $communityId` end to end (`:25`, `:45`,
`:56`, `:67`) — **represented**. Nothing reads it back: a census of
`packages/queue/src` and `packages/scheduler/src` for `CommunityContext` or
`context->set(` returns **nothing**. The framework never restores community scope
from the envelope it carries.

`packages/scheduler/src` is worse — **zero** files match "communit" or "tenant"
at all. Scheduled tasks have no community awareness in either direction.

Because `CommunityScope::isActive()` is false outside an HTTP request that passed
through `CommunityMiddleware`, every driver-level `condition('community_id', …)`
is skipped. A job enqueued from a community-scoped request — reindex this
content, send this notification, repair this record — executes against **all**
communities' rows.

This is the cleanest illustration of the audit's distinction: the community is
represented in the envelope, and enforced nowhere along the path that consumes it.

## T-3 — CONFIRMED: fail-closed at boot, fail-open per request

Two mechanisms exist and must not be conflated.

**Boot — fail-closed.** `AbstractKernel:413-421` throws `[TENANCY_MISCONFIGURED]`
when an entity type declares community tenancy but **no `CommunityContext` is
bound at all**, outside development mode, with the rationale stated in the code:
this "would silently disable community isolation on every read — a data-leak
posture, not a tolerable misconfiguration." Two documented escapes: development
mode only warns, and `$allowUnscopedMutationAuthorityRepair` returns null before
the guard (`:402-404`).

**Request — fails open.** That guarantee covers *wiring*, not *activation*. Every
driver site uses the same shape:

```php
if ($this->communityScope?->isActive()) {
    $query = $query->condition('community_id', …);
}
```

Wired-but-inactive ⇒ no condition ⇒ the operation spans communities.
`CommunityContextInterface`'s own docblock states this is deliberate: "Not set
during CLI execution, admin superuser sessions, or any context where
cross-community access is intentional."

So the framework guarantees a community-scoped type *has* a context mechanism, and
does not guarantee a community is *set* on any given execution. T-2 is the
consequence at scale.

## T-4 — CONFIRMED: community is not derived from the authenticated principal

`CommunityMiddleware::resolve()` (`:85-103`) takes the community from a route
parameter first, then a session key. It reads no header, query string, or body —
but it performs **no membership check**, depending only on
`CommunityContextInterface`. Whatever value it finds becomes the active scope.

Critically, the storage scope and the principal are **disconnected**:
`CommunityScope` reads `CommunityContextInterface`; no code bridges
`AuthorizationPrincipalInterface::communityId()` into it. The principal's
`communityId()` feeds cache keys and capability records only.

`AccountPrincipalFactory::fromAccountInContext()` (`:21-25`) short-circuits —
`if ($account instanceof AuthorizationPrincipalInterface) { return $account; }` —
and both `AnonymousUser` and `DevAdminAccount` implement that interface with
hardcoded `null` community. So a resolved community is silently discarded for
every anonymous request. *(reported; the short-circuit was read directly.)*

**Reachability bounds this.** No first-party route declares a `{community_id}`
parameter, and no production code writes the `waaseyaa_community_id` session key —
both resolution paths are dormant in the shipped framework. The hazard is for a
downstream application that activates either, as the middleware docblock invites.
This is the identity half, and it is already tracked as **#2816**.

## T-5 — CONFIRMED: custom unique keys are never community-scoped

`ensureDeclaredUniqueKeys()` (`SqlSchemaHandler.php:281-317`) materializes exactly
the field list the entity-type author supplied, with no automatic `community_id`
injection; the same holds for bundle unique keys (`:1263-1300`). Nothing validates
that an author added it.

A community-scoped type declaring a natural key — a per-community slug — collides
globally across communities by default. This is #2815's "collide through unique
keys across scope" requirement, confirmed unmet. *(reported.)*

## T-6 — CONFIRMED: the in-memory driver enforces what the SQL driver does not

`InMemoryStorageDriver::findTranslations()` checks the base row's `community_id`
against the active scope and returns `[]` on mismatch. `SqlStorageDriver`'s
`findTranslationsSqlBlob()`/`findTranslationsSqlColumn()` issue raw SQL with no
`community_id` predicate.

The divergence runs in the dangerous direction: a unit test asserting tenancy
isolation for `findTranslations()` against the in-memory driver passes, while the
SQL-backed path carries no such guarantee. Writes are enforced on both.
*(reported; the divergence direction was spot-checked.)*

## T-7 — CONFIRMED: three unrelated "tenant" concepts coexist

1. `AuthorizationPrincipalInterface::tenantId()` — populated only from
   `tenant_id`/`_tenant_id` request attributes, which have **no production
   writer**, and no route declares a `{tenant_id}` segment. Always null in the
   stock framework. So tenant *identity resolution* is unwired, not merely tenant
   enforcement.
2. `Waaseyaa\Foundation\Tenant\*` — `TenantContext`, `TenantMiddleware`,
   `TenantResolverInterface`, `NullTenantResolver`, self-documented "`@internal`
   Not wired in v1.0 — reserved for v2.0", no `#[AsMiddleware]`, no registration.
   Closed issue **#321** covers exactly this.
3. `EntityRepository::tenantIdFromValues()` — an optimistic-concurrency partition
   key derived from the entity's own `community_id`, defaulting to `'_global'`.
   Concurrency only, never access control.

None is connected to the others. *(reported.)*

## T-8 — CONFIRMED: derived surfaces inherit storage scoping or have none

- **Search**: `search_index`/`search_metadata` have **no** community column.
  Isolation rides entirely on `EntitySearchCandidateResolver` re-reading the
  entity through the community-scoped driver — the `view` access check is not a
  backstop for community, since no access policy is community-aware. If the
  context is inactive (T-2/T-3), search becomes cross-community.
- **Embeddings**: raw vector similarity search is unscoped; isolation depends on
  downstream `findMany()` re-resolution, and the access check is skipped entirely
  when `SearchController` is constructed without its two optional dependencies.
- **Caches**: no community context exists in `ContextNames`; no cache key —
  listing, render, discovery — carries a community dimension. Not independently
  exploitable given globally-unique entity ids **provided** the populating read
  was scoped; that proviso is exactly what T-2/T-3 remove.
- **Broadcast/SSE**: `_broadcast_log`/`_broadcast_retained` have no community
  column, and only `admin` and `session:*` channels are gated — any other channel
  name is subscribable by any client supplying it, with no ownership check.
  Safety rests on channel-id unguessability, which this lane did not evaluate.
- **Audit**: `audit_event` has no tenant column (matching the prior lane).
  `privileged_read_ledger` carries tenant/community inside a JSON `descriptor`
  blob — not a queryable column — populated from the principal on HTTP paths and
  statically declared (or null) on CLI/migration paths.
  *(all reported; the audit column set was confirmed in the prior lane.)*

## T-9 — CONFIRMED: no shipped entity type opts in, which bounds everything above

No production `EntityType` construction passes `tenancy: ['scope' => 'community']`,
and no production class implements `HasCommunityInterface` or uses
`HasCommunityTrait` — the only matches are the interface and `CommunityScope`
themselves. Verified directly by the lane owner.

So **nothing in the framework as distributed is exposed by T-1, T-2, T-5 or T-6**.
The machinery is a capability built for downstream adopters — Nation
distributions, and the consumer that prompted #2815 — and the gaps fire on the
first opt-in. There is also a legacy footgun: a class implementing
`HasCommunityInterface` *without* declaring `tenancy:` gets a deprecation warning,
not enforcement.

This bound is why nothing here is reported as a live vulnerability, and why all of
it still matters.

## Corrected and disproved leads

- **The lane owner's own over-broad claim.** An interim report stated community
  scoping "fails open by design" without qualification. That was imprecise: a
  boot-time fail-closed guard exists (T-3). The accurate statement separates
  wiring from activation.
- **The OCAP hypothesis is disproved.** The lane owner raised the possibility that
  community is an Indigenous data-governance concept rather than a security
  boundary, in which case failing open might be correct. The specs and #1094 say
  otherwise, explicitly and repeatedly. Community is the tenancy boundary.
- **#2815's "different concepts" framing is the consumer's, not the framework's.**
  No framework spec, ADR, or historical issue defines a non-community tenant;
  #1094 equates them. The corroborated asymmetry is narrower and real: the
  identity layer models two dimensions (ADR-022 gives `tenantId()` and
  `communityId()` independent null defaults) while the storage layer recognises
  one, and `entity-system.md:856` says "future region/org scopes require an
  explicit code change." *(reported.)*
- **Revisions are enforced.** Revision tables deliberately carry no `community_id`;
  visibility and mutation anchor to the base row via `baseScopeState()`, guarded by
  `TenancyViolationException`. This is correct indirection, not a gap — relevant
  because closed #2320 reported the opposite before it was fixed.

## Existing issue mapping

| Issue | State | Relation |
|---|---|---|
| **#2815** | open, p1, beta-blocker | This lane's subject — owns the **storage** half. T-1, T-5, T-6 are confirmed evidence for its acceptance criteria; T-9 bounds their current exposure. |
| **#2816** | open, p1, `area:security`, beta-blocker | Owns the **identity** half — "server-side tenant selection lacks a validated membership binding between request attributes and durable identity", which is exactly T-4. **Do not re-file.** |
| **#1094** | closed | Founding design issue; the source of the security-boundary framing quoted above. |
| **#2320**, **#2322**, **#2325**, **#2327** | closed | Four prior P0/security bypasses on the community axis (revision APIs, translation peer writes, divergent context, community omitted from principals). Evidence that this axis has failed before. Revision and write-side enforcement were confirmed present by this lane; the read-side translation gap (T-6) is adjacent to #2322's territory and worth checking against it. |
| **#321** | closed | Original finding that `TenantMiddleware` was unwired. The code still exists, still unwired, now self-documented as v2.0 — T-7. |
| **#2819** | open | **Correction to this lane's own earlier recommendation:** #2819 is *not* blocked on completing #2815. Its same-connection atomicity contract can be designed against a declared tenant dimension provided it states which dimension it commits to rather than inheriting an unenforced one. What this lane establishes for #2819 is narrower: `audit_event` has no tenant column, and the only existing scope is a community filter that is not enforced on every path. |
| **#2985** | open | Anchor. `issue-coverage.md` places #2815 in `entity-database-search-cache` and #2816 in `identity-access-workflows`, both "inventory-only-not-dispatched". This lane performs the source check that inventory deferred. |

**No new issue is filed.** Every confirmed finding belongs to #2815 or #2816.

## Compatibility constraints on any repair

- Community scoping is opt-in per entity type and currently has **zero** adopters;
  a repair can therefore change enforcement semantics without breaking shipped
  behaviour, which will not remain true once an adopter ships.
- Scoping `getQuery()` (T-1) changes result sets for any future adopter and must
  not silently narrow queries for non-tenant types — the driver's
  `?->isActive()` pattern exists precisely to avoid touching unscoped entities.
- The documented escapes in T-3 (`$allowUnscopedMutationAuthorityRepair`,
  development mode) are deliberate and load-bearing for repair tooling; a
  fail-closed request-time rule must preserve them explicitly.
- `CommunityContextInterface` is a per-process singleton cleared in a `finally`;
  restoring it in a worker (T-2) needs care to avoid leaking scope between jobs.
- `entity-system.md:1458` must be corrected or made true — as written it is a
  false security guarantee regardless of which repair lands.

## Focused acceptance criteria

1. `getQuery()` applies the same community condition as the driver, or
   `EntityQueryInterface` is documented as unscoped and the spec's "all entity
   queries" claim is corrected. A test must cover both read surfaces on the same
   community-scoped type.
2. A job enqueued in community A and executed by a worker either runs scoped to A
   or fails closed — and the envelope's existing `communityId` is the obvious
   carrier. Scheduled tasks need an explicit declared scope.
3. Declared unique keys on a community-scoped type either include `community_id`
   automatically or are rejected at schema build unless they do.
4. `SqlStorageDriver::findTranslations*()` matches `InMemoryStorageDriver`'s
   enforcement, so driver-parity tests mean what they appear to mean.
5. A request-time decision is stated for community-scoped types: refuse when no
   community is active, or document unscoped execution as intended and enumerate
   which entry points may rely on it.
6. Membership binding (T-4) is settled on #2816 before any route or session flow
   is allowed to select a community.

## Residual work not covered

- Every `AccessPolicyInterface` implementation was not audited; this lane
  established only that `packages/access/src` itself is community-unaware.
- The four closed bypasses (#2320/#2322/#2325/#2327) were not each re-verified
  end to end; revision and write-side enforcement were confirmed present, the
  read-side translation gap was not checked against #2322's original scope.
- Whether discovery/render HTTP routes always carry an active community context,
  or are cross-community browse surfaces by design.
- Broadcast channel-id unguessability.
- `AgentScheduleEntries` and other concrete scheduled tasks were not inspected
  individually for entity access.
- No executed proof for any finding.

## Recommended next disjoint lane

**Access-policy community awareness.** T-1 and T-8 both terminate at the same
unexamined surface: `packages/access/src` is community-unaware, so every claim
that "the access policy would catch it" is currently unsupported. Auditing what
the registered policies actually check — and whether any entity type ships a
policy that could serve as a second line of defence — is the natural successor and
is disjoint from all eleven lanes to date.
