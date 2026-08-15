# S1-FW-CFG-04 — secret custody and signing lifecycle

- Parent: `971bb1f3ba6525c4d37ae70c822fff830efb8cce`
- Parent tree: `4731fc23f8305dff22f4782b8f6d30984f57c1bb`
- Signing contract: `docs/specs/s1-signing-key-lifecycle.md`
- Findings: `F-CFG-010`, `F-CFG-012`, `F-CFG-013`, `F-CFG-014`,
  `F-CFG-015`; downstream `F-CFG-011` remains open
- Authority: local source changes and synthetic evidence only; forge-neutral

## Sequence

1. Preserve the exact integration/provider custody candidate and its evidence.
2. Commit retained-red signing-custody failures without rewriting history.
3. Establish non-exporting signer handles and remove private PEM from public
   key metadata.
4. Commit retained-red staged lifecycle failures.
5. Add the DB-02 migration, staged propagation, transactional activation,
   retention/JWKS policy, and explicit operator commands.
6. Commit retained-red compromise failures, then add separately confirmed
   emergency revocation and the closed Ed25519 CFG-03 trust composition.
7. Implement the versioned application-master/rekey primitives as a separate
   slice; Sheg retains ownership of operational preflight and ceremony.
8. Re-run exact verification, packaged-form proof, evidence sealing, and
   independent exact-head review.

## Interlocks

No real credential or signing key may be accessed, generated, rotated, revoked,
or destroyed. No issue, pull request, hosted check, merge, split-package fan
out, publication, release, deployment, production operation, backup, restore,
or recovery action is authorized by this record.

Framework emergency-revocation and master-rekey work provides production
primitives exercised only with synthetic evidence in this change record. The
installed cache owner contributes a concrete, database-authoritative generation
adapter; advancing the generation invalidates rebuildable cache payloads without
exporting key material or rewriting payload rows. Explicit cache deletion
reclaims the selected ids or bins across all generations. The database queue
now writes active-version authentication tags when a keyring is composed and
contributes a zero-row drain gate covering pending and failed predecessor or
failed-successor payloads without deleting jobs. `F-CFG-011` stays red until a
separately authorized Sheg/operator workflow supplies deployment preflight,
external custody, retained-backup, and ceremony evidence.

## Evidence disposition

Every implementation slice records exact commit/tree identity, split test and
static-analysis results, schema and package-layer rosters, packaged-form
results, and reconciled independent review. Evidence contains only synthetic
keys, non-secret fingerprints, counts, hashes, versions, and command outcomes.

This record remains open until signing lifecycle, emergency compromise,
CFG-03 composition, application-master transition, exact verification, and
independent review are all complete.
