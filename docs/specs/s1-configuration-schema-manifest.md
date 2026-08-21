# S1 configuration schema, sync format, and manifest authority

**Change record:** `S1-FW-CFG-03`  
**Status:** implementation-authorized on exact local predecessor
`e0db3cd933439260d978da42b7d536be0fb24f12`  
**Owns:** `F-CFG-007`, `F-CFG-008`, `F-CFG-009`  
**Depends on:** `S1-FW-CFG-01`, `S1-FW-CFG-02`

This contract closes the configuration schema, authored sync artifact,
effective generation, package compatibility, and drift-verification boundary
for the S1 single-node SQLite profile. It creates no release, deployment,
staging, production, signing-key, backup, restore, or recovery authority.

## Decisions and boundaries

- `waaseyaa.config-schema/1` is a Waaseyaa-owned closed dialect, not JSON
  Schema. Unknown keywords, types, malformed schemas, and conflicting or late
  registrations fail before configuration data is considered.
- `waaseyaa.config-sync/1` is the only writable/activatable sync format.
  Format-0 input requires a separate deterministic offline migration and never
  activates implicitly.
- `waaseyaa.canonical-config-json/1` is a versioned canonical I-JSON profile;
  it does not claim RFC 8785 conformance.
- CFG-03 owns manifest/envelope bytes, signer/verifier interfaces, replay
  sequencing, and verification results. CFG-04 owns key custody, trust
  resolution, rotation, revocation, and production signing authority.
- CFG-02 remains the only activation transaction authority. Tombstones,
  expected tokens, activation requests, and complete-replacement intent remain
  activation-plan state and are not smuggled into content identity.
- All persistence is installed by DB-02 versioned migrations. Runtime schema,
  manifest, and drift paths perform zero DDL and zero repair.

## Closed schema registry

The registry entry identity is:

```text
(schema_id, integer schema_version, dialect,
 owner_package, owner_config_contract_version, canonical_schema_hash)
```

Schema content changes, including default changes, require a new integer
version. Exact duplicate registration is idempotent. Any conflicting identity
fails. The registry freezes at the CFG-01 post-provider-boot
`configuration-authority-ready` boundary; CLI, HTTP, MCP, and bundle validation
reject late registration.

The v1 dialect permits only these keywords where structurally applicable:
`dialect`, `type`, `properties`, `required`, `additionalProperties`, `items`,
`enum`, `minimum`, `maximum`, `default`, and `nullable`. Supported non-null
types are `object`, `array`, `string`, `integer`, and `boolean`. A nullable type
is expressed as exactly one non-null type plus `null`. Floating-point `number`
and tuple schemas are unsupported.

Objects are closed by default. `additionalProperties` is either `false` or one
typed recursive schema; boolean `true` is not an escape. Arrays require exactly
one recursive `items` schema. Schema defaults are meta-validated against their
own schema.

Validation produces a separate effective document. Defaults materialize
recursively only for absent named object properties. A child is reached only
when its parent exists or has an object default. Arrays never synthesize
elements; extension schemas never invent keys. Explicit `null` remains
distinct from absence. Schema context disambiguates empty lists and maps.

A schema-owning package may additionally register one semantic validator for a
schema identity when a constraint depends on installed runtime definitions and
cannot be expressed by the closed dialect. The validator is registered and
frozen with the same registry, runs only after structural validation, receives
the complete authored document rather than one entry at a time, and must be
deterministic and read-only. Its verdict is required before authored or
effective content hashes are accepted. The package contract version and schema
provider bind responsibility for that code; semantic validators add no dialect
keywords.

A semantic validator declares a deterministic, portable **semantic contract**
string, which is the schema identity's promise about which domain rules were
enforced. That string — never a runtime object identity — is bound into
`canonical_schema_hash` under the separate `waaseyaa.config-schema+semantic/1`
digest domain, so the guarded and unguarded forms of one schema occupy
non-colliding identity spaces. Content authored under a semantic contract
therefore cannot verify on a host that installs a different contract or none at
all; the mismatch surfaces as the ordinary bound-identity refusal. Advancing
the enforced semantics means advancing the contract string.

Registering a semantic validator twice is idempotent only when the second
registration is genuinely the same authority: the same instance, or the same
class declaring the same contract and closing over the same runtime
dependencies. A second registration of the same class carrying materially
different dependencies is an ambiguous authority and is refused, as is any
competing class and any registration after freeze.

## Strict sync format

Every file declares a closed `_meta` mapping containing:

- `format`: exactly `waaseyaa.config-sync/1`;
- `entity_type` and `entity_id`, both agreeing with the filename;
- UUID and langcode;
- required dependencies list;
- exact schema ID, version, and canonical schema hash;
- owning package and its integer configuration-contract version.

Entry identity is `(entity_type, entity_id, langcode)`. UUID-to-entry binding is
unique within one bundle. Dependencies are a required list of unique canonical
refs. Wrong containers, non-string entries, duplicates, self-dependencies, and
malformed refs fail; dependencies sort only as a semantic set for canonical
encoding.

Symfony YAML is not the security boundary. A lexical pre-pass rejects duplicate
keys (including null-first duplicates), anchors, aliases, merge keys, explicit
tags, and forbidden directives. A recursive typed walk rejects objects,
invalid UTF-8, floating or non-finite values, unsafe integers, and implicit
date/timestamp coercion. Each entry binds both exact UTF-8 YAML bytes and
canonical authored content, so permitted comments remain authenticated.

