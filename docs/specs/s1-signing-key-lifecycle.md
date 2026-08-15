# S1 OIDC signing-key lifecycle

Status: implementation contract for the signing-key lifecycle slice of
`S1-FW-CFG-04`.

This is a forge-neutral local contract. It authorizes synthetic tests and local
source changes only; it does not authorize access to operational signing
material, publication, deployment, production mutation, or a rotation ceremony.

## Authority and states

One migration-backed OIDC lifecycle owns four exact states:
`staged-verify-only`, `active-sign-and-verify`,
`retired-verify-only`, and `revoked`. At most one active key and one staged
successor may exist. A monotonic database sequence allocates key versions; a
version is never derived from key bytes and is never reused by a committed key.

Reads perform no generation, rotation, repair, or DDL. An empty production
lifecycle refuses signing and JWKS service until an explicitly confirmed
initialization command establishes the first active key. Compatibility PEM
loaders remain available to explicit callers, but issuer signing and JWKS
composition always use the database lifecycle.

## Custody and algorithms

Public `SigningKey` metadata contains no private value. Private PEM crosses
from encrypted persistence into a process-local `SecretHandle` and only the
registered RS256 signing consumer can turn it into signature bytes. Signer
handles refuse serialization and expose only redacted metadata. A closed
algorithm policy applies at configuration ingestion, minting, and JWKS
construction.

## Ordinary rotation

Ordinary rotation is a three-phase protocol:

1. `stageSuccessor` transactionally allocates a version and publishes one
   verify-only public key without changing the active signer.
2. `recordPropagation` binds a SHA-256 digest of non-secret observable
   propagation evidence to that staged key.
3. `activateSuccessor` waits through the published JWKS cache lifetime plus
   propagation margin, then one transaction compare-and-swaps the expected
   active version to retired and the staged key to active.

The predecessor receives:

`retain_until = rotated_at + maximum configured token lifetime + maximum clock
skew + JWKS cache lifetime + propagation margin`.

Cleanup can delete only ordinary retired keys whose full boundary has elapsed.
JWKS publishes the staged successor, active signer, and every unexpired retired
verifier; it never publishes revoked or private material. Its cache max-age is
read from the same lifecycle policy used in retention arithmetic. This ensures
the first post-activation token verifies against the oldest cache permitted by
the pre-activation policy.

## Compromise boundary

Emergency revocation is not ordinary rotation. It is a separately confirmed,
request-identified mutation that records actor, reason, affected persisted
access/refresh-token enumeration digests and counts, and the conservative
stateless issuance window. Revoked keys leave signing and JWKS trust
immediately. External key destruction and any operational invalidation remain
deployment/operator responsibilities.

## Schema and verification

All lifecycle columns, version authority, indexes, and append-only revocation
evidence arrive through a DB-02 migration. Runtime repositories only assert the
schema and perform DML. Retained-red proofs cover empty-read refusal,
non-exporting custody, rollback on successor failure, staged propagation,
oldest-cache verification, exact retention arithmetic, competing successors,
monotonic versions, cleanup boundaries, emergency revocation, and the CFG-03
trust-policy seam.

