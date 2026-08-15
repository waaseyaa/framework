# S1-FW-CFG-04 OIDC application-master owner plan

Status: implementation plan for the OIDC persisted-purpose slice at parent
`c17bc04dd2bfbefef8048984daaeffc467c7fb2f`.

This plan authorizes local source changes, commits, and synthetic verification
only. It does not authorize operational key access, publication, deployment,
production mutation, backup or recovery work, or a rekey ceremony.

## Outcome

`waaseyaa/oidc` becomes the single production owner of its five declared
application-master purposes. New signing keys and opaque tokens use only the
active application-master version. Reads use an envelope-declared ciphertext
version and a bounded roster of declared lookup versions. Signing-key material
and each token ciphertext plus sibling lookup index transition with resumable,
per-row compare-and-swap semantics and verified rollback.

## Composition

The package contributes three database-authoritative adapters:

1. `oidc-signing-key-v1` owns signing-key encryption.
2. `oidc-access-token-v1` jointly owns access-token encryption and lookup.
3. `oidc-refresh-token-v1` jointly owns refresh-token encryption and lookup.

Splitting a token row into independent encryption and lookup adapters is
forbidden because it permits a committed mixed-generation pair. Combining all
three tables in one adapter is rejected because the coordinator persists one
cursor and snapshot per adapter, which would make independent bounded progress
and verification needlessly coupled.

## Persistence boundary

A DB-02 versioned migration:

- widens both opaque-token `token` columns from `VARCHAR(128)` to `TEXT` using
  Doctrine DBAL schema comparison, including SQLite's generated table rebuild;
- adds a positive, unique `custody_sequence` to each opaque-token table and
  deterministically backfills existing rows in `jti` order;
- adds one non-secret `oidc_token_custody_sequence` allocation table with an
  access and refresh counter initialized beyond the backfilled maxima.

Token issuers allocate and persist the sequence and token row in one
transaction. The allocation uses compare-and-swap so concurrent issuers cannot
reuse a sequence. Runtime code performs no DDL.

The signing-key adapter uses existing monotonic `key_version`. Token adapter
snapshots bind the exact maximum custody sequence and the ordered identity hash
of predecessor/legacy rows at or below that boundary. Successor-only writes
receive a higher sequence and cannot change the frozen inventory. A missing,
duplicate, malformed, deleted, or independently changed inventoried row blocks
completion instead of being silently omitted.

## Custody formats and compatibility

Ciphertext is canonical JSON for `ApplicationMasterEnvelope`; the authenticated
metadata binds purpose, row identity, schema version, and master version. Reads
also require the envelope identity to equal the selected row identity.

Lookup values use the compact canonical form `v<master-version>:<43-character
base64url digest>`. The fixed column purpose supplies the purpose identity, and
the strict parser reconstructs `ApplicationMasterAuthenticationTag`. This fits
the existing 64-character column for every supported positive integer version.

The existing `secretbox.hkdf-v1` ciphertext and 64-hex lookup formats remain a
bounded migration bridge only when an explicit OIDC compatibility setting is
enabled. With an application-master keyring and no bridge setting, runtime
reads fail closed on those legacy values. Without a keyring, the legacy
constructors remain source-compatible for explicit callers while the framework
transition is staged. No adapter persists raw keys or secret-bearing evidence.

## Forward transition

Each batch selects frozen identities above the durable cursor, opens only the
declared legacy/predecessor representation, seals under the active successor,
and performs one CAS update. Token CAS updates `token` and `token_lookup`
together and conditions on identity, custody sequence, old ciphertext, and old
lookup. It never writes lifecycle columns such as `revoked_at`. Signing CAS
updates only `private_key_pem` and conditions on `kid`, `key_version`, and the
old ciphertext.

The adapter emits a count delta for its complete purpose roster. Token
encryption and lookup deltas are identical. Batch commitments and verification
hashes include only request identity, row identities, versions, counts, and
cryptographic digests; they contain no token, plaintext, ciphertext, or key
bytes.

## Verification and rollback

Verification re-reads every row in the frozen boundary, requires the expected
master version for both ciphertext and lookup, opens the ciphertext with exact
row identity, and binds the ordered identity set to the original snapshot.

After the persisted writer switch, rollback takes a new immutable inventory of
failed-successor rows. The rollback keyring has the predecessor as its sole
active writer and the failed successor as readable only. Each reverse CAS
re-seals under the predecessor and, for tokens, recomputes both columns in one
statement. Revocation and signing lifecycle state are neither reset nor
resurrected.

## Retention policy

- signing-key encryption: maximum lifetime 7,776,000 seconds; retention is the
  configured signing lifecycle retention formula;
- access-token encryption and lookup: maximum lifetime 3,600 seconds;
  retention 3,900 seconds (TTL plus the configured maximum clock skew);
- refresh-token encryption and lookup: maximum lifetime 7,776,000 seconds;
  retention 7,776,300 seconds (TTL plus the configured maximum clock skew).

The provider derives the skew from the same `SigningKeyLifecyclePolicy`
configuration authority. A later token-expiry policy extraction may replace
these current issuer constants, but it must not weaken the registered bounds.

## Test and gate sequence

1. Retained-red provider roster, policy, and migration-shape tests.
2. Retained-red custody-format, successor-write, bounded-read, and fail-closed
   compatibility tests.
3. Retained-red adapter snapshot/resume/CAS/verification/rollback tests,
   including concurrent revocation preservation and injected cursor-ledger
   failure atomicity through the real coordinator/store transaction.
4. Implement the migration and custody boundary, then production provider and
   writer/read paths, then the three adapters.
5. Run focused OIDC and Foundation tests, split Unit and Integration suites,
   PHPStan, style, package-layer, migration, changelog, dead-code, and exact-head
   verification gates.
6. Reconcile an independent exact-head review before CFG04 closure.

