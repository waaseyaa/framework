# Entity Field-Read Boundary

<!-- Spec reviewed 2026-07-19 - #2064 alpha.269 closes the post-activation mutable-account convergence class: request composition passes the validated immutable principal to every entity/field access decision; EntityAccessHandler and policy PHPDoc narrow decision accounts to AuthorizationPrincipalInterface under PHPStan; and EntityAccessHandler centrally rejects a live entity-backed account before any policy can call hasPermission()/getRoles(). An architecture test pins the static and runtime gates. This does not unseal a field or create a missing-context fallback. -->

**Status:** WP4 activated: sealed entity reads, persistence gateways, production preflight, and payload boundaries are enforced without compatibility shims.
**Anchor:** GitHub #2064, with approved Design Revision 6 controlling.

## WP1 contract

WP1 introduces metadata and boundary types without changing runtime behavior.
`FieldReadLevel` defines `public`, `protected`, and `internal` wire values.
`FieldDefinition` implements the additive `FieldReadDefinitionInterface`; the
stable `FieldDefinitionInterface` is unchanged, and `#[Field]` gains an optional
`read:` argument. `FieldReadMetadataResolver` resolves explicit companion
metadata, legacy `settings.internal`, and site-artifact metadata, rejects
conflicts, and reports unclassified definitions. During dormant stages only,
unclassified definitions remain compatibility-Public. The resolver is not wired
into entity accessors or boot in WP1.

`EntityStructure` is immutable and contains only structural selectors needed for
non-recursive definition lookup: entity type, bundle, persisted id, uuid,
language/translation ids, revision selectors, and definition presence. It does
not contain labels or other content values. Attachment to hydrated entities and
post-construction mutation rules belong to WP2.

## Account authorization contracts

`AuthorizationPrincipalInterface` snapshots account id, authentication state,
roles, permissions, claims generation, and tenant/community binding without an
entity value bag. `AccountPrincipalFactoryInterface` is the future closed
bootstrap seam. `ProtectedFieldReadPolicyInterface` receives the principal,
`EntityStructure`, and a `PolicySubjectViewInterface` restricted to compiled
authorization inputs. It is separate from the stable, open-by-default
`FieldAccessPolicyInterface`; after activation only an explicit Allowed decision
will release a Protected value.

`AccountFieldReadScopeInterface` carries only account authority. Its default
implementation is nested, fiber-local, non-inheriting across child fibers, and
restores the prior principal in `finally`. WP1 does not install this scope in
HTTP, CLI, queues, or any accessor.

## Explicit capability and audit contracts

There is no ambient privileged scope and no callback bypass. Reviewed
`CapabilityDeclaration` objects name issuer, closed reason, exact entity/field
and bundle sets, tenant/community, allowed actor semantics, maximum TTL, and
justification. Query fields additionally name operations from
`QueryFieldOperation`; value-read and query-read capabilities are distinct,
empty, opaque, non-serializable object identities. `CapabilityIssueContext`
binds issuance to an execution boundary, explicit account/anonymous/system/no-
acting-context attribution, tenant/community, expiry, and classification/policy
generations. `CapabilityRegistryInterface` is the kernel-owned issuance and
execution-boundary revocation contract. Issuance and every authorization use
require the same live `CapabilityExecutionBoundary` proof; its correlation id
is metadata, while registry-owned object identity is the non-forgeable
credential. Revoking the proof invalidates the boundary and every capability
issued into it. Authority exists only as registry-owned `WeakMap` membership; a
caller may construct a handle or boundary-shaped object but cannot reconstruct
or forge membership.

`StrictPrivilegedReadLedgerInterface::reserve()` synchronously records a
`PrivilegedReadDescriptor` before a future closed reader obtains a value, then
`finalize()` records success or failure. The descriptor has no value property;
it distinguishes value and query reads and contains reason, issuer, explicit
actor semantics, structural subject identity, exact bundles and fields,
tenant/community, query fingerprint and operations where applicable,
classification/policy generations, correlation, and call-site metadata. Field
values, predicate values, and result values have no representation in the
contract. This strict contract is separate from the existing best-effort
`AuditWriterInterface`.

## Storage transition contracts

