# 009 — Migration manifest discovery: array form, FQCN namespaces, path coexistence

**Status:** Accepted (2026-05-03)
**Mission:** #529 (Schema Evolution v2.0), WP11
**Spec context:** `docs/specs/schema-evolution-v2.md` §8 (manifest evolution), §15 Q9 (manifest array form)

## Context

Pre-WP11, `extra.waaseyaa.migrations` accepted a single string — a path to one directory containing `*.php` legacy migration files. v2 migrations (mission #529) are FQCN classes implementing `MigrationInterfaceV2`, not files in a directory. The manifest needs to express both kinds, and Q9 ratified that the existing string form must keep working indefinitely.

## Options considered

### A. Two parallel keys (`migrations` + `migrations_v2`)

Add a sibling key for v2. Clean separation, but doubles the API surface and forces every package to know about v2 explicitly even when they have none. Rejected.

### B. Object/struct entries (`{type: 'namespace', value: '...'}`)

Verbose for the common case; introduces a second JSON-shape vocabulary alongside Composer's existing conventions. Rejected for v1; reserved for a future iteration if heuristic ambiguity emerges.

### C. Heterogeneous list of strings (CHOSEN)

Accept either a single string or an ordered list of strings. Each list entry is classified at discovery time as either an FQCN namespace prefix or a directory path. Compact, ordered, and backwards-compatible.

## Decision

`extra.waaseyaa.migrations` accepts either:

```json
"migrations": "migrations"
```

or:

```json
"migrations": [
  "Vendor\\Pkg\\Migrations\\v2",
  "../patches/v2",
  "Vendor\\Pkg\\Migrations\\hotfixes"
]
```

### Classification heuristic (v1)

Each list entry is classified by `MigrationLoader::looksLikeNamespace()`:

| Entry shape | Classification | Loader behaviour |
|-------------|----------------|------------------|
| Contains `\` | FQCN namespace prefix | `loadAllV2()` discovers `MigrationInterfaceV2` classes via Composer's classmap. |
| No `\` | Path string | `loadAll()` resolves as a directory relative to the package install path; `*.php` files load in lex order. |

This is intentionally simple. Windows path separators (`\\` in JSON literal) would tokenize as backslash characters at the PHP level — meaning a Windows-style path like `..\\patches` would be misclassified as a namespace. v1 documents this and recommends Unix-style separators in manifest values; future iterations may add explicit `{type: ..., value: ...}` override syntax if real-world ambiguity emerges.

### Validation discipline

- Compile time (`PackageManifestCompiler::validateMigrationsEntry()`): the entry MUST be a string OR an ordered list of strings. Anything else (objects, nested arrays, non-string scalars) raises `InvalidMigrationEntryException` with stable code `INVALID_MIGRATION_ENTRY`.
- Discovery time (`MigrationLoader::discoverInNamespace()`): an FQCN entry that resolves to zero `MigrationInterfaceV2` classes logs a warning. We do NOT silently skip — operators see the no-match in logs and can fix typos or re-run `composer dump-autoload --optimize`.

### Composer classmap requirement

v2 namespace discovery uses `Composer\Autoload\ClassLoader::getRegisteredLoaders()` and consults each loader's classmap. PSR-4 prefixes are NOT walked manually; only the optimized classmap is consulted.

**Operators MUST run `composer dump-autoload --optimize`** for v2 manifest entries to discover their classes reliably. This matches existing project guidance documented in `CLAUDE.md`.

### Order semantics

- Within a package: entries traverse in array order. `MigrationLoader::loadAllV2()` and `loadAll()` both walk the manifest left-to-right.
- Across packages: manifest order matches Composer's `installed.json` order. The unified DAG (WP06) reorders nodes by their declared dependencies; raw discovery order is only the input.
- Within a path entry: `*.php` files load in lex order (existing pre-WP11 behaviour).
- Within an FQCN entry: classmap iteration order is implementation-defined; v2 plans should not depend on it. Use explicit `dependencies()` in `MigrationInterfaceV2` for ordering.

### Root-package identity and paths

The Composer root package is a first-class migration producer even though it is
absent from `vendor/composer/installed.json`. The compiler reads its
`extra.waaseyaa.migrations` declaration separately and keys it by the root
Composer `name`; a declaration without that identity fails closed. Relative
path entries for that package resolve against the project root.

For backward compatibility, an application with no declaration may still place
legacy migration files in `<projectRoot>/migrations`, loaded under the
historical `app` package key. If a root manifest path already resolves to that
directory, the declared migration also keeps the historical `app:*` ledger ID
and the fallback does not run. This applies to canonical path aliases too:
discovery upgrades must not replay existing `app:*` rows under the root Composer
name. Non-default root paths continue to use the root Composer identity.

### Coexistence with the string form

The string form is supported indefinitely (Q9). Internally `MigrationLoader::normalizeEntries()` converts the string to a single-element list so both shapes traverse the same code path. Mixing forms within one package is technically possible but not recommended — pick one shape per package for clarity.

## Consequences

- The pre-WP11 single-string contract is preserved. Existing Composer manifests work unchanged.
- New packages whose v2 migrations live as classes (e.g. mission #529's WP09 ledger schema migration) declare them via FQCN entries in the array.
- The `INVALID_MIGRATION_ENTRY` diagnostic code is part of the operator-facing surface; once shipped its string MUST NOT change.
- Heuristic ambiguity (Windows paths) is documented but not solved in v1; future ADR may add explicit override syntax.

## References

- Spec §8, §15 Q9 (`docs/specs/schema-evolution-v2.md`).
- WP11 work package, T065.
- `packages/foundation/src/Discovery/InvalidMigrationEntryException.php` — code holder.
- `packages/foundation/src/Migration/MigrationLoader.php` — implementation.
