# FW-ADMIN-FILTERED-LIST-v1 — canonical declared-filter destinations

- Parent: `cf4bd663ae5fa96b11683e48fdd487b6214ee192`
- Issue: `#2462`
- Downstream: `jonesrussell/sheguiandah-waaseyaa#101`
- Authority: destination generation only; no query, field, entity, mutation, or
  access authority

## Boundary

`AdminDestinationPaths::filteredList()` encodes the list filter controls that
the schema-driven Admin SPA already restores. It accepts an untrusted array,
narrows it to exact `field => {operator, value}` tuples, sorts fields, and uses
RFC3986 query encoding. Empty filter sets, fields, operators, values, malformed
tuples, numeric fields, and extra tuple members are refused.

The generator does not declare a field filterable and does not bypass list
metadata, surface query policy, protected-field reads, or entity access. A
consumer must still offer a destination only to its authorized principal.

## Evidence

- retained-red contract: `f5f70dd73136cd301e21d420965e8e58e533a053`;
- implementation: `99af4cecfce2ce5c52a19578f1c29c9324bce50b`;
- focused contract: 37 tests / 48 assertions, 36 of 37 class statements covered
  (the sole uncovered statement is the private constructor); every new method
  statement is covered;
- Admin Surface Unit: 238 tests / 1,058 assertions;
- Unit: 11,728 tests / 231,862 assertions;
- Integration: 2,008 tests / 8,876 assertions;
- Architecture: 282 tests / 24,264 assertions, one environment skip;
- full PHPStan and dead-code analysis: green;
- PR preflight: 31/31 gates green, including surface parity, spec drift,
  changelog discipline, formatting, and Admin dist freshness.

No merge, release, tag, deployment, or settings action is implied.