`EntityStorageDriverV2Interface` and `RevisionableStorageDriverV2Interface` are
additive opaque SPIs matching the current ordinary and revision/langcode driver
surfaces. A composition-root `StorageBoundary` binds each row and snapshot to one
repository/driver object identity and issues four role-separated collaborators:
driver row factory, repository row reader, repository snapshot factory, and
driver snapshot reader. An unrelated role cannot unwrap an object, and the role
injected into a driver can construct rows and consume snapshots but cannot
hydrate row value arrays. The SPIs return opaque, non-serializable
`StorageRow`/`StorageRowSet` objects and accept an opaque `StorageSnapshot` for
writes.

`LegacyStorageDriverAdapter` receives only the two driver roles, wraps V1 raw
rows in boundary-bound V2 objects, and requires an
`entity.deprecation` emitter at construction. It is dormant and repository-only;
WP1 does not route any existing repository or V1 caller through it. V1 removal,
first-party driver conversion, and raw-row boundary gates follow Revisions 2 and
3; activation removes V1 and the adapter in the same no-shim PR.

## Preflight, serialization, and performance

`FieldAccessPreflightData` and `FieldAccessPreflightResult` are checksum-bound,
machine-readable data/result skeletons only. Readiness requires no metadata
conflicts and exact empty inventories for unclassified entries, V1 drivers,
serialized entities, and legacy payloads. WP1 adds no command, bootstrap, boot
guard, or content scanner. `EntitySerializationForbidden` is a future exception
type only; `EntityBase` serialization and queue/cache/state behavior remain
unchanged.

`benchmarks/field-read.php` records an unbooted public-read baseline and names the
required diagnostic fixtures: booted class/bundle definitions, translations,
revisions, config and audit read models, principal creation, cold/warm Protected
reads, strict audited reads, and 50-field projection. Its warm-Public 1.25 ratio
and warm-Protected 2.0 ratio are synthetic microbenchmark diagnostics, with
peak-memory/allocation results reported per fixture; they do not decide
activation readiness. The diagnostic report carries those reference ratios but
no pass/fail result.

Activation performance is decided only by the frozen real-page harness. It
compares the WP2 baseline tree with the activation candidate across 20 fresh
randomized paired process blocks, after 30 warmups and with 200 timed samples
per page and tree in each block.
The timed boundary includes fresh `HttpKernel` construction and `handle()` for
every request, matching the production front controller rather than shifting
kernel boot cost outside the measurement. If exact WP2 cannot execute that
lifecycle, the declared baseline is a clean benchmark-only WP2 parity commit
containing only the identical Twig lifecycle fix present in the candidate; no
field-read implementation may enter that parity commit. This keeps both trees
on the same executable lifecycle while isolating the field-read cost.
For every paired block, the harness calculates the candidate/baseline request-
median ratio and request-median delta. With a checked-in MT19937 seed it
resamples those 20 paired observations with replacement 100,000 times, takes
the median ratio and median delta of each resample, and uses the nearest-rank
one-sided 95th percentile as each upper confidence bound. Both the cache-cold
content page and the cache-cold members-directory page must have a bootstrap
paired-median ratio upper bound no greater than 1.03 **and** stay within the
bootstrap paired-median absolute upper bound
`max(0.50 ms, 0.05 ms × hydrated entity count)` for that page. The content-
scaled absolute budget prevents a large entity page
from failing on an immaterial fixed per-entity cost while the independent ratio
gate still bounds total-page impact. The hydrated-entity count is a frozen
workload attribute in each page trace (one entity for content; the authenticated
session User plus the rendered Users for the directory), not a candidate-side
observation. A missing, non-positive, cross-tree-mismatched, or cross-block-
changing count makes the result non-comparable. Response bodies, execution
traces, workload hashes, fixture manifests, PHP binaries/configuration, and
extensions must also match before timings are comparable. Each measured page
uses a project-and-page-private PHP session namespace. Page traces bind the
expected authorization mode, per-request kernel lifecycle, and privileged-read
ledger row count, so retained session/account state, authorization-ledger drift,
or a warmed framework kernel makes a block non-comparable instead of becoming
timing noise. Reports retain every paired ratio/delta, paired p95/max, and the
pooled request-sample p95/max for each tree. They also surface a separate raw-
tail warning when request maxima exceed either unchanged budget in at least 15
of 20 blocks, even when the bootstrap gate passes; a single noisy maximum is
never promoted into that consistency signal. The cache-hit content page and
the synthetic field-read
microbenchmarks are diagnostic only and cannot rescue a cache-cold failure.

