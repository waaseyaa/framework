# Access Control
<!-- Spec reviewed 2026-07-30 - #2154 (follow-up to #2146): a session.stateless_paths entry of exactly "/" now means the ROOT PATH only, not a prefix of every path. Prefix-matching it made every anonymous GET stateless including /admin/login (a GET that must mint a CSRF token, withheld when no session exists), so an app could not express a cookie-free homepage without silently breaking its own authentication. Named prefixes are unchanged. See middleware-pipeline.md "Stateless path gate". -->

<!-- Spec reviewed 2026-07-30 - #2146 stateless session paths: SessionMiddleware gains an opt-in session.stateless_paths gate (anonymous GET/HEAD on configured prefixes skip session_start; session-cookie-carrying requests resume; other methods unchanged; default [] is exact behavior parity). Access-control semantics unchanged: skipped sessions resolve to AnonymousUser under deny-unless-granted. Full contract in middleware-pipeline.md "SessionMiddleware". -->
<!-- Spec reviewed 2026-07-21 - #2101: the canonical `administrator` superuser role satisfies legacy `_role: admin` route requirements. The existing `admin` role remains valid and is not promoted to `administrator`; ordinary role matching and comma-separated alternatives are unchanged. -->

<!-- Spec reviewed 2026-07-21 - #2101 WP-2: note rows now carry Protected uid authorship. The canonical administrator may administer note rows; otherwise view/edit/delete-own grants require an exact authenticated uid match. Note uid edits are server-managed except for an account with administer notes. The immutable core.note type remains non-deletable while authorized note instances are deletable. -->

<!-- Spec reviewed 2026-07-21 - #2064 media hotfix: a first-party Protected field policy may opt into hydrated entity-view delegation. The field policy's own Forbidden still wins; otherwise EntityAccessHandler evaluates the complete registered `view` policy set against the matching immutable principal and already-hydrated entity. A missing or structurally mismatched entity fails closed. Media opts in so application contextual grants such as Band Member access release bundle-defined Protected metadata without bypassing an application Forbidden. Internal fields, pre-hydration query policy, and unrelated entity types are unchanged. -->
<!-- Spec reviewed 2026-07-19 - #2079 adds one purpose-scoped ReBAC operation alongside, not inside, generic RBAC evaluation. AuthorizedRelationshipTraversal::memberDirectory selects broad-only behavior when group:view is Allowed; otherwise its isolated scoped branch requires strict exact-group opt-in plus the authenticated principal's live direct membership from the same graph snapshot. The scoped branch grants only MemberDirectoryEntry{userId, displayName}; it does not mutate permissions/policies, authorize edges(), or release generic group/relationship/user fields. Generic Forbidden results retain precedence on the broad branch and never fall back to scoped output. -->
<!-- Spec reviewed 2026-07-19 - #2064 alpha.269 principal-convergence follow-up: every first-party HTTP access-decision boundary now propagates the middleware-established immutable AuthorizationPrincipal rather than the live User entity. EntityAccessHandler's five public decision entry points and the policy interfaces are statically narrowed to AuthorizationPrincipalInterface for PHPStan, and the handler also rejects every entity-backed decision account at runtime before policy dispatch; DecisionAccountResolver permits only the validated principal or an immutable principal value object, never an AccountInterface/User fallback. The architecture suite pins both layers. The sweep covers media and attachment downloads, OIDC userinfo field decisions, generic API/schema/translation/workflow/search/discovery/GraphQL/admin/SSR/app-controller surfaces, broadcast and Wayfinding permission checks, search account context, and direct agent-run policy decisions. Internal User fields and missing-context behavior remain unchanged. -->
<!-- Spec reviewed 2026-07-18 - #2064 WP4 routes access-checked query candidates through the current immutable HTTP principal when a caller binds the matching live account. A mismatched active identity fails before SQL, and principal cache dimensions include claims generation plus tenant/community scope. Policies may opt into entity-free candidate evaluation only through the explicit complete projected-policy contract whose exact Protected input set matches the generation-bound EntityReadLayout; stale/incomplete projections fail closed and every non-opted policy retains hydrated evaluation. Canonical details: entity-field-read-boundary.md. -->
<!-- Spec reviewed 2026-07-18 - #2064 WP3 adds the immutable-principal User V2 read policies: status is the exact non-recursive entity/name authorization input; active profiles require access user profiles, inactive profiles/names are admin-only, and direct status is self/admin only. Internal identity/credential fields are never released by these account policies. Activation wiring remains WP4. Canonical contract: entity-field-read-boundary.md. -->
<!-- Spec reviewed 2026-07-17 - #2064 WP1 adds immutable AuthorizationPrincipal contracts, a fiber-local account-only read scope, fail-closed Protected policy inputs, and exact opaque capability declaration/registry contracts. No account context, middleware, policy evaluator, CLI, or queue behavior changes. Canonical contract: entity-field-read-boundary.md. -->
<!-- Spec reviewed 2026-07-17 - #2064 WP2 adds strict multi-field bootstrap readers and production FieldReadContextMiddleware at priority 15: bearer/session identity resolves first, a fresh capability strictly audits immutable User claim extraction, the principal scope surrounds authorization/dispatch (including deferred streams), and finally cleanup prevents long-running-request bleed. Capability issuance and every use require the same live registry-owned CapabilityExecutionBoundary identity; matching correlation text is not authority. Field/query enforcement remains dormant. Canonical contract: entity-field-read-boundary.md. -->
<!-- Spec reviewed 2026-07-17 - #2064 WP3 adds dormant first-party Public/Protected/Internal classifications and a generation-aware reusable guard/read-plan fixture. Protected policy evaluation receives immutable structure plus a closed compiled subject view, never the full entity; production EntityBase activation remains WP4. Canonical contract: entity-field-read-boundary.md. -->

