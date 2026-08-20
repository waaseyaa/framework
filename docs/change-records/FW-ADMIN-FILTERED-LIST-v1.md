# FW-ADMIN-FILTERED-LIST-v1 — canonical declared-filter destinations

- Parent: `5327ab17bf8d5112bb1f527756f85d07f7c82852` (rebased from
  `cf4bd663ae5fa96b11683e48fdd487b6214ee192`)
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

## Closed field grammar

Field names must satisfy `\A[A-Za-z_][A-Za-z0-9_.]*\z` — the same grammar
`ListMetadata` enforces on a declared list, now read by both from
`SurfaceFieldName` so the two cannot drift. Dotted and underscored canonical
names are preserved; brackets, whitespace, control characters, leading digits,
slashes, backslashes, and non-ASCII names are refused.

A name carrying `]` or `[` previously produced a differently shaped query key
than the field requested, and a name carrying a space addressed a field no
declaration could name. Neither escalated authority — the list ignores keys it
did not declare — but the generator may not emit a destination the metadata
would refuse. The pattern is anchored with `\A`/`\z` rather than `^`/`$`
because PCRE's `$` also matches before a trailing newline, which would have
admitted `"state\n"`.

## Operator/value restoration contract

An operator is serialized so the list can compare it against the one its own
metadata declares — never so the list can execute it. `filteredList()` therefore
accepts only canonical `SurfaceFilterOperator` names and emits them in canonical
form.

`SchemaList.restoreBrowserQuery()` restores a search or filter control only when
the URL carries **both** members of the pair **and** the URL operator is exactly
the operator that field declares. A pair that is missing a member, names an
unknown operator, disagrees with the declaration, or addresses an undeclared
field restores nothing and leaves the control at its default. The executed query
continues to use the metadata-declared operator in every case, so metadata
remains authoritative and an arbitrary URL operator is never run.

## Evidence

- retained-red contract: `f5f70dd73136cd301e21d420965e8e58e533a053`;
- implementation: `99af4cecfce2ce5c52a19578f1c29c9324bce50b`;
- remediation of the two review findings, rebased onto `5327ab17`;
- `AdminDestinationPathsTest`: 64 tests / 77 assertions, covering every
  canonical and non-canonical field name and both operator rules;
- `SchemaListFilterRestore` Vitest: 9 behavioural tests covering matching,
  missing-operator, missing-value, mismatched, unknown, and undeclared pairs;
  four of them fail against the pre-fix restore logic, so the suite is a
  regression rather than a restatement;
- Admin Vitest: 90 files / 620 tests; `typecheck` and `build:contracts` green;
- Admin Surface Unit: 275 tests / 1,231 assertions;
- Admin dist rebuilt twice from clean inputs with identical digests;
- full PHPStan and dead-code analysis: green;
- PR preflight: 33/33 gates green, including surface parity, spec drift,
  changelog discipline, formatting, and Admin dist freshness.

No merge, release, tag, deployment, or settings action is implied.