## Explicit non-effects in WP1

- No existing `get()`, label/key helper, `toArray()`, translation, revision, query,
  repository, driver, PHP serialization, cache, state, queue, or boot path throws
  or changes result.
- No first-party field is classified yet, including User identity fields.
- No capability issuer, strict-ledger implementation, privileged reader,
  persistence extractor, query compiler enforcement, or preflight CLI ships.
- Existing output-surface field filtering remains unchanged.
- Direct process-memory inspection, Reflection, and debugger extensions remain
  outside the supported boundary.

WP2 supplies closed primitives and propagation. WP3 completes first-party
classification/convergence, live preflight, consumer fixtures, and performance
gates while still dormant. WP4 is the single no-shim activation PR.

## WP2 closed primitive tranche

The repository composition root now gives ordinary and revision/langcode
storage one opaque V2 boundary. First-party SQL, in-memory, and revision
backends cross that boundary only through role-bound rows and snapshots; a
consumer-extension V2 fixture proves the additive SPI, while legacy ordinary
V1 drivers remain repository-adapted with a deduplicated deprecation signal
until WP4 removes V1 and the adapter together.

`EntityStructure` is attached to framework entities during creation/hydration
and direct-constructor bootstrap. Persisted id and revision backfills replace
only their immutable structural snapshot through repository-owned hydration
hooks; duplication retains the same immutable selectors. Persistence obtains
the storage-canonical bag after lifecycle callbacks only through a private,
non-exported closure identity retained by `EntityRepository`. Framework
entities expose no companion raw-bag method; legacy third-party entities retain
a deduplicated `toArray()` compatibility fallback inside the same private
repository method. Repository base, bundle, translation, revision, and
backfill writes then cross driver SPIs as opaque snapshots. A semantic
architecture gate keeps persistence authority private, rejects public
raw-extractor companions, and inventories
every remaining direct value-bag and entity-array reader with a non-empty
rationale.

Structural hydration covers active/default/known translation ids and revision
id/tip/default flags. Repository translation/revision loads and translation
mutations replace only those immutable selectors; historical revisions cannot
retain the tip/default flags from a base-row prototype. A disciplined
revision-only save stamps its in-memory forward draft with `revisionTip=true`
because it is the latest revision, and `defaultRevision=false` because the
served base/default pointer did not move; ordinary and initial revision saves
stamp both flags true explicitly.

`AuditedFieldRead` validates an exact registered declaration and reserves one
strict-ledger descriptor before obtaining any value in an explicit related
field set, finalizing either success or failure. After reservation, first-party
values are obtained through a reader-private, non-exported `EntityBase` closure
that remains valid when the ordinary accessor guard activates; a forged handle
or ordinary accessor call never reaches that closure. Closed credential, session,
and identity readers constrain this primitive to their declared reasons. The
identity reader issues and revokes a fresh capability around every immutable
principal snapshot; HTTP installs that principal after bearer/session identity
resolution and before route authorization, restores the fiber-local scope in
`finally`, and reinstalls it only while a deferred streamed response executes.
Tenant/community values resolved by the request are bound through explicit
declaration flags; a declaration with fixed scope cannot also request dynamic
binding.

`DatabaseStrictPrivilegedReadLedger` stores reservation and finalization as
immutable events. Reservation is synchronous; finalization validates and
appends inside a database transaction, and a unique receipt/event invariant
prevents conflicting outcomes. A caller's enclosing persistence transaction
therefore includes both events, while an unfinished pure-read reservation stays
visible. Descriptor JSON contains only names and scope metadata, never values.

`QueryFieldReadRequest` and `AuditedQueryFieldRead` provide the dormant compiler
boundary for exact non-public query fields/operations. They validate the
distinct query capability and reserve a fingerprint-only descriptor before a
future executor runs; enforcement is not connected to entity queries until
activation. `AuditReadModelDefinitionRegistry` exactly classifies every column
of the deliberately unregistered flat audit tables.

