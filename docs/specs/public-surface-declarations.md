# Public-surface declarations

Change record: `docs/change-records/FW-DELIVERY-SURFACE-01.md` (#2901).
Charter authority: `docs/specs/stability-charter.md` §2 (binary model), §2.5
(where classification lives), §8.1 (parity gate), §8.2 (changelog discipline).

## 1. Purpose

Package-local declaration files are the **single editable authority** for an
element's public/internal disposition. `docs/public-surface-map.php` and
`docs/public-surface-map.md` are **derived views**, composed deterministically
by `bin/generate-surface-map`. Independent package changes therefore never edit
a shared aggregate, and automation never invents an API commitment: a
disposition exists only because a package declared it.

## 2. Declaration plane

Path: `packages/<pkg>/public-surface.php`. Returns:

```php
<?php

declare(strict_types=1);

return [
    'entries' => [
        [
            'fqcn' => 'Waaseyaa\Foundation\Log\LoggerInterface',
            'disposition' => 'public',
            'purpose' => 'Structured logger with PSR-3-style severity levels (framework-internal, not psr/log)',
            // optional: issue, ADR or audit reference that decided the disposition
            'ref' => '#2437',
        ],
    ],
    // optional: documentation lines the generated doc renders verbatim under
    // this package. Notes carry no disposition and govern nothing.
    'notes' => [
        '`EntityType::__construct(...translatable: bool = false, ...)` (constructor arg): …',
    ],
];
```

Rules:

- `entries` is a **list**, not a keyed array, so a duplicated FQCN survives
  `require` and is rejected (§4) instead of silently collapsing to the last
  value.
- `fqcn` is complete and case-sensitive. `disposition` is one of
  `public|internal|extract|remove` (the existing vocabulary; `extract` and
  `remove` currently have no uses and remain valid).
- `purpose` is optional free text; `ref` is optional.
- A package with no contract shapes may omit the file. A package that has
  contract shapes must declare each one (§4 *missing*).
- Ownership: an FQCN may be declared only by the package whose Composer
  `autoload.psr-4` prefix is the longest prefix of that FQCN. The 719 entries
  (640 `public`, 79 `internal`) migrated from the previous aggregate all have
  exactly one owner.

## 3. Composition

`Waaseyaa\Tooling\SurfaceDeclarations` (`tools/lib/SurfaceDeclarations.php`):

- `load(string $root): Declarations` — reads every `packages/*/public-surface.php`,
  validates each file's shape, and records file and index for every entry.
- `loadAt(string $root, string $ref): Declarations` — the same from a git ref
  (`git ls-tree` + `git show`), used for merge-base comparison.
- `compose(): array<string, string>` — the `FQCN => disposition` map, keys
  sorted with `strcmp`. This is the value the generated `public-surface-map.php`
  returns, so `require` consumers are unchanged.
- `owner(string $fqcn): ?string` — the owning package by PSR-4.

`tools/lib/compose-public-surface.php` is the path-based entry point: `require`
it and receive the composed `FQCN => disposition` map. Package-local tests use it
exactly as they once required the aggregate, without naming a `Waaseyaa\Tooling`
class (`bin/check-package-layers` PL010 forbids package tests referencing a
namespace that is not a PSR-4 package root).

`Waaseyaa\Tooling\SurfaceScanner` (`tools/lib/SurfaceScanner.php`) is the
existing php-parser walk lifted out of the gate: it discovers every
interface, abstract class, trait and enum under `packages/*/src` (the same
contract shapes as before; concrete classes are still not inferred). It also
reports the *shape* of any loadable FQCN (`interface`, `abstract class`,
`trait`, `enum`, `final class`, `final readonly class`, `class`, `readonly
class`) for the generated doc, so shape is never declared and cannot
contradict source.

## 4. Validation (fail closed)

Composition fails, naming every offender, on:

| Failure | Definition |
|---|---|
| **missing** | a scanned contract shape with no declaration |
| **duplicate** | the same FQCN twice in one package file |
| **orphaned** | a declared FQCN that no PSR-4 prefix of the declaring package owns, or that does not load |
| **contradictory** | the same FQCN declared by two packages — with different dispositions *or the same one*; one declaration plane means one declaration |
| **invalid** | unknown disposition, non-list `entries`, non-string fields, a file that does not return an array |

## 5. Authorization (unchanged semantics)

The gate composes the merge base's declarations and HEAD's and applies the
existing `SurfaceChangeAuthorization` rules to the two composed maps:

- a removed entry requires a newly-added `- Public surface removal:` or
  `- Public surface rename:` directive under the matching fragment type;
- a `public` → non-public change requires a newly-added
  `- Public surface deprecation:` directive;
- only fragments added by the candidate carry authority; fragments already on
  the base and historical `CHANGELOG.md` sections authorize nothing.

Charter §8.1's directive grammar is unchanged.

## 6. Generated views and the tracked/generated boundary

`bin/generate-surface-map`:

- `--write` regenerates `docs/public-surface-map.php` and
  `docs/public-surface-map.md`;
- `--check` exits non-zero if either tracked file differs from a fresh
  generation (the deterministic-bytes proof);
- `--stdout=php|md` prints one view without touching the tree.

Determinism: entries sorted by FQCN; packages grouped by the layer table in
`bin/check-package-layers` then by package name; LF line endings; a generated
header naming this spec. Generating twice yields identical bytes. Each md row
carries `Element | Type | Disposition | Purpose`: the human view lists
`internal` entries too, so the disposition column is what distinguishes a
commitment from a non-commitment — a row is never a commitment by presence
alone.

**Boundary rule.** A pull request does not commit the aggregates. The parity
gate accepts each tracked aggregate only when it is byte-identical to either
(a) the merge base's tracked bytes — the aggregate may lag — or (b) a fresh
generation from HEAD's declarations. Any other content is a hand edit and
fails. The governed release cut runs `bin/generate-surface-map --write` and
commits both files alongside `CHANGELOG.md`, so every release ships current
views. `bin/refresh-governance-artifacts` keeps `surface-parity` a *manual*
(judgment) gate — its failures are missing declarations or unauthorized
removals, which no write mode can repair — and its instruction names
`php bin/generate-surface-map --write` as the only way to refresh a stale
aggregate locally.

Consequence for readers: between releases `docs/public-surface-map.md` may be
behind main by at most one release train, exactly like `CHANGELOG.md`.

## 7. Gate

`tools/check-surface-parity.php --base=<ref>` (same path; the preflight roster
and `.github/workflows/surface-parity.yml` keep their references):

1. load and validate HEAD's declarations (§4);
2. scan source; report *missing* and *orphaned*;
3. load the merge base's declarations; apply §5 to the two composed maps.
   A merge base that carries no `packages/*/public-surface.php` at all (one
   that predates this plane) contributes its tracked
   `docs/public-surface-map.php` instead — the authority it actually held —
   so the comparison base is never an empty map. A base with neither is an
   infrastructure failure (exit 2), never a silent pass;
4. apply the §6 boundary rule to both tracked aggregates.

Exit codes are unchanged: `0` parity, `1` drift or unauthorized change, `2`
infrastructure (parser, git, malformed declaration file).

## 8. Consumers

| Consumer | After this change |
|---|---|
| `tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php` | reads the composed declarations; the source-regex duplicate-key test is replaced by §4 *duplicate* |
| `tests/Architecture/GenerationErrorCodeBoundaryTest.php` | reads the composed declarations (asserts a disposition a PR may have just declared) |
| `packages/{page-builder,publishing,seo}/tests/...PublicSurface*Test.php`, `SitemapPathTest`, `DiscoverySurfaceGovernanceTest` | `require` `tools/lib/compose-public-surface.php` (live composition, never the lagging aggregate); the seo pair keeps its split-form skip, predicated on that file |
| `tools/check-changelog-discipline.sh` | `packages/*/public-surface.php` is a public-surface file |
| `.github/workflows/surface-parity.yml` | triggers on `packages/*/public-surface.php` |
| `bin/check-external-consumers`, spec cross-references | unchanged (the aggregate path still exists) |

## 9. Distribution and packaged form

`packages/<pkg>/public-surface.php` ships with its package in the split
mirrors: it is small, has no runtime reader, and is not under `src/`, so the
manifest compiler's PSR-4 class scan never loads it. The aggregates remain
monorepo-only, so the governed skips in `tools/phpunit-skip-policy.json` for
the split SEO repository keep their predicate and rationale.

## 10. Migration guide

For maintainers:

1. `bin/migrate-surface-map` (one-off, kept for provenance) joined the
   previous `docs/public-surface-map.php` (dispositions, authoritative) with
   `docs/public-surface-map.md` (purpose text, where a row named a governed
   FQCN) and wrote one declaration file per owning package. Rationale comments
   in the old PHP map became `ref` fields where they cited an issue or ADR.
2. The 33 md rows that named no governed FQCN (27 concrete/interface
   elements the prior map never dispositioned, 6 method/argument rows)
   became package `notes`. They govern nothing and
   broaden nothing; an owner who wants one governed declares it with a
   `public` or `internal` entry and the ordinary review.
3. `docs/public-surface-map.php` / `.md` are now generated. Do not edit them.

For a package adding or changing a contract shape:

1. Add or edit the entry in your own `packages/<pkg>/public-surface.php`.
2. Add the changelog fragment as before; removal, rename and downgrade need
   the §5 directive.
3. Do **not** run `bin/generate-surface-map --write` in your PR unless you
   want to; the gate accepts a lagging aggregate. The release cut regenerates.

For a package outside the monorepo (split form): nothing changes; the file
travels with the package and no runtime code reads it.

## 11. Acceptance mapping (#2901)

| Acceptance | Where proven |
|---|---|
| every disposition preserved; no broadening | migration-commit evidence in the change record (719 = 719, no missing, no extra, no value differences); `SurfaceMigrationFidelityTest` runs the real migrator twice in a disposable package fixture and proves the immutable 719-entry pre-migration snapshot composes back to the exact same mapping, while later live surface changes remain governed only by §5 |
| same contract shapes as the AST gate; missing/duplicate/orphaned/contradictory rejected; unauthorized removal/reclassification rejected | `SurfaceDeclarationValidationTest` fixtures against the real gate |
| stable ordering and bytes; consumers migrated; packaged behaviour preserved | `bin/generate-surface-map --check` in preflight; §8, §9 |
| current-change authorization only | unchanged `SurfaceChangeAuthorization` + gate fixtures |
| two independent additions combine without a hand-edited aggregate; conflict fails closed | `SurfaceDeclarationCompositionTest` |
| one canonical plane, migration guide, tested deterministic command | §2, §10, §6 |
