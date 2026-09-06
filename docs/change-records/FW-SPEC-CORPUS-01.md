# FW-SPEC-CORPUS-01 — sanitized versioned agent spec corpus compiler

- Parent: `origin/main` at lease (`b701d5ad4`)
- Contract: `docs/specs/spec-corpus.md`, `docs/adr/022-ai-development-package-and-local-operator-trust-boundary.md` D-7
- Forge mirror: Framework #2661 (child of #2653, reuses #2641 stripping vocabulary)
- Authority: `docs/specs/` remains sole enduring specification tree; compiler is a read-only projection

## Finding

`bimaaji_search_specs` searches raw `docs/specs/*.md` when `bimaaji.specs_directory`
is configured. Superseded designs (e.g. `entity-storage-two-axis.md`) surface as
if current because the tool returns line snippets without lifecycle metadata.
ADR-022 D-7 keeps the tool on the allowlist but inert until a sanitized,
version-matched corpus exists (#2661 → #2662).

## Decision

1. Add `bin/compile-spec-corpus` and `tools/lib/SpecCorpus/*` as repository-owned
   tooling — not a second specification runtime, not a Packagist package surface.
2. Lifecycle values: `live`, `superseded`, `historical`, `draft`. Declared in YAML
   frontmatter (`waaseyaa-spec`) or a pilot manifest entry; manifest is the
   bounded pilot gate so ~110 specs need no bulk migration.
3. Default `index.json` includes **live** documents only. Other lifecycles compile
   to `documents/{id}.json` but are omitted from the default index.
4. Retrieval text strips `<!-- Spec reviewed … -->` blocks and internal execution
   links (`kitty-specs/`, `docs/history/`, `changes/`). Provenance retains the
   extracted material verbatim.
5. Deterministic heading-based chunks and SHA-256 digests for source, chunks, and
   corpus manifest. `corpus_digest` binds `corpus_version`, `framework_version`,
   and per-document title/lifecycle/path/digest metadata. `VERSION` supplies
   `framework_version`.
6. Fail closed on ids, paths, duplicates, YAML frontmatter (Symfony Yaml),
   conflicting declared metadata sources, supersession integrity, and output
   traversal. Publication requires a non-existent output target, stages the full
   tree, then performs one rename. `corpus_digest` is metadata identity only.

## Pilot scope (this slice)

Manifest `tools/spec-corpus-pilot-manifest.json` lists eight real specs covering
all four lifecycle values. Residual acceptance: migrate remaining ~102 specs via
incremental frontmatter as they are materially touched (#2229 rule); wire compiled
output into `bimaaji_search_specs` / FTS (#2662); optional preflight gate once
pilot stabilizes (proposed separately — not in this slice).

## Sequence

1. Architecture tests with fixture specs (failure classes first).
2. `SpecCorpusCompiler` library + `bin/compile-spec-corpus`.
3. Pilot manifest against real `docs/specs/` paths.
4. Issue fragment and spec contract.

## Boundaries

No CI/preflight roster edits, no `SearchSpecsTool` behaviour change, no bulk
frontmatter migration, no merge/release authority.

## Independent review and remediation

Root reproduced document-ID output traversal in a disposable fixture; that
candidate was blocked. Cursor added strict ID/source validation. Claude then
reproduced partial publication, missing headings and missing derived titles.
Output now requires an absent destination and publishes one staged directory;
heading capture and title fallback were repaired.

Root found that the purported stable-ID test asserted changed positional IDs.
The corrected regression compares the same Beta section before/after insertion:
RED `beta-2` versus `beta-3`, then GREEN with per-slug collision accounting.
Internal reference-style execution definitions now fail closed instead of
remaining in a supposedly sanitized corpus. These repairs are source-verified;
final qualification and review-candidate identity follow separately.

Sol's independent final-delta review then reproduced three remaining blockers.
One source path could be aliased under conflicting lifecycle IDs, index
verification trusted a copied digest without comparing the exact live entries,
and angle-wrapped inline destinations bypassed internal-link classification.
The repaired guard rejects duplicate normalized source paths; publication now
derives and compares the exact sorted live index from manifest metadata; and
inline angle destinations are unwrapped before classification while provenance
retains the original target. Each reproducer was captured as a failing test
(29 tests, 119 assertions, 3 failures) before the repairs; the focused corpus
suite is now green (29 tests, 122 assertions). The earlier qualification receipt
at `build/qualification/2661-final/receipt.json` remains valid evidence for the
old `5ebce945f` head only and was not overwritten. The repaired head requires
new exact-head qualification after this focused review candidate is committed.