`QueueEnvelopeV1` represents exactly one actor authority (actor id plus claims
generation) or one system authority (closed reason plus service identity), with
tenant/community and correlation dimensions. Persistent dispatch signs that
envelope only when the composition root supplies an explicit reviewed factory;
generic dispatch cannot acquire system authority by omission. The dormant
compatibility default retains the signed legacy message, installs no authority,
and emits a deduplicated diagnostic. The worker exposes a resolver-owned,
closeable authority scope for the handler only and guarantees cleanup before
acknowledgement, release, failure persistence, or the next job. CLI and API
persistent retry preserve the exact signed envelope and queue.

`QueueServiceProvider` obtains both the envelope factory and authority runtime
only from the kernel-services bus. A host may supply a reviewed
`QueueEnvelopeFactoryInterface` and `QueueAuthorityRuntimeInterface`; no
first-party actor/system resolver is inferred from session state or message
contents. The actor envelope intentionally carries no roles or permissions and
the system envelope carries no field-capability declaration, while the queue
package has no authoritative account-generation resolver; those inputs are
therefore insufficient for a first-party runtime to install fresh field-read
authority without inventing it. In their absence dispatch stays
legacy/non-authorizing and workers use `NoAuthorityQueueRuntime`. Provider-level
tests pin both the injectable production seam and this closed default. Generic
dispatch rejects a caller-created `QueueEnvelopeV1` before serialization or
transport access, so only the reviewed factory can create a newly persisted
authority envelope; exact signed-payload replay remains the retry-only
preservation path.

CLI declarations carry a closed CLI-valid reason (`MaintenanceCli`,
`AdminTooling`, `CredentialVerification`, or `StrictAuditProjection`), while
migration declarations compile to `MigrationImport`; all use `NoActingContext`
semantics and a null audit actor. `MigrationAuditedFieldReader` contains the explicit
`AuditedFieldRead::read(...)` call site; imports gain no read authority merely by
writing. `ProtectedCacheDimensions` includes principal/claims, tenant/community,
classification/policy generations, bundle, language, and revision.
`PublicStateProjection` explicitly carries Public values only. Queue/cache/state
write-boundary diagnostics are deduplicated and preserve dormant behavior.
HTTP cache bins and `CacheFactory` receive the cache diagnostic in production;
the repository has no state composition root, so `MemoryState`/`SqlState`
accept the state diagnostic at their real constructor/write boundary without
inventing a host binding. Hard rejection remains WP4.

WP2 does not convert all credential and identity call sites. The production
HTTP principal bootstrap uses `IdentityBootstrapReader`, but direct reads in
`User` helpers, mail delivery, authentication controllers/services,
notifications, and CLI handlers remain an explicit WP3 convergence inventory;
`CredentialBootstrapReader` is not yet a production authentication call site.
WP3 must convert and preflight those consumers before WP4 activation.
Accessor/query enforcement and hard cache/state/entity-serialization rejection
also remain later work.

## WP3 classification and preflight tranche

WP3 classifies first-party definitions while ordinary entity access remains
dormant. Structural id, uuid, bundle, language, and revision selectors are
Public. General published content/navigation/taxonomy fields are Public;
fields whose release depends on the viewing account are Protected; values that
exist only for credential, authorization, administration, storage, or audit
work are Internal.

| First-party area | Public | Protected | Internal |
|---|---|---|---|
| User | uid, uuid | name; status (self/admin direct read; exact entity/name authorization input) | mail, pass, roles, permissions, created, email verification, all two-factor state |
| Content/navigation | published node/note/taxonomy/path/menu fields and chronology | node author/workflow state | none |
| Identity-bearing collaboration | structural selectors | messaging bodies/participants, engagement actors/targets, relationships, trails | participant role |
| Genealogy | structural selectors | names, dates, living state, tree ownership and publication state | deletion tombstones |
| Files/media | structural selectors and published media chronology | attachment names/type/size/parentage, media owner/source | storage URI and checksum |
| OIDC | client_id and structural selectors | administrative display name | redirect URIs, scopes, grants, confidentiality and secret hash |
| Classification/retention | label vocabulary | none | retention rules and exemptions |

The semantic architecture inventory resolves names before checking
classifications. It covers aliased and fully-qualified `FieldDefinition`
construction, `#[Field]`, `#[FieldTemplate]`, and literal imperative
`_fieldDefinitions`; a named null is unclassified. Normalizers preserve a
non-null level rather than moving it into arbitrary settings.

