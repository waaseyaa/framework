# Entity Field-Read Boundary

**Status:** WP1 contracts plus WP2 closed primitives and propagation; field-read enforcement remains dormant until the single no-shim activation PR.
**Anchor:** GitHub #2064, with Design Revisions 2 and 3 controlling.

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
required activation fixtures: booted class/bundle definitions, translations,
revisions, config and audit read models, principal creation, cold/warm Protected
reads, strict audited reads, and 50-field projection. Activation budgets remain:
warm Public median no more than 25% above its matched baseline, and warm Protected
no more than twice guarded Public, with peak memory/allocation results reported
per fixture.

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
