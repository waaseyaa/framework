# Site golden path implementation plan (#2343)

The enduring contract is `docs/specs/site-golden-path.md`. This file records
the planned delivery sequence and review boundaries.

## Sequence

1. Land the specification and exact work-package split without runtime code.
2. WP1 adds the strict manifest schema/model/parser with malformed, unknown,
   duplicate, and valid-substitution negative tests.
3. WP2 adds transactional initialization and deterministic regeneration using
   temporary output plus atomic publication.
4. WP3 adds strict doctor reports, repo-wide bypass discovery, scoped expiring
   suppressions, and `site-verify`.
5. WP4 adds the published-content recipe through Listing, Path, routing, SEO,
   dependency injection, and generated acceptance tests.
6. WP5 adds the subscription recipe through framework database/migrations,
   privacy lifecycle, Mail, Queue, and generated acceptance tests.
7. WP6 proves a clean packaged consumer, offline reruns, framework upgrade
   continuity, and both local and hosted CI adapters.

## Cross-package boundaries

- CLI owns command presentation and delegates to typed services.
- A new low-layer site-contract package owns schema, manifest values,
  validation, deterministic rendering metadata, and report types.
- Recipe implementations may depend downward on the capabilities they compose;
  they must not be placed in a low layer that imports Listing, SEO, Mail, or
  Queue upward.
- Skeleton owns generated consumer files and adapter examples.
- Existing `waaseyaa-audit-site` remains available during migration but strict
  convergence moves to the typed `site:doctor --strict` plus portable
  `site-verify` contract.

## Review gates

Every implementation PR must include:

- `Part of #2343` and the work-package identifier;
- a CHANGELOG `[Unreleased]` entry;
- a `spec-reviewed:` commit trailer;
- the failing boundary test observed before implementation;
- focused tests, split Unit/Integration suites as applicable, code style,
  PHPStan, Composer policy, and package-layer checks; and
- an independent review of architecture/discovery changes before auto-merge.

The final reference-consumer gate must run without network access after an
exact dependency install and must not require GitHub credentials, APIs, or
repository availability.