User identity/PII classification is explicit: `uid` and `uuid` are structural
Public selectors; `name` is Protected profile identity; `status` is Protected
and marked `authorizationInput` so only the exact non-recursive User entity/name
policy map may inspect it. Direct status reads are self/admin only. Inactive
profiles and names are administrator-only; active profiles still require
`access user profiles`. `mail`, password material, roles, permissions,
email-verification state, and all two-factor state remain Internal and are never
released by an account policy. `created` is Internal audited-administration
chronology rather than public profile content.

`UserAccessPolicy`, already present in the package's authoritative discovered
policy manifest, implements the additive `ProtectedReadPolicyProviderInterface`
and exposes the separate V2 entity and field policies. This keeps discovery
single-sourced while avoiding the incompatible legacy/V2 `access()` signatures;
the WP4 evaluator consumes the V2 companions and does not pass a `User` entity as
the acting account.

`field-access:preflight --format=json` is read-only by default. Its database
scanner emits names and type markers only: registered core/bundle/application
definitions, base/bundle/translation/revision columns and `_data` keys, schema
fingerprint, V1 driver identities, serialized entity locations, and current or
legacy persistent queue locations. Signed queue payloads are authenticated
before their envelope type is inspected; malformed, unverifiable, or signed
legacy payloads remain blockers. `--write-artifact` atomically writes the exact
checksum-bound candidate result. Classification, package-lock, definition, or
schema changes produce a different artifact identity.

The reusable `FieldReadGuard` decision/cache path is exercised only by WP3
fixtures and benchmarks. `EntityBase`, queries, serialization, and normal boot
do not install it in this tranche; WP4 performs that single no-shim activation.

## WP3 sealed entity-read convergence

`EntityBase` owns one private authoritative `EntityValueContainer`; there is no
protected or public raw value bag for subclasses. Public fields remain stored as
raw scalars/arrays in that container, while Protected and Internal fields are
stored as restricted cells bound to the exact entity-view identity and compiled
`EntityReadLayout`. Layout compilation is cached only under the exact entity
class/type/bundle source, immutable definition identities and semantic
fingerprint, structural key map, field-name shape, and registry-owned
type/bundle generation. Registry mutation advances that source generation and
replaces the registry's generation source so compiled identity/layout entries
cannot survive registration. A changed immutable-definition fingerprint does
the same. Custom definition implementations additionally install a weak,
registry-owned semantic fingerprint probe: an in-place change to read level or
authorization-input status advances the existing generation before the next
read. Losing the registry also stales that generation. The probe cannot retain
the registry, and the final readonly framework `FieldDefinition` never installs
one, preserving its constant-time generation check.
Every retained layout is sealed to both the process generation and its exact
registry generation, and every sealed entity or projected query decision checks
those seals. A value compiled under obsolete classification/policy metadata is
therefore rejected and must be reloaded/recompiled rather than read under stale
semantics.

Equivalent built-in immutable definitions may reuse a bounded process-lifetime
classification blueprint containing only field levels, authorization-input
names, and the undeclared-field level. A blueprint carries no registry authority:
each hit creates a fresh `EntityReadLayout` bound to the current process and the
requesting registry's current generation. Its key includes the exact semantic
definition identity and structural source shape, so a semantic definition
change misses instead of reusing stale classification. A registry-generation
change may reuse the immutable recipe, but only by binding a new layout to that
fresh generation; the already-bound layout still throws as obsolete. Custom
definition implementations never enter this cross-registry cache and retain
full metadata resolution plus the semantic mutation probe described above. The
cache is capped at 256 blueprints and excludes
entities, field values, views, principals, account scopes, policy decisions,
capabilities, ledger records, registries, and mutable runtime services.

V2 creation and hydration use an opaque `EntityInitialization` issued by one
`EntityInitializationBoundary`. The factory seals values and immutable
`EntityStructure` before an entity object exists; the paired installer creates
the object without invoking its constructor or `fromStorage()` and installs the
container, structure, entity type, and key map atomically. Entity code therefore
never receives a repository array during V2 hydration. Id, uuid, bundle,
language/translation, and revision selectors read the attached structure rather
than content fields; repository-owned backfills replace only the immutable
structural snapshot.

Ordinary sealed reads have three outcomes. Public values are returned without a
policy decision. Protected values require an established account read context
and an explicit Allowed decision; missing context and insufficient authority are
distinct failures. Internal values are never returned by the ordinary accessor
and require an exact declared capability through `AuditedFieldRead`, whose
strict ledger reservation is recorded before its closed reader obtains the
value. Mutation invalidates the guard's decision cache.