Unknown `_meta` or top-level keys fail unless the selected schema explicitly
authorizes the top-level field. Validation accounts for every directory entry,
including malformed names, unreadable paths, empty files, and parse failures;
one bad entry never truncates later diagnostics.

## Canonical encoding and digests

Canonical configuration JSON uses these rules:

- map keys are non-empty ASCII and sort bytewise;
- list order is preserved;
- schema-declared sets sort semantically before encoding;
- strings must be valid UTF-8 and are preserved without normalization;
- floats, objects, resources, and integers outside the interoperable range
  `[-9007199254740991, 9007199254740991]` fail;
- empty containers are encoded using schema context;
- every digest is `SHA-256(domain_id + NUL + canonical_bytes)`.

Authored hashes bind the explicit normalized source. Effective hashes bind the
recursively default-materialized document plus its exact schema and owning
configuration-contract identities. These hashes are never interchangeable.

## Three distinct manifests

### Sync-bundle manifest

The signed authored artifact binds format and dialect versions, producer
evidence, required package configuration-contract versions, registry checksum,
bundle scope, monotonic bundle sequence, and a map from `(ref, langcode)` to
UUID, exact-byte hash, authored-content hash, schema identity, and dependencies.

### Effective-generation manifest

This content-only manifest maps `(ref, langcode)` to effective entry hash,
schema identity, and owning configuration-contract identity. CFG-02's reusable
`generation_id` is the domain-separated SHA-256 of these exact bytes. The
hashed document never contains its own ID or another aggregate checksum.

### Activation-plan manifest

CFG-02 request state binds submitted input, original expected token,
candidate/generation identities, operations, explicit deletions, and plan hash.
It does not change authored or effective content identity.

Generated time, actor, host, path, mtime, request identity, activation sequence,
expected token, and signature bytes are evidence/envelope fields excluded from
content hashes.

## Envelope, replay, and unsigned policy

The envelope uses DSSE-style pre-authentication encoding. Its closed protected
header binds payload type, the only v1 algorithm (`Ed25519`), trust-key
reference, bundle scope, monotonic bundle sequence, and canonical manifest
bytes. Unknown fields or algorithms fail before key resolution.

Ordinary activation requires a sequence greater than the last committed
sequence for the scope/signing authority. Older signed content uses CFG-02's
separately authorized rollback path. Unsigned local/test policy comes only from
sealed CFG-01 bootstrap identity, never mutable `APP_ENV` alone. Production
continues to refuse until CFG-04 custody is bound; CFG-03 must not manufacture
keys or signing/custody claims.

## Package compatibility and rollback direction

Every schema-owning package declares `extra.waaseyaa.config-contract` with an
integer contract version and schema-provider identity. Composer package
versions, exact source refs, and installed cohorts are provenance evidence;
compatibility is decided by configuration-contract versions and schema hashes.

Staging new content requires the current writable contract/schema version.
Read-only verification and CFG-02 reactivation of retained generations require
a declared readable version. There is no implicit down migration. Signature
provenance remains immutable; retired versus compromised trust policy belongs
to CFG-04.

## Drift verification

Verification recomputes authored bytes and content, effective documents,
defaults, schema and registry identities, dependencies, package contracts,
generation rows, and the transactional manifest. Active verification uses one
CFG-02 read snapshot and binds its verdict to the exact active token. Every
supported long-lived reader reports that token; absence or mismatch fails.

Diagnostics sort bytewise by path, phase, and field. Read-only verification
performs no repair or staging. Before/after hashes of SQLite, sync, and compiled
artifacts prove non-mutation even when drift exists.

## Retained-red categories

Implementation tests must first reproduce and then close:

1. unknown schema keyword/type and malformed-schema acceptance;
2. unknown data properties, missing recursive array validation, and defaults
   that validate without materializing;
3. null-versus-absence and empty-container ambiguity;
4. registry conflict, duplicate, and freeze behavior;
5. unversioned sync input, metadata mismatch, dependency coercion, duplicate
   UUID/ref, and malformed directory-entry handling;
6. YAML duplicate-key, alias/anchor/merge/tag/directive, implicit date,
   invalid UTF-8, float, and unsafe-integer hazards;
7. canonical nested-map order, list order, domain separation, authored versus
   effective identity, and generation self-reference;
8. bundle/global/package/schema tampering and incomplete verification;
9. protected-header tampering, unsupported algorithm, replay, and unsigned
   production refusal without resolving or creating a key;
10. writable versus readable rollback compatibility, forward-only migration,
    snapshot-consistent drift, complete diagnostics, zero mutation, and zero
    lazy DDL;
11. shipped schema/default/sync corpus conformance and an installed exact-head
    consumer proof.

## Review-candidate and verification contract

The review candidate records this change-record ID, exact parent and candidate
commits, and executable evidence. Spec and retained-red commits precede
implementation without history rewrite. Required gates include focused CFG-03
tests, split Unit/Integration/Architecture suites, Composer validation,
PHPStan, package-layer/dead-code checks, changelog and diff hygiene, exact-head
packaged-form proof, and independently reconciled advisory review.

The package is complete only when every retained malformed/tampered fixture is
rejected without serving-state mutation and the positive strict v1 bundle can
be verified and handed to CFG-02 activation. CFG-03/04 signing and independent
custody remain red unless separately proven by their own authorized evidence.
