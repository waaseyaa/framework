# FW-DELIVERY-SURFACE-01 — package-local public-surface declarations

- Issue: `#2901` (programme `#2527`, wave "efficient parallel agent delivery")
- Contract: `docs/specs/public-surface-declarations.md`
- Charter: `docs/specs/stability-charter.md` §2.5, §8.1 (amended by this record)
- Authority: repository-tracked source changes; forge-neutral

## Problem

`docs/public-surface-map.php` (704 dispositions across 53 packages) and its
companion `docs/public-surface-map.md` are edited by every package that adds a
contract shape. Two independent package changes therefore collide on the same
aggregate file, and each rebase re-imports the other's rows. The map is
authoritative for `public|internal` and cannot be inferred from syntax, so the
fix cannot be "generate everything": automation must never invent an API
commitment.

## Decision

1. **One editable plane.** Each package declares its own dispositions in
   `packages/<pkg>/public-surface.php`, a list of entries (`fqcn`,
   `disposition`, optional `purpose`, optional `ref`) plus optional free-text
   `notes`. A list — not a keyed array — so duplicate FQCNs survive `require`
   and are rejected instead of silently collapsed.
2. **Ownership is checkable.** An FQCN may be declared only by the package
   whose Composer PSR-4 prefix owns it. Every existing entry has exactly one
   owner (verified: 0 orphans at `5bac44286`). Declaring another package's
   symbol is an *orphaned* declaration and fails.
3. **Contradiction fails closed.** The same FQCN declared by two packages
   (even with the same disposition) is rejected; the message names both.
4. **The aggregates become derived.** `bin/generate-surface-map` composes
   the declarations into `docs/public-surface-map.php` (same return shape, so
   every `require` consumer keeps working) and `docs/public-surface-map.md`
   (layer → package → sorted symbols, shape derived from the AST/reflection,
   purpose from the declaration, notes rendered verbatim). Ordering and bytes
   are deterministic; `--check` proves it.
5. **The tracked/generated boundary.** PRs do not commit the aggregates.
   The parity gate accepts the tracked aggregate only if it is byte-identical
   to the merge base's **or** to a fresh generation from HEAD's declarations —
   never a hand edit. The governed release cut regenerates and commits both
   files, exactly as it compiles `CHANGELOG.md` from `changes/unreleased/`.
   Between releases the aggregate may lag; every authority check reads the
   declarations, not the aggregate.
6. **Authorization is unchanged in substance.** Removal, rename and public
   downgrade are compared between the merge base's *composed declarations*
   and HEAD's, and require the same newly-added exact-FQCN changelog-fragment
   directive as before (`SurfaceChangeAuthorization`). Historical changelog
   entries still authorize nothing. `extract` and `remove` remain valid
   dispositions with zero current uses.
7. **No broadening.** The migration carries the 704 dispositions verbatim.
   The 38 md rows that were never dispositioned (31 concrete classes, 7 prose
   rows) are carried as package `notes`, not as new dispositions.

## Slice

Single review candidate: declaration library and scanner, generator, gate
rewrite at the same path (`tools/check-surface-parity.php`, so the preflight
roster and `surface-parity.yml` keep their references), 53 package declaration
files produced by a one-off migration script and then checked in, consumer
migration, contract spec with migration guide, charter amendment, and the
composition proof required by the acceptance criteria.

Shared files owned by Codex for this wave (`tools/preflight-gates.json`,
`.github/workflows/release-cut.yml`, `docs/specs/governed-gates.md`) are not
edited here; an exact integration patch is attached to the review candidate.

## Evidence

Recorded on the review candidate: red output of the composition proof before
the implementation; `bin/generate-surface-map --check` on the migrated tree;
byte-equality of two consecutive generations; the gate's rejection of a
contradictory two-package fixture; preflight and the three suites on the exact
candidate head.