`EntityBase::__clone()` is final and reissues every restricted cell and guard
cache identity. Sealed `duplicate()` uses that clone path and never passes a raw
bag through the legacy overridable `duplicateInstance()` hook. Translation bags
are private related containers; translation clones and fallback reads use their
reissued view identities. Separately hydrated revision views likewise receive
distinct identities, while translation, fallback, and revision structural
metadata remains value-free.

`fieldNames()` is the canonical non-value-bearing enumeration surface.
`toArray()` first scans the complete layout and, if any Internal field exists,
throws before reading any Public or Protected value; it can never return a
partial array. `EntityValues` uses `fieldNames()` for framework entities,
excludes Internal names from ordinary projections, and obtains every selected
value through guarded `get()`. Its `toArray()` name-discovery fallback remains
only for third-party `EntityInterface` compatibility. `EntityValuesSnapshot`
preflights framework entities and refuses any non-Public sealed field before a
guarded read, so Protected/Internal values cannot be detached into an
unguarded snapshot. The repository's persistence snapshot remains a separate
private closure authority and is not implemented through `toArray()`,
`EntityValues`, or an overridable callback.

Whole-bag comparisons use the closed `EntityValueComparator` inside the private
containers and return only equality or changed-field-name metadata. Revision
restoration and write-echo bookkeeping never export compared values; their
legacy `toArray()` branches remain only for third-party `EntityInterface`
implementations that cannot expose the framework container.

Validation of a non-Public field is closed to the framework's reviewed Symfony
constraint set. `ValidationFieldReader` rejects custom constraints before
reservation, reserves a one-shot validation read before obtaining the value,
and finalizes success or failure. Outward violations use a constant message,
empty parameters, a value-free structural root, and `RedactedInvalidValue`; the
field value, entity object, constraint object, and causal exception are absent.

The approved matched-baseline memory gates use 10,000 sealed entities: at most
160 bytes per populated restricted field, at most 2 KiB total overhead for a
full User-shaped entity, no per-field object allocation for Public fields, and
zero retained entities after references are released (verified with
`WeakReference`). These are activation acceptance gates alongside, not a
replacement for, the frozen real-page latency gate above.

## WP3 fingerprinted field-storage gateway

The multi-backend field-storage SPI gains an additive V2 contract while the
ordinary entity read boundary remains dormant. A V2 implementation declares a
stable lowercase SHA-256 fingerprint and receives only a registrar-issued
`FieldStorageGatewayRole` plus an opaque `FieldStorageGatewayInput`. It must
unwrap the invocation and construct its opaque output through that same role;
the registrar exposes only `FieldStorageBackendGateway`, never the raw V2
implementation. Inputs, outputs, roles, invocations, and audit receipts reject
serialization and are bound to the exact registrar/backend object identity.

Every active V2 registration requires `StrictFieldStorageGatewayAuditInterface`.
The gateway synchronously reserves a value-free attempt descriptor before
fingerprint validation or backend invocation. Failure to reserve begins no
backend call and therefore no write. Fingerprint drift likewise finalizes a
failure with `backendInvocationStarted=false` before calling the backend. Once
the backend invocation begins, storage remains deliberately nontransactional:
a backend may have written before throwing or before returning an invalid
output, and a later backend in coordinator fan-out may fail after earlier
backends committed. The strict failure finalization records that invocation
started; existing `PartialSaveException` committed/uncommitted reporting and
application-owned reconciliation semantics remain unchanged.

WP4 activates the no-shim field-storage gateway in one change:

1. convert first-party and approved extension backends to
   `FieldStorageBackendV2Interface` and freeze their reviewed fingerprints;
2. require the strict gateway audit binding at the production composition root;
3. switch `BackendResolver`, definition validation, and
   `EntityStorageCoordinator` to registrar-owned gateways and opaque calls;
4. delete `FieldStorageBackendInterface`, `HasFieldStorageBackendsInterface`,
   `IsFrameworkBackendProviderInterface`, the V1 conformance harness, and the
   registrar's `get()`/`all()` V1 exposure; and
5. make the V1 field-backend preflight inventory empty because no V1 surface is
   loadable.

