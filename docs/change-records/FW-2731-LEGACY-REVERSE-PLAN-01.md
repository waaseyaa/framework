# FW-2731 — legacy reverse-plan contract (fail-closed rollback)

- Issue mirror: `waaseyaa/framework#2731`
- Parent program: FW-ARCH-2026-08 / B04-R2 (`#2721`, `#2719`)
- Sibling already on main: `#2730` (replay / prior-state authority)
- Contract: `docs/specs/s1-schema-authority.md` §Rollback and verification
- Authority: this record + executable tests; forge is a discussion mirror

## Problem

`Migrator::rollback()` treated any override of `Migration::down()` as a
supported reverse plan. A shipped no-op override (for example
`waaseyaa/oidc:2026_05_25_000002_oidc_token_schema`) therefore:

1. reported `count=1`,
2. deleted the ledger row,
3. left schema and indexes in place,
4. left strict verify green against the emptied ledger.

A second failure of the same boundary: a **different** class body under the
same applied migration id could run as the reverse without matching the
ledger source checksum.

The reflection heuristic only proved that a method was declared. It proved
neither supported reversal, exact applied-source identity, nor post-state.

## Decision

Legacy rollback is fail-closed unless every node in the last batch has an
**explicit** supported reverse plan **and** the loaded source is the exact
applied source:

1. **Opt-in support** — `Migration::providesSupportedReverse()` defaults to
   `false`. Test fixtures and new migrations may return `true`.
2. **First-party catalogue** — checksum-bound historical migration files are
   **not** edited to opt in. The eight first-party migrations whose `down()`
   bodies actually reverse schema are listed in
   `LegacyReversePlanCatalog` by exact ledger migration id. No-op overrides
   stay out of the catalogue and therefore refuse under `[S1-DB104]`.
3. **Identity gate** — before any reverse mutation, each batch row must have
   a non-null ledger checksum equal to
   `MigrationCatalogFingerprint::legacySourceChecksum()` of the loaded
   source, and the loaded package must match the ledger package. Mismatch or
   null/unverifiable identity refuses with `[S1-DB113]`; the whole batch is
   unchanged.
4. **Post-state gate** — after `down()` and before ledger removal, the
   logical schema fingerprint must differ from the pre-reverse fingerprint.
   An ineffective reverse refuses with `[S1-DB114]` inside the coordinator
   transaction so schema, data, ledger, and manifest roll back together.
5. **Preflight** — support and identity checks run for every node in the
   batch before the first `down()`. A later node that would fail does not
   leave earlier nodes reversed.
6. **V2** — unchanged: V2 ids in the last batch still fail as missing legacy
   reverse source (`[S1-DB103]`) until a separate V2 reverse contract exists.
7. **Missing source** — retained (`[S1-DB103]`).

## Non-goals

- No silent force/adoption path, CLI wrapper, or body-emptiness heuristic.
- No casual edits to historical migration files (checksum-bound).
- No V2 reverse-plan implementation in this slice.
- No change to forward apply, `#2730` replay rechecks, or coordinator
  writer-first acquisition.

## Acceptance evidence

- Unit: no-op override refuses; changed source refuses; package mismatch
  refuses; null ledger checksum refuses; successful supported reverse still
  drops schema and ledger; multi-node batch refuses entirely when a later
  node is unsupported; ineffective opted-in reverse refuses post-state.
- Integration / retained red: real OIDC token-schema source refuses and
  preserves tables + ledger + verify.
- Spec `s1-schema-authority.md` §Rollback updated to name the contract and
  codes `[S1-DB104]`, `[S1-DB113]`, `[S1-DB114]`.
