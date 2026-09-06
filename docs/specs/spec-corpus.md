# Specification corpus compiler

**Status:** Pilot (FW-SPEC-CORPUS-01, #2661).
**Audience:** framework maintainers, local-operator tooling authors.
**Authority:** `docs/specs/` remains the sole enduring specification tree. The
compiled corpus is a read-only, versioned projection for agent retrieval — not a
second specification runtime.

## Purpose

Authoritative specs compile into a sanitized, version-matched corpus so local
agents retrieve **live** contracts without mixing superseded designs, historical
audit context, draft work, or internal workflow notes.

## Lifecycle

| Value | Meaning | Default index |
|---|---|---|
| `live` | Current contract | Included |
| `superseded` | Replaced by another spec (`superseded_by` required) | Excluded |
| `historical` | Retained for audit; not the current contract | Excluded |
| `draft` | Work in progress; not publication-ready | Excluded |

Supersession links are validated fail-closed: `superseded_by` MUST name a compiled
document with lifecycle `live`.

## Declaration

**Frontmatter** (preferred when a spec is materially touched):

```yaml
---
waaseyaa-spec:
  lifecycle: live
---
```

```yaml
---
waaseyaa-spec:
  lifecycle: superseded
  superseded_by: revision-system-unified
---
```

**Pilot manifest** (`tools/spec-corpus-pilot-manifest.json`) supplies lifecycle for
listed specs without frontmatter. Only manifest-listed specs compile during the
pilot; bulk migration of the full `docs/specs/` tree is explicitly out of scope.

## Compilation

```bash
php bin/compile-spec-corpus [--root DIR] [--manifest FILE] [--output DIR]
```

Output layout:

```
{output}/
  manifest.json    # corpus_version, framework_version, corpus_digest, per-document metadata
  index.json       # live-only default index
  documents/{id}.json
```

Each `documents/{id}.json` carries:

- `lifecycle`, supersession links, `source.path`, `source.digest`
- `provenance` — extracted spec-reviewed comments and internal execution links
- `retrieval_text` — body with those elements removed
- `chunks` — deterministic `##` / `###` sections with stable ids and digests

## Sanitization

Retrieval text MUST omit:

1. `<!-- Spec reviewed … -->` HTML comments (same class #2641 skips for deferral
   scans).
2. Markdown links to internal execution artefacts. Targets are normalized from
   the `docs/specs/` base (`../history/...` resolves like `docs/history/...`)
   and match when they begin with `kitty-specs/`, `docs/history/`, or `changes/`.
   Standard angle-wrapped inline destinations (`[label](<target>)`) are
   normalized before classification.
   External URLs (`https://…`), mailto links, and in-document anchors (`#…`)
   are preserved. Reference-style links (`[label][ref]`) are recorded in
   provenance as unsupported and are not stripped.

Provenance MUST retain the removed comments and links verbatim.

## Path, identity, and publication safety

- Manifest document ids MUST match `[a-z][a-z0-9_-]*` — no path separators.
- Spec source paths MUST be regular files directly under `docs/specs/*.md`.
  Symlink paths are rejected.
- Duplicate manifest ids or source paths, unknown lifecycles, unsupported `corpus_version`
  (only `"1"` today), empty `VERSION`, or conflicting declared frontmatter and
  manifest metadata abort compilation.
- `corpus_digest` binds `corpus_version`, `framework_version`, and per-document
  `title`, `lifecycle`, `source_path`, `source_digest`, and `superseded_by`. It is
  a metadata-identity digest only — not a seal of published retrieval bodies or
  chunk text. `#2662` must verify full content before trust.
- `verifyCompiledDigest()` recomputes that metadata identity and checks
  manifest/index agreement immediately before publication.
- Publication requires the output target path to not exist yet (no empty
  directories, files, or symlinks). The compiler writes a complete staging tree,
  then performs a single rename into place. Failed runs remove staging and leave
  no output target behind.

## Determinism and fail-closed rules

- Recompiling unchanged inputs yields identical `corpus_digest` and chunk
  digests.
- `VERSION` at the repository root supplies `framework_version`.
- Unknown lifecycle, missing `superseded_by` on a superseded spec, or a
  `superseded_by` target that is missing or not `live` aborts compilation.

## Non-goals (pilot)

- Wiring compiled output into `bimaaji_search_specs` or FTS5 (#2662).
- Scanning `docs/history/plans/` or `kitty-specs/`.
- Bulk frontmatter migration of untouched specs (#2229 incremental rule).

## Verification

- `tests/Architecture/SpecCorpusCompilerTest.php` — fixture proofs for lifecycle,
  sanitization, supersession validation, live-only index, and digest stability.

### Stable section identities and reference-link boundary

Unique heading slugs retain their chunk IDs when unrelated sections are inserted.
Only repeated or colliding slugs receive local numeric suffixes; repeated identical
headings still depend on order within that collision group.

The pilot handles inline execution links. Internal reference-style definitions
are refused before publication and must be converted to inline links; external
reference links remain unchanged. Unsupported syntax is not evidence that the
source was sanitized. Full Markdown parsing remains a subsequent bounded change.