`CoordinatorLifecycleDispatcher` obtains post-callback persistence values once
through a private `EntityBase`-bound authority and passes only each declared
field value into its gateway. There is no adapter from V1 to V2 and no fallback
from a missing or rejected V2 gateway to V1.
The guard depends only on entity/access-layer compiled rules. Field-definition
metadata is resolved and weakly compiled by `FieldReadMetadataResolver` at the
existing field composition boundary, so the access package has no dependency
on the field package and no access↔field package cycle is introduced.

Before activation, framework User consumers converge on two required seams.
`UserInternalFieldReaderInterface` exposes typed, reason-specific snapshots for
credential verification, two-factor verification, mail delivery, verification,
session response identity, and maintenance authorization; it never accepts a
caller-selected field name. `UserIdentityLookupInterface` owns active login and
mail-existence queries. Their audit implementations open one registry boundary,
issue an exact reviewed capability, reserve before value/query execution,
finalize the strict ledger outcome, and revoke the boundary in `finally`.
Framework auth/session/mail/notification/CLI constructors require these seams;
there is no optional compatibility or raw-access fallback. Audited value reads
authorize and describe an attached `EntityStructure::bundleId` as canonical,
falling back to `EntityInterface::bundle()` only for non-V2/third-party entities.

WP4 activates cache, state, queue, and entity-serialization convergence.
Cache/state defaults and production HTTP cache composition reject a nested
entity graph before any write and require identifiers or an explicit Public
projection. `EntitySerializationBoundary` throws
`EntitySerializationForbidden` by default. Persistent database queue
composition rejects entity-bearing messages before serialization, requires a
reviewed `QueueEnvelopeV1` factory at dispatch and an authority-restoring
runtime at worker construction, and rejects authenticated legacy rows at both
retry and worker consumption. The restored scope closes in `finally` before
acknowledgement, release, or failure handling. Sync queues remain process-local
and do not claim persistent envelope semantics.

Normal runtime composition installs one process guard after policy discovery.
It uses the exact kernel-owned `AccountFieldReadScopeInterface` instance given
to `FieldReadContextMiddleware`; a separate scope would make request principals
invisible to sealed entity reads and is forbidden. Protected decisions route
through `EntityAccessHandler::checkProtectedFieldRead()` with an
entity-view-bound authorization-input subject. Internal values remain available
only through the audited capability readers.

Access-checked repository queries receive that same scope. A live account bound
with `setAccount()` is evaluated as the current immutable principal only when
account id and authentication state match; a different active identity fails
before cache or SQL work. An explicitly bound principal remains valid outside an
ambient scope. Access-filtered query cache identities include claims generation,
tenant, and community so a changed authorization snapshot cannot reuse prior
survivors. A framework Protected entity-read policy may opt into the closed
candidate projection only through `ProjectedProtectedEntityReadPolicyInterface`,
whose authorization-input list is a complete declaration for one exact
type/bundle plan. The handler returns a plan only when every matching Protected
entity-read policy has that contract; its declared input set must exactly equal
the generation-bound `EntityReadLayout` authorization inputs. The SQL query then
selects only those reviewed inputs and required structural selectors under
opaque aliases, builds `EntityStructure` through the canonical runtime compiler,
and evaluates a `CompiledPolicySubjectView` without constructing an entity or
returning subject values. SQL fragments come only from trusted resolved field
metadata and candidate IDs remain bound parameters. Legacy policies,
non-opted-in V2 policies, and unresolved bundle plans retain the existing
full-entity loader evaluation.

The projected path is fail-closed at both compilation and execution. An
unclassified or non-Protected declared input, a non-framework definition, an
unsupported storage backend, a policy/layout input mismatch, an obsolete layout
generation, or an incomplete, duplicate, or disappeared candidate row raises an
error before any survivor set is returned. Projection reads are chunked within
driver parameter limits and restored to the original candidate order. Missing
account context and an insufficient principal remain distinct from these
projection-integrity failures; neither becomes a null or empty-value success.

