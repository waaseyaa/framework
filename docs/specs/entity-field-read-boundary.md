# Entity Field-Read Boundary

**Status:** WP1 additive contracts; dormant until the single no-shim activation PR.
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
execution-boundary revocation contract. Authority exists only as registry-owned
`WeakMap` membership; a caller may construct a handle but cannot reconstruct or
forge membership.

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