<!-- Spec reviewed 2026-07-14 - #2020 policy discovery security: packages declare their full access-policy inventory in extra.waaseyaa.policies. PackageManifestCompiler rejects missing/divergent discovery before boot, and AccessPolicyRegistry throws rather than warning-and-skipping if a manifested class disappears. -->
<!-- Spec reviewed 2026-07-14 - R21 WP7 (#2010): bin/check-access-hardening is the recurring prevention gate for the audit's agent-tool guard, public access-vs-status, filter/sort field-access, and explicit route-posture failure classes. It runs fixture self-tests and the real repository scan through composer verify and the blocking verify-gates CI job. -->

<!-- Spec reviewed 2026-07-02 - audit-remediation batch WP3: new Waaseyaa\Path\PathAliasUniquenessListener (BeforeSaveEvent hook enforcing path_alias (alias, langcode) uniqueness) queries via accessCheck(false), justified identically to PathAliasResolver above — a system-context uniqueness check, not a user-facing view. No change to the access pipeline, gate logic, or access-decision semantics; see CHANGELOG [Unreleased] Fixed for the full path-alias NFC-normalization + uniqueness fix. -->
<!-- Spec reviewed 2026-06-22 - WP12b (install-closure / layering): UserServiceProvider (Layer 1) no longer statically calls Layer-6 Waaseyaa\SSR\SsrServiceProvider::getTwigEnvironment() to build AuthMailer — an upward layer violation. AuthMailer's $twig is now nullable and the provider resolves Twig\Environment from the container (SSR registers it as a singleton; cross-provider resolution via ProviderRegistryKernelServices). With no Twig renderer available, AuthMailer::isConfigured() is false and the send methods no-op. No access-policy/contract change; user-package wiring only. Pinned by AuthMailerTest::is_unconfigured_and_sends_nothing_without_a_twig_renderer + UserServiceProviderLayerTest. -->
<!-- Spec reviewed 2026-06-15 - B-1 (security): UserAccessPolicy now implements FieldAccessPolicyInterface — roles/permissions/status/email_verified are Forbidden on 'edit' without 'administer users' (even on one's own account), and pass/two_factor_secret/two_factor_recovery_codes_hash are Forbidden on the generic field surface, closing a JSON:API write path where an authenticated user editing their own account could set privileged fields. The field-access mechanism (FieldAccessPolicyInterface, open-by-default; see field-access.md) is unchanged — this is an entity policy that uses it. Pinned by UserAccessPolicyTest. -->

<!-- Spec reviewed 2026-06-12 - #1655 companion: User::email_verified #[Field] declared required: false — the NotNull inference from the non-nullable bool property was inert before save-time validation went live (alpha.204) and no write path supplies the field; account contracts otherwise unchanged. -->
<!-- Spec reviewed 2026-06-12 - mission revision-audit-provenance-01KTWY5V WP05: new request-scoped acting-account context — Waaseyaa\Access\Context\AccountContextInterface + RequestAccountContext (three-state actor model: account N / anonymous 0 / null = no acting context, never coerced). One kernel-shared instance (AbstractKernel::accountContext()) exposed via the kernel-services bus, the handler container, the repository factory (EntityRepository::setAccountContext) and HttpKernel's SessionMiddleware wiring; writers are SessionMiddleware (unconditional per-request overwrite, incl. bearer-auth accounts), McpEndpoint and AgentExecutor (set/restore in finally); readers are entity-storage revision_author resolution (SaveContext::withActorUid override wins) and the audit listeners. SessionMiddleware constructor gained ?AccountContextInterface. Refs #1644, #1645. -->
<!-- Spec reviewed 2026-06-04 - PR #1614: the local dev-fallback admin account (`Waaseyaa\User\DevAdminAccount`) is gated by `HttpKernel::shouldUseDevFallbackAccount()` — a development `APP_ENV` plus the explicit `auth.dev_fallback_account` opt-in are the real gates, with the `cli-server` SAPI check kept only as a secondary safety belt so the FrankenPHP worker runtime can also use it. No change to the access-decision pipeline, policies, or field-access semantics. -->
<!-- Spec reviewed 2026-05-20 - updated for M-B container-resolved registry (PolicyDependencyResolverInterface), fail-closed CI gate (bin/check-getquery-bindings), and WP05 retro regression tests. -->
<!-- Spec reviewed 2026-05-20 - post-#1525 sweep caught two more #1495 misses: SitemapGenerator::collectFromEntityTypes() (sitemap generation is anonymous-served by definition; same shape as PathAliasResolver) and UserBlockService::isBlocked() (block-relationship existence is an integrity primitive; same shape as RelationshipValidator). Both opt out via accessCheck(false) with C-004 inline references; audit doc updated. No change to access pipeline, gate logic, or access semantics. -->
<!-- Spec reviewed 2026-05-20 - #1525 #1495-sweep miss in AuthController::findUserByName (pre-auth identity resolution) now opts out with accessCheck(false); semantics unchanged — the documented system-context bypass simply applies to a missed call site. Audit doc updated with two new entries. No change to access pipeline, gate logic, or access semantics. -->
<!-- Spec reviewed 2026-05-19 - SqlEntityQuery query-layer access checking added per mission sql-entity-query-access-checking-01KRYP15 (#1495): EntityQueryInterface::setAccount() binds the account used for per-row filtering; SqlEntityQuery::execute() now runs EntityAccessHandler::check($entity, 'view', $account) for every candidate row; accessCheck(true) is the default and accessCheck(false) is preserved as an audited system-context opt-out (see docs/security/sql-entity-query-access-check-bypass-audit.md); MissingQueryAccountException is thrown when neither bypass nor account is bound. -->
<!-- Spec reviewed 2026-05-10 - #1395 dead-code removal: CsrfMiddleware::attachXsrfCookie() instance method deleted; attachCookieIfHtml() static helper (called by HttpKernel) remains the sole live cookie-attachment path. No change to session resolution, gate logic, or access pipeline semantics. -->
<!-- Spec reviewed 2026-06-23 - audit C-6: Layer 3 (entity query) flipped to deny-by-default. SqlEntityQuery::filterCandidates() survivor test is now isAllowed() (was !isForbidden()), so a Neutral row is dropped, not admitted; count() inherits this. The empty/unwired-handler fallback now denies every candidate (fail-closed) instead of passing the unfiltered window. Production wiring is unchanged (#1714 accessHandlerResolver). Consumers' isAllowed() re-filters become belt-and-braces. Genealogy SSR neighbor/ancestor topology now gathered via accessCheck(false) (system context) with per-person gate concealment, so living/private relatives still render as redacted placeholders rather than vanishing. Pinned by SqlEntityQueryDeniesNeutralRowTest + GraphQL/discovery/semantic/genealogy integration suites. -->
<!-- Spec reviewed 2026-05-10 - WP05 php-8.5 upgrade: @PHP8x5Migration cs-fixer pass — AuthorizationMiddleware and EntityAccessHandler touched by octal_notation + new_expression_parentheses rules only; no semantic change to access pipeline or gate logic. -->
<!-- Spec reviewed 2026-05-10 - WP03 php-8.5 upgrade: AccessResult::allowed/forbidden/neutral/unauthenticated gained #[\NoDiscard] — no semantic change to access pipeline, gate logic, or AccessChecker. -->
<!-- Spec reviewed 2026-05-13 - M-006 entity-storage-translations-v1: EntityAccessHandler recognizes new 'translate' operation with Neutral→update fallthrough (translate ⊆ update); explicit Forbidden honored. New Waaseyaa\Access\ContextAwareAccessPolicyInterface companion accepts a ['langcode' => $lc] context for translation-aware decisions, dispatched via instanceof — preserves backward-compat for existing AccessPolicyInterface implementors. Full surface documented at docs/specs/entity-storage-translations-v1.md §3.9. -->
<!-- Spec reviewed 2026-05-01 - Auth README added under packages/auth/ (skeleton only — purpose, layer, key classes); no AuthManager/RateLimiter/TwoFactorManager contract change. Reaffirms WP05 paired-nullable invariants and AccessChecker placement (mission #824 WP09 surface F, closes #849) -->
<!-- Spec reviewed 2026-04-25 - packages/user: #[ContentEntityType]/#[ContentEntityKeys] alignment with EntityTypeManager registration parity; no gate or policy semantics change -->
<!-- Spec reviewed 2026-04-24 - Auth HTTP routes moved to Waaseyaa\Routing\AuthOidcRouteServiceProvider; AuthServiceProvider is DI-only; auth controllers and access semantics unchanged (Layer 1 audit remediation) -->
<!-- Spec reviewed 2026-04-11 - User/UserBlock: widened constructors (optional entityTypeId, entityKeys, fieldDefinitions) for ContentEntityBase::duplicateInstance re-entry; no change to gate or policy semantics (#alpha-119) -->
<!-- Spec reviewed 2026-04-08 - LoginController: removed session_write_close() after successful JSON login so Set-Cookie is emitted with the response (#813); no change to gate/session access semantics -->
<!-- Spec reviewed 2026-04-07 - packages/auth composer.json: waaseyaa/* requires use ^0.1 for split/Packagist consumers (#1138); no access API change -->
<!-- Spec reviewed 2026-04-03c - auth controller review fixes: JSON_THROW_ON_ERROR, session guard, AccountInterface null check (#571) -->
<!-- Spec reviewed 2026-04-08 - composer manifest policy normalization for packages/access, packages/auth, packages/user; no runtime access-control behavior change -->
<!-- Spec reviewed 2026-04-08b - restored packages/access symfony/routing floor from ^7.3 back to ^7.0 to avoid unnecessary downstream constraint tightening -->
<!-- Spec reviewed 2026-05-01 - AccessChecker canonical placement: source lives at packages/access/src/AccessChecker.php with namespace Waaseyaa\Access; routing depends on access (downward); package tables, file/namespace headers, and dir-tree visualization corrected (mission #824 WP05 surface A, closes #832) -->

Waaseyaa's access control system spans three packages: `packages/access/` (core primitives), `packages/routing/` (route-level checks), and `packages/user/` (session resolution, password reset). This document covers entity-level and route-level access. For field-level access, see `docs/specs/field-access.md`.

Route role matching is exact except for one directional superuser implication: an account holding the canonical `administrator` role also satisfies a route requiring the legacy operational role id `admin`. An account holding only `admin` does not thereby acquire `administrator`; this preserves existing operational-role compatibility without broadening canonical administrator checks.

## Public Surface

Authoritative dispositions are in `docs/public-surface-map.php`, verified by `PublicSurfaceVerificationTest`.

**Public API** (stable, semver-protected):

| Package | Interfaces/Classes |
|---------|-------------------|
| access | `AccountInterface`, `AccessPolicyInterface`, `FieldAccessPolicyInterface`, `PermissionHandlerInterface`, `GateInterface` |

**`@internal`** (implementation details, may change without notice):

| Package | Interface/Class | Reason |
|---------|----------------|--------|
| access | `ErrorPageRendererInterface` | Error page rendering detail, not a consumer contract |
| auth | `AuthTokenRepositoryInterface` | Token storage internals |
| auth | `RateLimiterInterface` | Auth-specific rate limiter, distinct from Foundation's public `RateLimiterInterface` |

## Packages

| Package | Path | Provides |
|---------|------|----------|
| access | `packages/access/src/` | AccessPolicyInterface, AccessResult, AccessStatus, EntityAccessHandler, AccountInterface, FieldAccessPolicyInterface, PermissionHandler, Gate, EntityAccessGate, AuthorizationMiddleware, **AccessChecker** (route-level access) |
| auth | `packages/auth/src/` | Controllers and services for login/register/reset/verify; **HTTP route registration** is in `packages/routing` (`AuthOidcRouteServiceProvider`), not in `AuthServiceProvider` |
| routing | `packages/routing/src/` | `AuthOidcRouteServiceProvider` wires auth and OIDC HTTP routes (AccessChecker is owned by `waaseyaa/access`, not routing — mission #824 WP05 surface A) |
| user | `packages/user/src/` | SessionMiddleware (account resolution), UserServiceProvider (user entity type registration) |

## Core Interfaces

### AccessPolicyInterface

**File:** `packages/access/src/AccessPolicyInterface.php`
**Namespace:** `Waaseyaa\Access`

```php
interface AccessPolicyInterface
{
    public function access(
        EntityInterface $entity,
        string $operation, // 'view', 'update', or 'delete'
        AccountInterface $account,
    ): AccessResult;

    public function createAccess(
        string $entityTypeId,
        string $bundle,
        AccountInterface $account,
    ): AccessResult;

    public function appliesTo(string $entityTypeId): bool;
}
```

- `access()` checks an existing entity for a given operation.
- `createAccess()` checks whether an entity of the given type/bundle can be created.
- `appliesTo()` scopes which entity types this policy governs. EntityAccessHandler skips policies that return `false`.

### AccountInterface

**File:** `packages/access/src/AccountInterface.php`
**Namespace:** `Waaseyaa\Access`

```php
interface AccountInterface
{
    public function id(): int|string;
    public function hasPermission(string $permission): bool;
    public function getRoles(): array; // string[]
    public function isAuthenticated(): bool;
}
```

**Critical:** `AccountInterface` lives in the `access` package, not `user`. The `User` entity and `AnonymousUser` live in `packages/user/`. Access must never depend on User to avoid circular package dependencies. Middleware needing an account should type-hint `AccountInterface`, not concrete `AnonymousUser`.

**`User::isAuthenticated()` requires a persisted identity.** Because `User::id()` coerces a null uid to `0`, a freshly-constructed unsaved `User` would otherwise report the same `id() === 0` signal as the anonymous user. The predicate is `!$this->isNew() && $this->id() > 0` — authenticated only when the user is a persisted record (not `isNew()`) with a non-zero uid; an unsaved or uid-0 user is not authenticated.

**Canonical boolean-field representation (#2064, alpha.270).** Every entity
field whose resolved definition type is `boolean` or `bool` has one value
representation: native PHP `bool` (or `null` when nullable). The compiled
definition layout canonicalizes legacy/input `0`/`1` values while atomically
sealing construction and hydration, and canonicalizes every later `set()`.
`User.status` and `User.email_verified` therefore have the same value contract;
`isActive()` remains the convenience/access-controlled liveness predicate, not
an alternate type adapter. Physical SQL boolean encoding is a backend detail and
must not be observable through entity reads, validation, or persistence
snapshots.

**Accepted same-layer cycle (`access` ↔ `entity`):** `access` depends on `entity` for the core contracts it authorizes over (`EntityInterface` and siblings — 8 files / 11 imports). `entity` depends back on `access` for exactly **one** reverse symbol: `Waaseyaa\Access\AccountInterface`, imported only by `EntityQueryInterface::setAccount(?AccountInterface)` so a query can carry the requesting account for access-aware filtering. This 2-cycle is **accepted and deliberately not broken**: extracting `AccountInterface` to a Layer-0 package would be the textbook fix, but `AccountInterface` is public surface (`docs/public-surface-map.php`), so a namespace move is semver-breaking across ~250 callsites — net-negative against a one-symbol, query-time binding. The pair is one of five reviewed entries in `tools/package-layers-cycle-baseline.txt`; `bin/check-package-layers` emits a warning for those exact historical pairs and hard-fails **`PL006`** for every new same-layer cycle.

## Access Result Semantics

**File:** `packages/access/src/AccessResult.php`
**Namespace:** `Waaseyaa\Access`

AccessResult is a `final readonly class` with three states defined in the `AccessStatus` enum:

```php
enum AccessStatus: string
{
    case ALLOWED = 'allowed';
    case NEUTRAL = 'neutral';
    case FORBIDDEN = 'forbidden';
}
```

### Factory Methods

```php
AccessResult::allowed(string $reason = ''): AccessResult
AccessResult::neutral(string $reason = ''): AccessResult
AccessResult::forbidden(string $reason = ''): AccessResult
AccessResult::unauthenticated(string $reason = ''): AccessResult
```

`$reason` is a non-nullable string (`''` default) — callers needing a fallback message must use `!== ''` rather than `??`; the null-coalesce is dead code and PHPStan flags it as `nullCoalesce.property`.

### State Checks

```php
$result->isAllowed(): bool   // status === ALLOWED
$result->isNeutral(): bool   // status === NEUTRAL
$result->isForbidden(): bool // status === FORBIDDEN
```

### Combination Logic

**`orIf()`** -- OR logic, used by EntityAccessHandler to combine policy results:

- Forbidden wins over everything (short-circuit)
- Either Allowed yields Allowed
- Both Neutral yields Neutral

**`andIf()`** -- AND logic, used by AccessChecker to combine route requirements:

- Forbidden wins over everything (short-circuit)
- Both must be Allowed for Allowed
- At least one Neutral yields Neutral

### Entity-Level Evaluation Pattern

Entity access uses **deny-by-default** with `isAllowed()`:

```php
// EntityAccessHandler::check() starts with Neutral, combines via orIf().
// Controller checks: $result->isAllowed()
// Neutral means "no policy granted" = denied.
```

This is intentionally asymmetric with field-level access, which uses `!isForbidden()`. See `docs/specs/field-access.md`.

**Workflow-derived node visibility (#2081).** Workflow authority is an additive entity-view opinion, not a role or mutation grant. For a bound node, an authenticated principal is Allowed only when `loadWorkingCopy()` resolves the exact requested type/bundle/id and `TransitionService::getAvailableTransitions()` finds at least one permission- and group-valid outgoing edge from that working copy's current state. Every non-match and every create/update/delete check is Neutral, never Forbidden, preserving Forbidden-wins composition and ordinary published/owner access. The protected companion deliberately stays on hydrated evaluation rather than `ProjectedProtectedEntityReadPolicyInterface`, because a served/base-row projection cannot establish current tip state. It supplies no protected field policy, so field sealing is unchanged.

**Application protected-read classification (#2082).** An application may provide a `ClassifiedProtectedEntityReadPolicyInterface` whose exact `classificationInputs()` are projected privately for query/list/count and supplied identically during hydrated detail evaluation. Inputs may be Public fields without changing their output classification; Protected inputs still require reviewed authorization-input metadata, while Internal and unknown fields fail closed. Each policy receives only its own declared subject shape, missing inputs are Forbidden, and all applicable policy results compose with Forbidden winning over Allowed and Neutral. Filtering occurs before totals, ordering, and pagination, so adding an application policy cannot widen or bypass framework visibility.

**Hydrated entity-view delegation for Protected fields (#2064).** A first-party field policy marked by `EntityViewProtectedFieldReadPolicyInterface` composes its field-specific result with `EntityAccessHandler::check($entity, 'view', $principal)`. This is available only at the accessor boundary where the exact hydrated entity accompanies its immutable `EntityStructure`; missing type, bundle, or identity parity returns Forbidden. A field-specific Forbidden remains authoritative. Media uses this seam so consumer policies that grant or deny a contextual media view also govern bundle-defined Protected metadata. Such entity policies must decide from Public fields, declared subject inputs, or contextual services and must not recursively read the Protected field under evaluation.

**Capability-scoped staff directories (#2086).** A host binds a capability name and one exact roster group bundle through `StaffDirectoryReadDeclaration`; neither member status nor a role name is accepted as staff authority. The groups package discovers `CapabilityScopedStaffDirectoryAccessPolicy`, which remains dormant when that host declaration is absent, and lazily exposes `StaffDirectoryReaderInterface` after the final handler exists. Its contextual User entity-view batch is evaluated inside a consistent-read transaction owned by the same database object as the policy and qualified hydrator, after resolving exactly one active group of the declared bundle and its live, directed `user -> group` memberships. A capability holder receives an Allowed opinion only for active User candidates in that exact roster; other candidates remain Neutral so independent administrator/profile authority is not globally erased. The staff reader requires this exact declared context key in addition to ordinary Forbidden-wins composition, so missing/dormant policy or non-roster Neutral cannot fall through to an unrelated grant. Missing capability is Neutral, and the reader also refuses list and detail before querying, so ordinary members, view-only staff, non-members, and anonymous principals receive no rows or cardinality. The policy supplies no Protected field policy: User `name` and `status` retain their existing policy, and Internal email/claims/credentials remain sealed.

## Entity Access Handler

**File:** `packages/access/src/EntityAccessHandler.php`
**Namespace:** `Waaseyaa\Access`

Orchestrates policy evaluation. Not a `final class` (can be extended).

```php
class EntityAccessHandler
{
    public function __construct(array $policies = []) // AccessPolicyInterface[]
    public function addPolicy(AccessPolicyInterface $policy): void

    public function check(
        EntityInterface $entity,
        string $operation,       // 'view', 'update', 'delete'
        AccountInterface $account,
    ): AccessResult;

    public function checkCreateAccess(
        string $entityTypeId,
        string $bundle,
        AccountInterface $account,
    ): AccessResult;

    public function checkFieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,       // 'view' or 'edit'
        AccountInterface $account,
    ): AccessResult;

    public function filterFields(
        EntityInterface $entity,
        array $fieldNames,       // string[]
        string $operation,       // 'view' or 'edit'
        AccountInterface $account,
    ): array; // string[] — fields not forbidden
}
```

### Evaluation Algorithm

For `check()` and `checkCreateAccess()`:

1. Start with `AccessResult::neutral('No policy provided an opinion.')`.
2. Iterate registered policies. Skip those where `appliesTo($entityTypeId)` returns false.
3. Call `$policy->access(...)` or `$policy->createAccess(...)`.
4. Combine results with `orIf()` (any Allowed grants access).
5. Short-circuit on Forbidden -- nothing can override it.
6. Return final result.

For `checkFieldAccess()` and `filterFields()`, see `docs/specs/field-access.md`.

### Policy Registration

Policies are passed to the constructor or added via `addPolicy()`. In the current post-M10 boot flow, `AccessPolicyRegistry` builds the handler from `PackageManifest::$policies`, while the kernel still exposes the resulting gate to `AccessChecker` during boot:

Every installed package that owns a `#[PolicyAttribute]` class declares that class in `extra.waaseyaa.policies`. Discovery must contain every declared class independent of Composer autoload optimization; a count/content mismatch or a class that cannot be loaded aborts boot with `POLICY_MANIFEST_MISMATCH`. There is no warning-and-continue path for incomplete enforcement.

```php
$accessHandler = new EntityAccessHandler([
    new NodeAccessPolicy(),
    new TermAccessPolicy(),
    new ConfigEntityAccessPolicy(entityTypeIds: ['node_type', 'taxonomy_vocabulary', ...]),
]);
$gate = new EntityAccessGate($accessHandler);
$accessChecker = new AccessChecker(gate: $gate);
```

### Bundle-scoped policies

Multi-bundle entity types (e.g. `group`) may need different access rules per bundle. The `#[AccessPolicy]` attribute carries a `bundles` parameter for this:

```php
#[AccessPolicy(id: 'group_team', entityTypes: ['group'], bundles: ['team'])]
final class TeamAccessPolicy implements AccessPolicyInterface { ... }
```

- `bundles: []` (the default) — policy applies to every bundle of the named entity types. All pre-existing single-bundle policies retain their prior semantics without edits.
- `bundles: ['alpha', 'beta']` — policy applies only when the entity being checked has one of those bundles.

`EntityAccessHandler` keeps a parallel `$bundleFilters` array, populated from the attribute at registration time via `resolveBundles()` (reflection over `#[AccessPolicy]`). The filter is applied at every gate the handler exposes: `check()`, `checkCreateAccess()`, and `checkFieldAccess()`. A policy whose `bundles` list is non-empty is skipped when the resolved bundle does not match; a policy with an empty list is always considered. No ordering or combinator changes — the filter runs before `appliesTo($entityTypeId)`, and the rest of the evaluation algorithm is unchanged.

For the storage-side contract this surfaces (how bundle membership is resolved from per-bundle subtables and field registration), see `docs/specs/bundle-scoped-fields.md §Access`.

### Purpose-scoped relationship authorization

Generic entity access remains RBAC/policy-composed. The member-directory ReBAC
operation is a separate domain authority with a closed output, not a new
`AccessResult` combination rule. If generic source group view is Allowed, only
the broad source/edge/endpoint branch runs. If it is not Allowed, the scoped
branch may run only for an authenticated principal, a strict exact-group
opt-in, and a live direct membership edge for that principal and group. The
branches never merge or fall back per edge.

The scoped result cannot be reused as a permission, policy result, field-read
context, relationship predicate, or grant for another group. It returns only
the fixed member-directory DTO and leaves every later access decision
unchanged. Principal authentication/provenance remains the responsibility of
the established middleware/factory boundary; the domain operation derives the
claimant id from that immutable principal and accepts no caller-supplied member
identity.

## Gate System

The Gate is a separate access mechanism from EntityAccessHandler. It resolves policies by entity type and delegates ability checks to method calls.

### GateInterface

**File:** `packages/access/src/Gate/GateInterface.php`
**Namespace:** `Waaseyaa\Access\Gate`

```php
interface GateInterface
{
    public function allows(string $ability, mixed $subject, ?object $user = null): bool;
    public function denies(string $ability, mixed $subject, ?object $user = null): bool;
    public function authorize(string $ability, mixed $subject, ?object $user = null): void;
        // throws AccessDeniedException
}
```

### Gate (Implementation)

**File:** `packages/access/src/Gate/Gate.php`
**Namespace:** `Waaseyaa\Access\Gate`

```php
final class Gate implements GateInterface
{
    public function __construct(private readonly array $policies = [])
}
```

Policy resolution strategy:
1. Check for `#[PolicyAttribute(entityType: '...')]` on the policy class.
2. Fall back to naming convention: `NodePolicy` maps to entity type `node` (PascalCase to snake_case).

Ability delegation: `$gate->allows('update', $node)` calls `$policy->update($user, $node)`. If the method does not exist, ability is denied.

### EntityAccessGate (Adapter)

**File:** `packages/access/src/Gate/EntityAccessGate.php`
**Namespace:** `Waaseyaa\Access\Gate`

```php
final class EntityAccessGate implements GateInterface
{
    public function __construct(
        private readonly EntityAccessHandler $handler,
        ?LoggerInterface $logger = null,
        private readonly ?AccountFieldReadScopeInterface $fieldReadScope = null,
        private readonly ?AccountContextInterface $accountContext = null,
    )
}
```

Adapter that bridges `GateInterface` to `EntityAccessHandler`, reusing existing `AccessPolicyInterface` policies. Translation logic:

- `allows($ability, EntityInterface $subject, AccountInterface $user)` → `$handler->check($subject, $ability, $user)->isAllowed()`
- `allows('create', string $entityTypeId, AccountInterface $user)` → `$handler->checkCreateAccess($entityTypeId, '', $user)->isAllowed()` — the bundle-less form, bundle `''`, kept for backward compatibility
- `allows('create', ['entity_type' => string, 'bundle' => string], AccountInterface $user)` → `$handler->checkCreateAccess($entityType, $bundle, $user)->isAllowed()` — the bundle-aware form (GitHub #1946). Both `entity_type` and `bundle` must be strings and `entity_type` non-empty, or the array subject is treated as malformed (see below). `EntityDestination::buildCreateSubject()` (`packages/migration/src/Plugin/Destination/EntityDestination.php`) is the framework's own caller of this form, deriving the bundle from the destination entity's bundle-key value when the entity type declares one.
- String subject + non-`create` ability, or a malformed array subject → `false` (instance required for view/update/delete; array subject must match the `['entity_type' => ..., 'bundle' => ...]` shape)
- Non-null, non-`AuthorizationPrincipalInterface` user or unsupported subject type → `false` with a logged warning

**Null-user resolution (GateInterface contract: "Null means the current/anonymous user").** A `null` `$user` is resolved to the current principal, never blanket-denied: first the middleware-established immutable principal from the optional `AccountFieldReadScopeInterface` (installed per-request by `FieldReadContextMiddleware`); else a non-entity `AuthorizationPrincipalInterface` held by the optional `AccountContextInterface` acting-account context; else the anonymous principal `new AuthorizationPrincipal(0, false, [], [], 'anonymous')` (same shape `FieldReadContextMiddleware` builds for account-less requests). Entity-backed context accounts are never handed to a policy directly — they must cross the audited principal factory (see `DecisionAccountResolver`) — and degrade to the anonymous principal here. `denies()`/`authorize()` inherit this via delegation to `allows()`. This is what makes contract-correct `allows($op, $row, null)` callers (e.g. `ListingResolver`'s per-row FR-029 filter) return anonymous-viewable rows instead of an empty listing. The convention-based `Gate` needs no equivalent: it passes `$user` (including `null`) through to the policy method, which owns guest semantics.

Wired in `public/index.php`: wraps `EntityAccessHandler` and is passed to `AccessChecker(gate: $gate)`. Policy exceptions are caught, logged, and treated as denial.

**Kernel-services bus binding (G-014 / #1940).** `ProviderRegistryKernelServices::get(GateInterface::class)` (`packages/foundation/src/Kernel/Bootstrap/ProviderRegistryKernelServices.php`) now resolves a shared `EntityAccessGate` built from the same lazy `EntityAccessHandler` accessor used for `EntityAccessHandler::class` resolution — memoized per handler instance, so repeated resolves within a request return the same adapter. This closes a gap where consumers resolving `GateInterface` off the kernel bus outside the `public/index.php` HTTP wiring (e.g. `packages/listing/src/ServiceProvider.php`) got `null` and fell back to a deny-all `Gate([])`, the root cause of the Sheguiandah pass-1 512/512 `entity_create_denied` failure. Resolution is still `null` before `AbstractKernel::discoverAccessPolicies()` runs (the handler accessor is not yet available). The HTTP middleware wiring (`HttpKernel::buildMiddlewareStack()`) continues to construct its own `EntityAccessGate` directly. All three production construction sites — the kernel bus, `HttpKernel::buildMiddlewareStack()`, and `SsrServiceProvider::configureHttpKernel()` — pass the kernel's shared field-read scope and acting-account context so null-user resolution works everywhere.

### PolicyAttribute

**File:** `packages/access/src/Gate/PolicyAttribute.php`
**Namespace:** `Waaseyaa\Access\Gate`

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PolicyAttribute
{
    public function __construct(
        public readonly string $entityType,
    ) {}
}
```

### AccessPolicy (Plugin Discovery Attribute)

**File:** `packages/access/src/Attribute/AccessPolicy.php`
**Namespace:** `Waaseyaa\Access\Attribute`

Extends `WaaseyaaPlugin`. Used for attribute-based plugin discovery (distinct from `PolicyAttribute` for the Gate).

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AccessPolicy extends WaaseyaaPlugin
{
    public function __construct(
        string $id,
        public readonly array $entityTypes = [],
        public readonly array $bundles = [],  // see bundle-scoped-fields.md §Access
        string $label = '',
        string $description = '',
    ) {}
}
```

The optional `bundles:` parameter scopes a policy to specific bundles of the listed entity types. An empty array (default) preserves existing semantics — the policy applies to every bundle. See [`bundle-scoped-fields.md`](./bundle-scoped-fields.md#access) for the full contract.

### AccessDeniedException

**File:** `packages/access/src/Gate/AccessDeniedException.php`

```php
final class AccessDeniedException extends \RuntimeException
{
    public function __construct(
        public readonly string $ability,
        public readonly mixed $subject,
        string $message = '',
    ) {}
}
```

## Route Access Control

**File:** `packages/access/src/AccessChecker.php`
**Namespace:** `Waaseyaa\Access`

The class lives in the access package because route-level access checking is the routing-time consumer of the access subsystem (gates, policies, account context). The routing package depends on access, never the other way around.

```php
final class AccessChecker
{
    public function __construct(private readonly ?GateInterface $gate = null)

    public function check(Route $route, AccountInterface $account): AccessResult

    public static function applyGateToRoute(
        Route $route,
        string $ability,
        mixed $subject = null,
    ): void
}
```

### Route Options

Routes declare access requirements via Symfony Route options. Multiple requirements combine with AND logic (all must pass).

| Option | Type | Behavior |
|--------|------|----------|
| `_public` | `true` | Always allow (no auth required) |
| `_authenticated` | `true` | Require non-anonymous identity; returns `AccessResult::unauthenticated()` (401) if anonymous. Short-circuits before other checks. |
| `_session` | `true` or `string[]` | Require active session. When array, requires specific session keys to be present. |
| `_permission` | `string` | Require specific permission via `$account->hasPermission()` |
| `_role` | `string` | Require role (comma-separated for multiple); checks `$account->getRoles()` |
| `_gate` | `array{ability: string, subject?: mixed}` | Require gate ability check |

If no access requirements are present on the route, returns `AccessResult::neutral()`. AuthorizationMiddleware treats Neutral as passthrough (open-by-default at the route level).

### Evaluation

1. Check `_authenticated` first (short-circuit: returns `unauthenticated` immediately if anonymous).
2. Check `_session` (short-circuit: returns `forbidden` if session requirements not met).
3. Start with `AccessResult::allowed()`.
4. For each remaining requirement present (`_public`, `_permission`, `_role`, `_gate`), compute its result and combine via `andIf()`.
5. If no requirements found, return `AccessResult::neutral()`.
6. Return combined result.

## Permission Handler

**File:** `packages/access/src/PermissionHandler.php`
**Namespace:** `Waaseyaa\Access`

```php
final class PermissionHandler implements PermissionHandlerInterface
{
    public function registerPermission(string $id, string $title, string $description = ''): void
    public function getPermissions(): array // array<string, array{title: string, description: string}>
    public function hasPermission(string $permission): bool
}
```

Permissions are declared in `composer.json` under `extra.waaseyaa.permissions` and collected into the package manifest by `PackageManifestCompiler`.

```json
{
  "extra": {
    "waaseyaa": {
      "permissions": {
        "access content": { "title": "Access published content" },
        "create article": { "title": "Create Article content" }
      }
    }
  }
}
```

## Roles

**Files:** `packages/user/src/Role.php`, `packages/user/src/RoleRepository.php`
**Namespace:** `Waaseyaa\User`

A role groups a set of permissions under a single machine name. `Role` is a `final readonly` value object with four fields: `id` (machine name), `label` (human-readable), `permissions` (string[], the permissions the role grants), and `weight` (ordering). Roles are contributed by service providers implementing `Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface` and collected into `RoleRepository`, an id-keyed registry built via `RoleRepository::fromProviders($providers)` (later providers win on duplicate ids). See `docs/specs/package-discovery.md` for the discovery contract.

Two CLI commands attach roles to a user, and they differ in what they write:

- `user:role <user_id> <role>` only appends the role string to the user's `roles` array. Because `User::hasPermission()` reads the flat `permissions` array (and only the `administrator` role is special-cased), a non-administrator role added this way grants **no** permissions.
- `user:assign-role <user_id> <role> [--remove]` resolves the role from `RoleRepository` and recomputes the user's flat `permissions` as the **union** of the permissions of every registry-known role the user holds after the change, so multiple roles compose. Roles not present in the registry keep their string membership but contribute no permissions. `--remove` drops the role and recomputes the union without it.

## Enforcement Layers

Access enforcement runs at four distinct layers. Each layer is independent — the request must pass every applicable check.

| # | Layer | Site | Contract | Granularity |
|---|-------|------|----------|-------------|
| 1 | **Route** | `AccessChecker::check(Route, AccountInterface)` in `AuthorizationMiddleware` | Route options (`_public`, `_authenticated`, `_session`, `_permission`, `_role`, `_gate`) | Per HTTP request, before controller dispatch |
| 2 | **Entity (handler)** | `EntityAccessHandler::check(EntityInterface, $operation, AccountInterface)` invoked by controllers | `AccessPolicyInterface::access()` policies combined via `orIf()` | A single, already-loaded entity instance |
| 3 | **Entity (query) — NEW (mission `sql-entity-query-access-checking-01KRYP15`, #1495)** | `SqlEntityQuery::execute()` runs `EntityAccessHandler::check($entity, 'view', $account)` for every candidate row | Same `AccessPolicyInterface` pipeline as layer 2, applied per-row at query time | Cardinality and rows returned by entity queries (count + list) |
| 4 | **Field** | `EntityAccessHandler::filterFields(EntityInterface, fieldNames, $operation, AccountInterface)` invoked by `ResourceSerializer` | `FieldAccessPolicyInterface::fieldAccess()` policies | Individual fields on an entity — open-by-default (only `Forbidden` removes) |

Layer 2 (entity handler / controllers), Layer 3 (the entity query, since audit C-6), and the serializers are all **deny-by-default** enforcement points (`$result->isAllowed()`): they are wired with the composed `EntityAccessHandler` (all discovered policies) by `HttpKernel`, and they re-filter every result — both the page items and the totals. JSON:API uses `JsonApiController::accessFilteredTotal` for the total and `isAllowed()` for items; GraphQL uses `GraphQlAccessGuard::canView` for items and, since #1702 (audit C-7), `EntityResolver::resolveList` recomputes its `total` with that same `canView()` predicate across all matching rows (previously the GraphQL `total` was the open-by-default storage `COUNT`, leaking restricted-collection cardinality). The ai-vector `SearchController`, `GenericAdminSurfaceHost`, and the ai-tools entity tools likewise re-filter. Layer 4 (field) is **open-by-default** (`!$result->isForbidden()`).

Layer 3 (query) is, since audit **C-6**, **deny-by-default in its own right**. Its per-row survivor test is `isAllowed()` (`SqlEntityQuery::filterCandidates()`), so a `Neutral` result (no policy granted a view) drops the row exactly as `Forbidden` does — identical to Layer 2 and the entity gate, and to the `isAllowed()` re-filter every consumer applies. Production threads the composed access handler into `SqlEntityQuery` through `SqlEntityStorage::getQuery()` — `AbstractKernel` and `EntityStorageFactory` pass an `accessHandlerResolver` that supplies the kernel's discovered-policy handler at query time (issue #1714) — so the per-row check runs against the real policies. When no handler is wired (direct construction, or a resolver that returns null) `getQuery()` falls back to an empty handler whose every check is `Neutral`; under deny-by-default that **denies every candidate** — the query fails closed rather than passing an unfiltered candidate window through. The Layer-2/serializer re-filters described above are therefore now redundant belt-and-braces, not the sole enforcement point: a consumer that returns `getQuery()->execute()` (or its `count()`) without re-filtering no longer leaks `Neutral` rows or an inflated cardinality. The **system-context opt-out** (`accessCheck(false)`) remains the explicit, audited way to retrieve an unfiltered candidate window (background jobs, sitemap/URL enumeration, relationship-topology gathering); it skips Layer 3 entirely. See `docs/specs/field-access.md` for the field-level asymmetry (Layer 4 remains open-by-default).

### Layer 3 contract details

- **Default:** `SqlEntityQuery::accessCheck(true)` is the default state.
- **Account binding:** Call `$query->setAccount($account)` before `execute()` to bind the request's authenticated account. `EntityQueryInterface::setAccount(?AccountInterface): static` is required on every implementation.
- **Fail-closed:** When `accessCheck(true)` is active and no account is bound, `execute()` throws `Waaseyaa\EntityStorage\Exception\MissingQueryAccountException`. This is the v1 default — the query layer cannot silently leak rows.
- **System-context opt-out:** `$query->accessCheck(false)` preserves the pre-mission behaviour (no per-row filter, no account required). Every remaining call site is audited at [`docs/security/sql-entity-query-access-check-bypass-audit.md`](../security/sql-entity-query-access-check-bypass-audit.md); new bypasses MUST update that document.
- **Filter semantics:** Per-row, `EntityAccessHandler::check($entity, 'view', $account)` is consulted; `Allowed` + `Neutral` admit the row, `Forbidden` drops it. **In production the handler is empty** (see above — the storage is built without it), so every row is `Neutral` and the query is a pass-through candidate window. Even if a real handler were wired here, `Neutral` would still admit (this is open-by-default at the query layer by construction). The authoritative deny-by-default decision is the consumer's re-filter via `isAllowed()`.

The `view`-operation symmetry between layers 2 and 3 is deliberate: a row's visibility in a list and its visibility on a detail page are governed by the same policy code, so consumers cannot construct a query that returns rows they could not otherwise load individually.

- **Regression-pinned (audit C-6 — reclassified, accurate rationale):** the candidate-window invariant (`Neutral` admits a row) is locked by `packages/entity-storage/tests/Unit/SqlEntityQueryNeutralAdmitsRowTest.php`, and the production reality (storage built without a handler ⇒ access-checked queries are a pass-through) is pinned by the `productionStorageWithoutHandlerIsPassThrough` case in that same test. Audit C-6 recommended flipping the survivor test to `isAllowed()` (deny-by-default). That is **not a safe standalone change** for two reasons: (1) because production storage does not wire the handler (above), the empty-handler query would resolve every row to `Neutral` and `isAllowed()` would **drop all of them** — every access-checked list/count would return empty; and (2) it is **unnecessary** — deny-by-default is already enforced at the Layer-2/serializer layer for both items and totals, so the candidate-window pass-through leaks nothing through any production consumer. The earlier "would hide legitimately-visible `Neutral` rows for the genealogy pedigree/family services" rationale was **incorrect**: genealogy's authenticated edges resolve to `Allowed` (via `GenealogyRelationshipAccessPolicy` + `orIf`), and a flip would *empty* the query, not hide `Neutral` rows. A genuine query-layer deny-by-default would require **completing the WP03 wiring** (construct `SqlEntityStorage` with the composed handler in `EntityStorageFactory`/`AbstractKernel`) **and** adding allowing `view` policies for the Neutral-reliant entity types (`group`, `group_type`, `trace`, `classification_label_definition`, `retention_policy`, and the orphaned-parent `attachment` case) so admins/legitimate viewers do not lose them — tracked as future work, not a one-line flip.

<!-- Spec reviewed 2026-07-15: `group`/`group_type` deliberately opt in to JSON:API after the entity-type `api:` default changed to false. `GroupAccessPolicy` grants entity CRUD only to accounts with `administer groups` (#1871). This paragraph's query-layer Neutral-admits-a-row analysis is unaffected — it concerns list/count access-checked queries, not the single-entity CRUD policy. -->


## Authorization Pipeline

**Entry point:** `public/index.php`

Authorization participates in the shared HTTP middleware onion around real dispatch:

```
Request -> SessionMiddleware -> AuthorizationMiddleware -> Controller/domain-router dispatch -> Response
```

### SessionMiddleware

**File:** `packages/user/src/Middleware/SessionMiddleware.php`
**Namespace:** `Waaseyaa\User\Middleware`

```php
final class SessionMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $userRepository,
        private readonly ?AccountInterface $devFallback = null,
        ?LoggerInterface $logger = null,
        private readonly ?array $sessionCookieOptions = null,
        private readonly array $trustedProxies = [],
        private readonly ?AccountContextInterface $accountContext = null,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
}
```

Behavior:
1. Reads `$_SESSION['waaseyaa_uid']` (via `$request->attributes->get('_session')` or `$_SESSION`).
2. Loads User entity via `$this->userRepository->find($uid)`.
3. Falls back to `AnonymousUser` if: no UID in session, load fails, or loaded entity is not `AccountInterface`.
4. Sets `$request->attributes->set('_account', $account)`.
5. Mirrors the same account into the acting-account context (`$this->accountContext?->set($account)`) — unconditionally, on every request, including `AnonymousUser` (id 0). When `BearerAuthMiddleware` (higher priority) already resolved an authenticated `_account`, that account is mirrored instead. See "Acting-account context" below.
6. Calls `$next->handle($request)`.
7. Creates `NativeSession` with `$trustedProxies` so session cookie secure flag respects proxy trust.

**Trusted proxy guard:** Both `NativeSession::isSecureConnection()` and `SessionMiddleware::isHttpsRequest()` only trust `X-Forwarded-Proto` when `REMOTE_ADDR` matches a configured trusted proxy IP. The header comparison is case-insensitive (`HTTPS`, `Https`, `https` all match). Both methods return `false` early when `REMOTE_ADDR` is empty or missing, preventing accidental matches against empty-string entries in the trusted list. Without trusted proxies configured, the header is ignored. Only exact IP addresses are supported (no CIDR notation). Configure via `'trusted_proxies' => ['127.0.0.1']` in `config/waaseyaa.php`.

**Secure-by-default session cookies (audit C-8):** `SessionMiddleware::applySessionCookieIni()` applies hardened defaults on **every** request — `httponly=true`, `samesite='Lax'`, `use_strict_mode=true` (session-fixation protection), and `secure='auto'` (the Secure flag is set when the request is HTTPS-detected via the trusted-proxy guard above) — even with no `config['session']['cookie']` block configured. Any key supplied under `config['session']['cookie']` overrides the matching default (explicit config always wins). This closes the previous insecure-by-default gap where a stock install ran with PHP's bare cookie ini (no HttpOnly/SameSite/strict-mode).

Does not handle login/logout. Only resolves "who is making this request."

**Session lifecycle on login/logout (`AuthManager`).** `AuthManager::login()` calls `session_regenerate_id(true)` to defeat session fixation (a fresh id is issued the moment privileges change). Symmetrically, `AuthManager::logout()` clears **all** session data (`$_SESSION = []`, not just the uid) and then, when a session is active, rotates and destroys the underlying session (`session_regenerate_id(true)` + `session_destroy()`) so the pre-logout session id can never be reused. Both operations are guarded on `session_status() === PHP_SESSION_ACTIVE` because the session is started by the bootstrap, not by `AuthManager` (and is inactive in CLI/tests).

Lives in the `user` package because it depends on `User`, `AnonymousUser`, and entity storage.

### AuthorizationMiddleware

**File:** `packages/access/src/Middleware/AuthorizationMiddleware.php`
**Namespace:** `Waaseyaa\Access\Middleware`

```php
final class AuthorizationMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly AccessChecker $accessChecker,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
}
```

Behavior:
1. Reads matched `Route` from `$request->attributes->get('_route_object')`. If null, passes through.
2. Reads `AccountInterface` from `$request->attributes->get('_account')`. If missing/invalid, returns 403 JSON:API error.
3. Delegates to `$this->accessChecker->check($route, $account)`.
4. If Forbidden: returns 403 JSON:API response with `$result->reason`.
5. If Neutral (no requirements on route): passes through (open-by-default).
6. If Allowed: calls `$next->handle($request)`.

Requires SessionMiddleware to run first. Enforced by middleware priority ordering.

### 403 Response Format

```json
{
  "jsonapi": { "version": "1.1" },
  "errors": [{
    "status": "403",
    "title": "Forbidden",
    "detail": "The 'administer site' permission is required."
  }]
}
```

Content-Type: `application/vnd.api+json`.

## Acting-account context (`AccountContextInterface`)

**Files:** `packages/access/src/Context/AccountContextInterface.php`,
`packages/access/src/Context/RequestAccountContext.php`
**Namespace:** `Waaseyaa\Access\Context`
**Mission:** `revision-audit-provenance-01KTWY5V` (FR-002, #1644/#1645)

A request-scoped holder for the **acting account** — the account in whose
name the current operation runs. It exists so deep, non-HTTP-aware
subsystems (entity storage's `revision_author` recording, the audit
listeners' actor attribution) can read "who is acting" without the
`_account` request attribute being threaded through every call signature.

```php
interface AccountContextInterface
{
    /** The acting account, or null when no acting context exists. */
    public function current(): ?AccountInterface;

    /** Set (or clear with null) the acting account for the current request/run scope. */
    public function set(?AccountInterface $account): void;
}
```

### Three-state model

| State | `current()` | Meaning | Example contexts |
|---|---|---|---|
| Account N | account with `id() >= 1` (incl. `PHP_INT_MAX` dev fallback) | a real authenticated account is acting | web session, MCP bearer token, agent run initiator |
| Anonymous | account with `id() === 0` (`AnonymousUser`) | an anonymous web actor is acting — anonymous IS an actor | unauthenticated HTTP request |
| None | `null` | no acting context exists | CLI batch, queue worker, system bootstrap |

Consumers must never collapse the last two: `0` means "the anonymous account
did it", `null` means "nobody was in scope". No reader or writer may coerce
`null → 0`.

### Writer table (scoping discipline)

`RequestAccountContext` is a deliberately dumb holder — it stores exactly
what it is given. The set/restore discipline lives at the writer sites:

| Writer | Scope | Discipline |
|---|---|---|
| `SessionMiddleware` (`packages/user`) | per HTTP request | HTTP requests are the outermost scope: **unconditional overwrite** on every request, never restores. Mirrors whatever lands in `_account` (authenticated N, `AnonymousUser` 0, dev fallback, or a bearer-auth-resolved account from `BearerAuthMiddleware`) |
| `McpEndpoint` (`packages/mcp`) | per MCP request | sets the bearer-auth-resolved account after `authenticate()` succeeds; captures the prior value and **restores it in `finally`** (the MCP account deliberately differs from any session account) |
| `AgentExecutor` (`packages/ai-agent`) | per agent run | sets the run's `$initiatorAccount` for the duration of the run (including queue-driven runs with no HTTP request); **restores the prior value in `finally`** so a thrown run never leaks the initiator into the next job on a long-lived worker, and nested scopes unwind correctly |
| *(nothing)* | CLI / queue / bootstrap | nothing sets the context; readers get `null` |

### Single kernel-shared instance and resolution

The kernel constructs **exactly one** `RequestAccountContext` per process
(`AbstractKernel::accountContext()`, lazily) and serves that same instance on
every exposure path — a second construction site would silently fork the
context. Resolution paths:

- **Kernel-services bus** — service providers resolve
  `AccountContextInterface::class` via `resolveOptional()` /
  `safeResolve()` (this is how `AuditServiceProvider` injects it into the
  audit listeners and `AiAgentServiceProvider` into `AgentExecutor`).
  Bare-provider construction without a kernel resolves null; consumers then
  read null actors — the correct degraded behavior.
- **Handler container** — `AbstractKernel::buildHandlerContainer()` binds
  `AccountContextInterface::class` to the kernel instance for
  controller-side resolution (e.g. `McpServiceProvider`'s explicit
  `McpEndpoint` binding).
- **Repository factory** — the kernel attaches the instance to every
  `EntityRepository` it builds (`setAccountContext()`), where it is the
  ambient source for `revision_author` resolution.
- **HTTP middleware** — `HttpKernel` passes the instance to
  `SessionMiddleware`'s constructor.

### Readers and the `SaveContext::withActorUid()` override

- **Entity storage** (`packages/entity-storage`): `EntityRepository`
  resolves the revision author once per revision-creating operation as
  `SaveContext override → AccountContextInterface::current()?->id() → null`.
  `SaveContext::withActorUid(?int)` is the explicit per-save override and
  **wins over the ambient context** — including `withActorUid(null)`, which
  forces a NULL author inside an authenticated request (system-attributed
  maintenance writes). A context that never called `withActorUid()` defers
  to this holder. Full contract: `docs/specs/revision-system-unified.md` §4a.
- **Audit listeners** (`packages/audit`): `EntityLifecycleAuditListener`
  reads it as the sole actor source; `AgentToolAuditListener` and
  `PublishPointerAuditListener` use it as the fallback behind their events'
  carried actor. Full catalogue: `docs/specs/ocap-audit-log.md`.

There is no configuration surface: attribution has no opt-out switch by
design (nullable columns and additive events make recording non-breaking);
the per-save `withActorUid()` override is the only knob.

## CSRF Protection

**File:** `packages/user/src/Middleware/CsrfMiddleware.php`
**Namespace:** `Waaseyaa\User\Middleware`

`CsrfMiddleware` runs in the HTTP pipeline (priority 20) and enforces session-based CSRF protection for all state-changing requests (`POST`, `PUT`, `PATCH`, `DELETE`). On allowed requests it unwinds over the final dispatched response and attaches the readable token cookie to HTML.

### XSRF-TOKEN cookie

After passing a non-validating request through the pipeline, the middleware writes an `XSRF-TOKEN` cookie to `text/html` responses so JavaScript clients can read the current session token. Since the #2177 F1 prerequisite it also writes the same cookie (identical attributes) to **any** response — JSON included — whose request carries an authenticated `_account` and a non-empty `waaseyaa_uid` login-session marker (`attachCookieIfAuthenticated()`): the admin SPA boots against `GET /api/user/me` and never receives a kernel HTML response, so this session-authenticated path seeds its token. Anonymous and bearer-only non-HTML responses stay cookie-free. Cookie attributes:

| Attribute | Value |
|-----------|-------|
| Name | `XSRF-TOKEN` |
| Value | `rawurlencode($_SESSION['_csrf_token'])` |
| `Path` | `/` |
| `HttpOnly` | `false` (required — JS must be able to read it) |
| `SameSite` | `Lax` |
| `Domain` | not set |
| `Secure` | mirrors `$request->isSecure()` |
| Lifetime | session (no explicit `Expires`/`Max-Age`) |

Inertia consumers benefit automatically: axios reads the cookie and forwards its value as `X-XSRF-TOKEN` on subsequent mutation requests.

**Known gap:** `$request->isSecure()` reads raw `$_SERVER['HTTPS']` without trusted-proxy awareness. Behind a TLS terminator the `Secure` flag will not be set unless a trusted proxy is configured. Tracked at waaseyaa/framework#1394. See also: `SessionMiddleware` trusted-proxy contract above.

Cross-reference: `docs/conventions/csrf-token-cookie.md` for runnable integration examples.

### Token validation (any-of, state-changing requests only)

For state-changing requests the middleware accepts the session token from any of these sources, compared via `hash_equals`:

1. `_csrf_token` POST field — read as-is, no transform.
2. `X-CSRF-Token` request header — read as-is, no transform.
3. `X-XSRF-TOKEN` request header — URL-decoded once via `rawurldecode` before comparison (matches the URL-encoded value written to the cookie).

The first matching source short-circuits; all comparisons are constant-time.

### CSRF-exempt requests

Requests with a `Content-Type` of `application/json` or `application/vnd.api+json` are not validated **by default** (browsers cannot forge those content types from HTML forms). The `_csrf` route option overrides the default in either direction (#2177 F1 prerequisite):

- `_csrf: false` (`RouteBuilder::csrfExempt()`) — never validate; the route has its own authentication model.
- `_csrf: true` (`RouteBuilder::requireCsrf()`) — always validate on state-changing methods, **including** the JSON content types above. The exemption is unsound when the session cookie is the sole authenticator of a JSON endpoint (cross-origin `fetch` can send JSON and the browser attaches the cookie unless SameSite blocks it); the first consumer is the MCP write-tier approval controller. On an opted-in route, a missing/invalid token yields the standard JSON:API 403, which still re-delivers the XSRF-TOKEN cookie to an authenticated session so the client can retry.

## Discovery

Policies and permissions are discovered at build time via `PackageManifestCompiler`:

- **Policy discovery:** `#[AccessPolicy]` attribute is scanned during class scanning. Discovered policies stored as `array<string, string>` (entity type ID => FQCN) in the manifest.
- **Permission discovery:** `composer.json` `extra.waaseyaa.permissions` collected into `PackageManifest::$permissions`.

Layer discipline: Foundation (layer 0) uses string constants for attribute class names to avoid importing from higher layers. `ReflectionClass::getAttributes()` accepts string class names.

## User/Auth HTTP Surfaces (post-M10 package ownership)

**Packages:** `packages/auth/`, `packages/user/`
**Registered by:** package service providers discovered from composer metadata. `AuthServiceProvider` owns all auth-related request surfaces: login, logout, me, registration, password-reset, and email-verification controllers. These controllers are callable objects (implementing `__invoke(Request): JsonResponse`) registered via `RouteBuilder::controller()`.

### Endpoint Access Requirements

#### AuthServiceProvider-owned routes

| Endpoint | Route option | Controller |
|----------|-------------|------------|
| `POST /api/auth/login` | `_public: true` | `LoginController` |
| `POST /api/auth/logout` | `_public: true` | `LogoutController` |
| `GET /api/user/me` | `_public: true` | `MeController` |
| `POST /api/auth/register` | `_public: true` | `RegisterController` |
| `POST /api/auth/forgot-password` | `_public: true` | `ForgotPasswordController` |
| `POST /api/auth/reset-password` | `_public: true` | `ResetPasswordController` |
| `POST /api/auth/verify-email` | `_public: true` | `VerifyEmailController` |
| `POST /api/auth/resend-verification` | `_authenticated: true` | `ResendVerificationController` |

`ResendVerificationController` requires an active authenticated session. `AccessChecker` short-circuits with `unauthenticated` (401) if the `_account` attribute on the request is anonymous. The other seven endpoints are public — no session required. `LoginController` applies its own rate limiting (5 attempts per IP per 60s).

All auth controllers accept an optional `?LoggerInterface $logger` (defaults to `NullLogger`). DevLog-mode verification/reset URLs and best-effort email failures are logged via this interface rather than `error_log()`.

**Configuration resolution:** `UserServiceProvider` registers `AuthMailer` with `MailerInterface` (from `MailServiceProvider`), `authEmailConfigured` when trimmed `mail.sendgrid_api_key` and `mail.from_address` are both non-empty, Twig from `SsrServiceProvider::getTwigEnvironment()`, `baseUrl` in precedence order: `$config['app']['url']`, then `APP_URL`, then `http://localhost:8000`, and `appName`: `$config['app']['name']` → `APP_NAME` → `Waaseyaa`. When mail is not configured, `AuthMailer::isConfigured()` is false and auth email sends no-op without hitting the transport. Consumer apps that set neither app URL config nor env var still boot with localhost defaults adequate for dev and CI. Production should set `APP_URL` (via `.env` or `config/waaseyaa.php`) so reset and verification links use the correct hostname.

### Rate Limiting

All auth endpoints apply rate limiting via `RateLimiterInterface` keyed on IP or user identity. Two implementations exist: `RateLimiter` (in-memory, resets per process — marked `@internal`, see below) and `DatabaseRateLimiter` (DBAL-backed via `DatabaseInterface`, persists across restarts; driver-portable). `DatabaseRateLimiter` provisions its `rate_limits` table through the schema builder (TOCTOU-safe, like `AuthTokenRepository`) with non-reserved column names (`bucket_key`, `hits`, `reset_at` — never the reserved words `key`/`count`, which broke non-SQLite drivers), and increments atomically (`SET hits = hits + 1` via a quoted-identifier statement) so concurrent hits never undercount. `AuthServiceProvider` registers `DatabaseRateLimiter` by default, resolving `DatabaseInterface` from the container. `HttpKernel` also injects a `DatabaseRateLimiter` into `ControllerDispatcher` for the login endpoint. The in-memory `RateLimiter` is `@internal` (not part of the public surface): its per-instance state resets every request under the boot-per-request runtime, so it does **not** throttle across requests and must never be the bound limiter in production — it is retained only as a test / non-boot-per-request fallback when no `RateLimiterInterface` is injected:

| Endpoint | Limit |
|----------|-------|
| `POST /api/auth/register` | 5 per IP per 15 min |
| `POST /api/auth/forgot-password` | 3 per email per 15 min, 10 per IP per hour |
| `POST /api/auth/reset-password` | 10 per IP per hour |
| `POST /api/auth/verify-email` | 10 per IP per hour |
| `POST /api/auth/resend-verification` | 3 per user per hour |

Rate limit responses return 429 with a `Retry-After` header.

### Anti-Enumeration

All user-facing responses from `ForgotPasswordController` and `RegisterController` are generic — the system never reveals whether an account exists for a given email. Constant-time comparisons are used where needed to prevent timing side-channels.

### AuthTokenRepository

Replaces `PasswordResetTokenRepository` (which used raw PDO). Uses `DatabaseInterface` (DBAL). Tokens are 64-char hex strings hashed with HMAC-SHA256 using `auth.token_secret` from config. Plain tokens are never persisted.

**The token secret must be configured in real deployments.** `AuthServiceProvider` resolves the repository with `auth.token_secret` (falling back to `app_secret`). It never falls back to the literal `'change-me'` published in source — a known HMAC key makes reset/verify token hashes forgeable. If no real secret is configured, the provider **throws a clear `\RuntimeException` at resolution (boot)** in production/staging (any environment outside `local`/`dev`/`development`/`testing`), failing loudly rather than shipping a forgeable default. In dev/test only, it synthesises an ephemeral random secret so a misconfigured non-production app still boots; that secret is per-process (per-request under the boot-per-request runtime), so reset/verify token flows that must survive a reboot still require `AUTH_TOKEN_SECRET` to be set.

**Schema bootstrap — idempotent and race-safe.** `ensureSchema()` provisions the `auth_tokens` table and is resolved on the **request hot path** (`AuthServiceProvider` registers it during route registration). Under FrankenPHP classic `php-server` (the `composer run dev` runtime) the kernel boots afresh per request across many worker threads, so `ensureSchema()` runs on every request and can run concurrently on a cold DB. It therefore keeps an existence guard *and* tolerates a concurrent create: if `createTable()` throws (the race-loser's "table auth_tokens already exists"), it rethrows only when a fresh re-check shows the table genuinely absent. A bare `CREATE TABLE` behind a non-atomic check is a TOCTOU bug that 500s `/api/broadcast` (alpha.238). The cleanup-backlog (CL-11) tracks moving this provisioning off the request path into `db:init`/`migrate`; see `docs/specs/operations-playbooks.md` "Two runtimes, two launchers" for the per-request-boot invariant.

**Token types and default TTLs:**

| Type | Default TTL | Notes |
|------|-------------|-------|
| `password_reset` | 1 hour | Single-use; revokes previous tokens for same user |
| `email_verification` | 24 hours | Single-use; revokes previous tokens for same user |
| `invite` | 7 days | Single-use; `user_id` is NULL |

### Auth Configuration

Registered under `auth` key in `config/waaseyaa.php`:

```php
'auth' => [
    'registration' => 'admin',        // 'admin' | 'open' | 'invite'
    'require_verified_email' => false, // true = block unverified users from AdminShell
    'mail_missing_policy' => null,     // null = auto (dev-log in dev, fail in prod)
    'token_secret' => env('AUTH_TOKEN_SECRET', ''),
    'token_ttl' => [
        'password_reset' => 3600,
        'email_verification' => 86400,
        'invite' => 604800,
    ],
],
```

`mail_missing_policy` auto-resolves: `dev-log` when `APP_ENV` is `local`/`development`; `fail` in production. Explicit values `'dev-log'`, `'fail'`, and `'silent'` override the auto behavior.

## File Reference

```
packages/access/src/
    AccessPolicyInterface.php        - Entity access policy contract
    FieldAccessPolicyInterface.php   - Field access policy contract (see field-access.md)
    AccessResult.php                 - Tri-state value object (Allowed/Neutral/Forbidden)
    AccessStatus.php                 - Enum: ALLOWED, NEUTRAL, FORBIDDEN
    EntityAccessHandler.php          - Orchestrates policy evaluation
    AccountInterface.php             - User account contract (id, permissions, roles)
    PermissionHandler.php            - In-memory permission registry
    PermissionHandlerInterface.php   - Permission registry contract
    Attribute/
        AccessPolicy.php             - Plugin discovery attribute
    Context/
        AccountContextInterface.php  - Request-scoped acting-account holder contract (see "Acting-account context")
        RequestAccountContext.php    - Default mutable holder (one instance per kernel)
    Gate/
        GateInterface.php            - Gate contract (allows/denies/authorize)
        Gate.php                     - Gate implementation with policy resolution
        EntityAccessGate.php         - Adapter bridging GateInterface to EntityAccessHandler
        PolicyAttribute.php          - Maps policy class to entity type
        AccessDeniedException.php    - Thrown by Gate::authorize()
    AccessChecker.php                - Route option access checks (_public, _authenticated, _session, _permission, _role, _gate)
    RedirectValidator.php            - Open-redirect prevention (isSafe/sanitize)
    ErrorPageRendererInterface.php   - Error page rendering contract (render -> ?Response) [@internal — not a public consumer contract]
    Middleware/
        AuthorizationMiddleware.php  - Route-level access enforcement

packages/user/src/
    Middleware/
        SessionMiddleware.php        - Resolves AccountInterface from session
        BearerAuthMiddleware.php     - JWT and API key authentication via Bearer tokens (priority: 40)

public/index.php                     - Front controller; wires the pipeline
```

---

## Parent-Delegated Policies

Added in mission `single-entity-work-surface-01KQ7M1P`. A **parent-delegated access policy** delegates access decisions for a child entity to the policy registered for its parent entity.

### Pattern

```php
#[PolicyAttribute('attachment')]
final class ParentDelegatedAccessPolicy implements AccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        $parentType = (string) $entity->get('parent_entity_type');
        $parentId = (string) $entity->get('parent_entity_id');

        if ($parentType === '' || $parentId === '') {
            return AccessResult::neutral('Attachment has no parent entity reference.');
        }

        $parent = $this->entityTypeManager->getStorage($parentType)->load($parentId);
        if ($parent === null) {
            return AccessResult::neutral('Parent entity not found.');
        }

        return $this->accessHandler->check($parent, $operation, $account);
    }
}
```

### Semantics

- `AccessResult::neutral()` (not `forbidden()`) is returned when the parent cannot be resolved. Under entity-level `isAllowed()` semantics, neutral effectively denies access without encoding an explicit Forbidden decision. This is intentional — orphaned child entities must not silently become accessible.
- `createAccess()` is not delegated by this policy — create access for child entities is governed at the API layer (e.g., require `update` on the parent before allowing attachment creation).
- The policy auto-discovers its entity type via `#[PolicyAttribute('attachment')]`.

### Canonical implementation

`Waaseyaa\Attachment\Policy\ParentDelegatedAccessPolicy` in `packages/attachment/src/Policy/`.

→ See `docs/specs/work-surface.md` F4 for the attachment wire-up.
→ See `docs/specs/field-access.md` for field-level access semantics (open-by-default).

## Per-revision access (mission `entity-storage-v2-01KRCDDC`)

When an entity type opts into revisions (`EntityType::isRevisionable() === true`), the gate gains a `view_revision` operation:

- `GateInterface::VIEW_REVISION = 'view_revision'`.
- `PolicyAttribute` accepts an `operations: array` parameter. A policy that declares `view_revision` must implement `viewRevision(EntityInterface $entity, AccountInterface $account, RevisionMetadata $revision): AccessResult`. Missing the method while declaring the op fails at boot.
- `Waaseyaa\Access\Gate\RevisionAccessRouter` resolves the policy for the entity type. If the policy declares `view_revision`, it calls `viewRevision()`. Otherwise it falls back to `view()` and emits a structured log line on the `entity.lifecycle` channel with `outcome=view_revision_fallback` (context: entity_type_id, entity_id, vid, policy_fqcn). **Open-by-default**: absence of an explicit rule does NOT flip to deny — the fallback returns whatever `view()` returned.

Canonical sources: `kitty-specs/entity-storage-v2-01KRCDDC/contracts/revisionable-entity.md` §11.2; mission spec §3.6; `docs/specs/entity-system.md` "Field storage backends" → "Per-revision access fallback rule".

→ See `docs/upgrades/waaseyaa-alpha-X-to-Y.md` for the `view_revision` policy template and migration steps.

## Two-factor authentication

When a user enables 2FA, `LoginController` short-circuits after password verification: instead of issuing a session token, it sets `$_SESSION['waaseyaa_pending_2fa_uid']` to the user's UID and returns `{ data: { type: 'auth', attributes: { state: '2fa_required', pending_user_id: <uid> } } }`. The client must follow up with `POST /api/auth/2fa/verify` carrying a TOTP code or recovery code. On success, `VerifyTwoFactorController` promotes the pending key to a full `waaseyaa_uid` session and regenerates the session id.

Surface:

- `Waaseyaa\Auth\TwoFactorService` — orchestrator. `setup(User)`, `enable(User, secret, plaintextCodes, firstCode)`, `verify(User, code)`, `disable(User)`, `isEnabled(User)`. All persistence goes through `EntityTypeManagerInterface`.
- `Waaseyaa\Auth\TwoFactorManager` — primitive layer (RFC 6238 TOTP verification + recovery-code *generation*). Recovery-code **verification** is not done here: codes are stored Argon2id-hashed and verified via `password_verify` in `TwoFactorService`. (The former plaintext-compare `verifyRecoveryCode()` helper was removed as a dead, misleading footgun; the TOTP `getCurrentCode()` helper is `@internal`, test/diagnostic only.)
- `Waaseyaa\Auth\TwoFactorSetupResult` — readonly value object carrying secret + QR URI + plaintext recovery codes for one-time display.
- Controllers: `SetupTwoFactorController`, `EnableTwoFactorController`, `VerifyTwoFactorController`, `DisableTwoFactorController` (`packages/auth/src/Controller/`).
- Routes registered in `Waaseyaa\Routing\AuthOidcRouteServiceProvider`:
  - `POST /api/auth/2fa/setup` — initiates setup, returns secret+QR+recovery codes; does NOT persist.
  - `POST /api/auth/2fa/enable` — verifies first TOTP, persists Argon2id-hashed recovery codes.
  - `POST /api/auth/2fa/verify` — accepts TOTP OR recovery code; rate-limited 5/IP/60s under `2fa-verify:` namespace.
  - `POST /api/auth/2fa/disable` — requires valid code as proof-of-possession; wipes secret + codes atomically.

Storage: two `#[Field]` properties on `User` — `two_factor_secret` (Base32 string, nullable) and `two_factor_recovery_codes_hash` (list of Argon2id hashes, nullable). Both live in the entity's `_data` JSON blob; no schema migration required.

Full contract: `docs/specs/two-factor-auth.md`.

## Implementation gotchas

- **Fail-closed status defaults in view-access checks**: when the `view` branch turns on a publication-status field, an absent/undeterminable status MUST resolve to unpublished, not published — whether the status is read inline in the policy (`TermAccessPolicy::checkViewAccess()` uses `$entity->get('status') ?? false`) or via the entity's own accessor (`NodeAccessPolicy` delegates to `Node::isPublished()`, which is fail-closed at the entity layer: `(int) ($this->get('status') ?? 0) === 1`). A published-by-default fallback (`?? true`) silently grants public view to any entity the policy is handed that cannot yield a status — a hydration gap, a stub, or a future entity type that doesn't carry the field. A published-by-default fallback silently grants public view to any entity the policy is handed that cannot yield a status — a hydration gap, a stub, or a future entity type that doesn't carry the field. This is a policy-side hardening only: an entity class's own constructor default (e.g. `Term` seeds `status = true`, i.e. published-by-default as a product decision) is a separate, unrelated concern and is not implicated. `TermAccessPolicy::checkViewAccess()` was fixed to this pattern (audit-remediation batch 2026-07-02, WP4); `EngagementAccessPolicy`'s anonymous-ownership fail-open (WP1, same batch) is the sibling class of bug for owner checks rather than status checks.
- **Avoid double `$storage->create()` in access checks**: When checking field access before persisting a new entity, create once and reuse for both the access check and the save. Don't create a throwaway temp entity.
- **`discoverAccessPolicies()` constructor heuristic**: `ConfigEntityAccessPolicy` takes `array $entityTypeIds` as a required constructor parameter (from `#[PolicyAttribute]`). The reflection-based heuristic in `AbstractKernel::discoverAccessPolicies()` that passes entity types to constructors with required params exists for this reason — do not remove it.

## Container-resolved policy instantiation (M-B, v0.1.0-alpha.187+)

Added in mission `access-fail-closed-completeness-01KS3RJT` (closes #1519). Replaces the
previous silent-skip heuristic in `AccessPolicyRegistry` with container-resolved instantiation
for every discovered policy class.

### PolicyDependencyResolverInterface

**File:** `packages/foundation/src/Kernel/Bootstrap/PolicyDependencyResolverInterface.php`
**Namespace:** `Waaseyaa\Foundation\Kernel\Bootstrap`

```php
interface PolicyDependencyResolverInterface
{
    /**
     * Instantiate a policy class, resolving its constructor dependencies from
     * the container. Throws PolicyInstantiationException if the class cannot
     * be resolved.
     *
     * @param class-string<AccessPolicyInterface> $fqcn
     */
    public function resolve(string $fqcn): AccessPolicyInterface;
}
```

### Two-phase resolution algorithm

When `AccessPolicyRegistry` builds the `EntityAccessHandler`, each policy FQCN goes through a
5-rule resolution cascade:

1. **Container binding** — if the DI container has an explicit binding for `$fqcn`, use it.
2. **Auto-wire** — inspect the constructor via `ReflectionClass`; resolve each parameter from
   the container by type-hint. Primitives and unresolvable params use their default values if
   declared, otherwise fail.
3. **No-arg constructor** — if the class has no constructor or a zero-arg constructor, call
   `new $fqcn()`.
4. **`EntityAccessHandler` forward-reference** — for policies that accept an `EntityAccessHandler`
   (e.g. `ParentDelegatedAccessPolicy`-style policies needing the handler itself for recursive
   delegation), the handler being assembled is injected. This breaks the forward-reference
   cycle without a separate service-locator step.
5. **Fail-closed** — if none of the above succeeds, throw `PolicyInstantiationException` with
   the FQCN and the underlying cause. The kernel does **not** log-and-skip; a single
   unresolvable policy is a hard boot failure. This is intentional: silent skips cause
   undetected permission holes at runtime.

### Writing a new policy with injected dependencies

No manual `ServiceProvider::boot()` wiring needed. Declare dependencies in the constructor:

```php
#[PolicyAttribute(entityType: 'node')]
final class NodeAccessPolicy implements AccessPolicyInterface
{
    public function __construct(
        private readonly NodeTypeRepository $nodeTypes,
        private readonly PermissionHandlerInterface $permissions,
    ) {}
    // ...
}
```

`AccessPolicyRegistry` will auto-wire `NodeTypeRepository` and `PermissionHandlerInterface`
from the container when the kernel boots. Register those services in any package's
`ServiceProvider::register()` as usual.

### CI gate: unbound getQuery() check

`bin/check-getquery-bindings` (added in #1528, part of `composer verify`) fails on new
`getQuery()->...->execute()` callsites that have neither `->setAccount()` nor
`->accessCheck(false)` in the call chain. Pre-existing exemptions are listed in
`tools/getquery-bindings-baseline.txt`; every entry must carry an inline comment. Driving the
baseline to zero is tracked in M-B.1 (see *Known limitations* below).

## Known limitations

### Anonymous semantic-search calls (SearchController)

`SearchController` currently falls through to `accessCheck(false)` when `$account` is null
(anonymous caller). This was flagged during the WP01 review for mission
`access-fail-closed-completeness-01KS3RJT` as a pre-existing fail-open posture: anonymous
users can trigger entity-query execution with system-context bypass, bypassing per-row view
policies. Fixing this is out of M-B scope (it requires the search subsystem to guarantee an
account is always present by the time queries execute).

**TODO (M-B.1):** Audit the `SearchController` anonymous path. Either bind `AnonymousUser`
so per-row policies can run, or add an explicit `_authenticated` route option to block
unauthenticated calls. Track via the M-B.1 follow-up issue
(`M-B.1: drive getquery-bindings-baseline.txt to zero`).

<!-- Spec reviewed 2026-05-17 - dead-code baseline reduction (#1493 / PR TBD): @api PHPDoc sweep on extension-point classes + WaaseyaaEntrypointProvider extended to recognize EntityBase/ContentEntityBase subclasses and their traits. No behavioural change. -->

<!-- Spec reviewed 2026-05-17 - dead-code Phase 3 Bucket 4: @api PHPDoc sweep on additional public-API classes. No behavioural change. -->

<!-- Spec reviewed 2026-05-18 - WP07 (agent-executor mission) rebase + rewire: no behavioural change to this subsystem; touch refreshes drift-detector timestamp. -->
