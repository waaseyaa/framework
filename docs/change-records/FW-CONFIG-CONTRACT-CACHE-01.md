# FW-CONFIG-CONTRACT-CACHE-01 — preserve installed CFG-03 contracts on cache hits

- **Issue:** [#3044](https://github.com/waaseyaa/framework/issues/3044)
- **Studio blockers:** [Studio #24](https://github.com/waaseyaa/studio/issues/24),
  [Studio #25](https://github.com/waaseyaa/studio/issues/25), and
  [Studio #19](https://github.com/waaseyaa/studio/issues/19)
- **Base:** `e780870d5249205f6d4a683ba2b85160c183fcd8`
- **Scope:** package-manifest cached loading only; no configuration fallback,
  signature change, schema change, or Studio-owned package declaration.

## Measured defect

An immutable Studio execution image contained `waaseyaa/workflows`, its
provider, and its CFG-03 v1 declaration in Composer's installed metadata. The
first package-manifest compile collected that declaration. A later process
loaded the valid cache and `mergeRootWaaseyaaIntoManifest()` reconstructed the
manifest without `configContracts`, so canonical configuration activation
refused that no installed contract existed for `waaseyaa/workflows`.

The regression test performs a real compile-and-cache followed by a new
compiler instance's cache hit. Before the repair it failed with the declared
contract replaced by an empty array while the root provider remerge succeeded.

## Decision

Cached root metadata remerge forwards the existing manifest's
`configContracts` alongside every other installed discovery field. Contracts
remain compiled exclusively from installed Composer metadata; the root remerge
does not add, reinterpret, or validate a second source. Legacy caches continue
to deserialize a missing `config_contracts` key as an empty cohort.

## Evidence

- RED: focused regression, 1 test / 2 assertions, failed because the cached
  load returned no configuration contracts.
- GREEN and final focused discovery results are recorded with the immutable
  review candidate before publication.
- The consuming Studio image is rebuilt and qualified separately against the
  accepted exact Framework commit; this record alone does not claim that
  product journey complete.