One reviewed User-specific difference is intentional. A legacy row with no
physical `status` key projects an exact `status => null` subject, which the User
policy treats as inactive and therefore denies to an ordinary profile viewer.
The hydrated subject omits a physically absent authorization input and fails
the policy's exact-subject-shape check. Those paths therefore agree on ordinary
denial and neither may synthesize User's permissive constructor default. An
administrator is the sole decision difference: projection permits through the
independent `administer users` grant after receiving the exact null-bearing
shape, while hydrated evaluation denies the incomplete shape. This accepted
discrepancy grants no authority to an ordinary principal and must not be
"fixed" by defaulting absent status to active; the private projection never
releases the null value or any entity field.

Production-equivalent normal boot consumes
`.waaseyaa/field-access-preflight.json`, recomputes framework,
classification-artifact, package-lock, and database-schema identities, verifies
the canonical checksum/readiness flag, and calls
`assertReadyForActivation()`. A missing, stale, malformed, or blocker-bearing
artifact aborts normal boot before provider boot hooks. The restricted
`field-access:preflight` command remains the sole producer of this artifact.

The semantic accessor inventory was reviewed under #2067. Remaining entries
are classified activation-compatible guarded accessors, closed
persistence/validation authorities, third-party compatibility fallbacks, or
explicitly classified audit read-model helpers; no entry retains stale WP3 wording.
The imperative `node_type` registration explicitly classifies its structural,
display, revision-default, status, dependency, and export fields Public, so the
production revision-default listener consumes the same reviewed metadata under
sealed repository hydration rather than relying on fixture-default access.
The relationship type is simultaneously the relationship bundle and label
selector, so it is structurally Public; endpoint and visibility content remain
Protected.
`MediaDownloadRouter` requires the request's immutable authorization principal
to match its account and runs policy evaluation plus the Protected `source_uri`
read inside the kernel-shared account scope. Direct dispatch without that
principal fails closed. `MediaProtectedFieldReadPolicy` releases `source_uri`
to an immutable principal holding the same `access media` capability that
admits published media view, while `uid` remains owner/admin-only; response MIME type and filename are derived from the
resolved contained file, so the download path does not acquire authority for
unclassified metadata fields.
Media ownership remains Protected: `uid` is the exact compiled authorization
input consumed by `MediaAccessPolicy` through a private entity-bound subject
authority. The policy never invokes the ordinary owner accessor, and ordinary
`getOwnerId()` calls still require an explicit account principal context.

Relationship endpoint selectors and maintenance values remain Protected.
Topology, traversal, endpoint visibility, and lifecycle consumers receive only
fixed-shape typed projections from private `EntityBase`-bound readers; callers
cannot select arbitrary relationship fields or export the underlying value bag.

Genealogy policy decisions consume compiled Protected `tree_id`, `status`,
`owner_uid`, `is_living`, and `death_date` inputs. Person/family/event
`deleted_at` tombstones remain Internal and are read only by a typed reader that
opens an execution boundary, issues the exact `genealogy.tombstone` capability,
uses the strict audited value-read seam, and revokes the boundary in `finally`.
Pedigree and family services consume the shared typed relationship-topology
projection. Anonymous SSR integration enters one exact principal scope around
the complete synchronous controller and template render, preserving living
person concealment without granting ambient access.

Wayfinding keeps `owner_uid` Protected and marked as an authorization input.
`TrailAccessPolicy` decides ownership from the compiled subject, while
`TrailStore` uses a private fixed-shape persistence authority for the exact
title, beacon, origin, and owner values required by save and re-record flows.

Classification retention-policy fields are Protected governance configuration.
The V2 entity/field policy releases them only to `governance-viewer` or admin
principals (mutations remain admin-only), while scheduler jobs receive one
closed fixed-shape maintenance projection rather than entering an ambient
account scope or invoking ordinary getters.

Messaging thread, message, and participant values—including participant
roles—are Protected participant content. Message and participant `thread_id`
selectors are exact authorization inputs. `MessagingAccessPolicy` evaluates
immutable principals against those compiled selectors (or the structural thread
id), and Protected entity/field reads fail closed for non-participants.

Taxonomy term `name` is explicitly Public because it is the entity's public
label; an undeclared runtime-added term field remains Internal and cannot be
read or array-exported accidentally. Engagement comment, reaction, and follow
content is Protected. Owner, target type/id, and comment publication status are
exact compiled inputs used by immutable-principal entity and field policies;
unpublished comments release only to their owner, while published engagement
still inherits parent visibility. Note ingestion provenance remains Internal
and is exposed to trusted ingestion verification only as a fixed two-field
metadata projection.
