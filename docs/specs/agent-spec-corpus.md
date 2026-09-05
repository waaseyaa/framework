# Versioned, sanitized agent specification corpus

Status: DESIGN REVIEW CANDIDATE

Change record: `FW-AGENT-SPEC-CORPUS-01`

Forge mirror: #2661 (parent #2653; reuses #2641 evidence)

## Purpose

Waaseyaa's enduring specifications live only under `docs/specs/`. A packaged
consumer does not receive those sources at a location Bimaaji can use, and a
raw directory scan cannot distinguish a live contract from a superseded,
historical, or draft document. This contract defines a deterministic compiler
that projects the authoritative sources into a sanitized package resource for
later offline search.

The projection is content-addressed and lifecycle-labelled. It is not a new
specification authority: source prose remains authoritative in `docs/specs/`,
and the lifecycle catalog described below is itself part of that same tree.
Generated corpus files are derived views and MUST be reproducible from those
inputs.

## Scope and non-goals

This slice owns:

- a closed lifecycle catalog for Markdown below `docs/specs/`;
- deterministic sanitization and chunking;
- a live-only default index plus separately labelled non-live material;
- source, document, chunk, and corpus digests; and
- a packaged resource under `packages/bimaaji/resources/spec-corpus/v1/`.

This slice does not activate `bimaaji_search_specs`, build an FTS database,
change `SpecIndexProvider`, configure `bimaaji.specs_directory`, add a client
adapter, add an MCP surface, or introduce a workflow/specification runtime.
Search, citations that include the installed package/framework version, empty
corpus diagnostics, and the ADR-022 D-7 activation review belong to #2662.
Raw `docs/specs/` sources and the compiled resource keep separate distribution
ownership for #2648/#2650.

## Observed repository constraints

1. At the pinned base, `docs/specs/` contains 110 top-level Markdown files and
   two nested Markdown files. Status prose is not machine-readable or uniform: examples
   include `LIVE`, `Shipped`, `Draft`, `Review candidate`, and explicit
   supersession banners.
2. Fifty-two specification files contain append-only `<!-- Spec reviewed ...
   -->` comments. #2641 proves those comments deliberately retain stale
   historical statements and therefore cannot enter current retrieval text.
3. `SpecIndexProvider` currently enumerates top-level `*.md` files as paths and
   `SearchSpecsTool` reads them later. It neither carries lifecycle in the
   result nor packages the source directory. ADR-022 therefore keeps the tool
   listed but inert until #2661 and #2662 land.
4. `bin/lib/repository-files.php` is the repository-owned, environment-scrubbed
   way to enumerate tracked plus untracked-not-ignored files. A compiler MUST
   use that repository boundary rather than a recursive filesystem walk.
5. The monorepo root Composer version is not release identity. A generated
   file cannot embed the commit that contains itself. Corpus identity therefore
   derives from bytes; #2662 reports the installed package/framework version
   alongside that identity at query time.

## R1 — authoritative lifecycle catalog

`docs/specs/agent-corpus.json` is the only authored compiler input besides the
Markdown sources. It is metadata within the existing specification authority,
not a copy of specification prose. The document has this closed v1 shape:

```json
{
  "schema": "waaseyaa.agent-spec-catalog.v1",
  "documents": [
    {
      "id": "revision-system-unified",
      "path": "docs/specs/revision-system-unified.md",
      "lifecycle": "live",
      "supersedes": ["entity-storage-two-axis"],
      "superseded_by": []
    },
    {
      "id": "entity-storage-two-axis",
      "path": "docs/specs/entity-storage-two-axis.md",
      "lifecycle": "superseded",
      "supersedes": [],
      "superseded_by": ["revision-system-unified"]
    }
  ]
}
```

All five keys on each entry are required and no additional key is accepted.
`id` matches `^[a-z][a-z0-9-]*$`. `path` is a canonical repository-relative
path under `docs/specs/`, with `/` separators and no `.` or `..` segments.
Documents are sorted by `id`; every ID and path is unique.

Every regular, non-symlink Markdown file returned by repository enumeration
under `docs/specs/` MUST appear exactly once, recursively. This includes
catalog/readme material: if it is retained only for context, it is classified
`historical`. A missing catalog row is a refusal, never an implicit live
default. A catalog row whose file is missing, ignored, outside the repository,
or a symlink is also a refusal. The catalog JSON itself is not a Markdown
document and is not recursively catalogued.

The initial per-file classification is a human review deliverable of the
implementation candidate. This design intentionally does not infer it from
today's free-form `Status:` prose.

## R2 — lifecycle graph

The exact lifecycle vocabulary is:

| Value | Meaning | Default search |
| --- | --- | --- |
| `live` | Current normative contract. | Included |
| `superseded` | Retained contract replaced by one or more live documents. | Excluded |
| `historical` | Retained context with no current normative force and no replacement claim. | Excluded |
| `draft` | Proposed contract that has not become current authority. | Excluded |

The compiler enforces the graph rather than trusting labels:

- A `superseded` document has a non-empty `superseded_by` list. Every target
  exists, is `live`, and reciprocally lists the source in `supersedes`.
- A `live` document may list one or more `supersedes` entries; every source is
  `superseded` and reciprocally names the live target.
- `historical` and `draft` documents have empty `supersedes` and
  `superseded_by` lists. Contextual relationships stay in prose.
- Self-links, duplicate links, missing targets, asymmetric links, chains to a
  non-live replacement, and cycles are refusals.

A transition from live to superseded and its live replacement MUST enter the
same candidate so main never carries a dangling replacement. A draft becomes
live by changing its catalog row. A current contract with no replacement may
become historical. Version 1 does not support deleting or renaming a catalogued
document: retain it as superseded/historical, or design a later migration that
preserves citation identity.

## R3 — sanitization boundary

The compiler reads UTF-8 Markdown with LF endings. A BOM, NUL, invalid UTF-8,
CR byte, unreadable file, or unclosed fenced code block/comment is unsupported
and fails closed.

Sanitization is line-preserving: removed material leaves the same number of
newline characters so output citations continue to name source line numbers.
It performs these operations in order:

1. Outside fenced code blocks and inline code spans, remove complete HTML
   comments whose first non-whitespace words are `Spec reviewed`
   (case-sensitive). Other HTML comments are retained unless explicitly
   bounded by the markers below.
2. Remove explicitly marked execution-only spans between exact paired markers
   `<!-- agent-corpus:exclude:start -->` and
   `<!-- agent-corpus:exclude:end -->`. Nesting, an unmatched marker, or a
   marker inside a code fence is a refusal.
3. Outside fenced and inline code, replace an internal execution Markdown link
   with its visible label and remove an internal bare/autolink URL. Internal execution
   targets are exactly: `kitty-specs/**`, `docs/history/**`,
   `docs/change-records/**`, `changes/**`, `.github/**`, `work/**`, absolute
   local paths, `file:` URLs, Claude session URLs, and Waaseyaa Framework
   GitHub issue, pull, Actions, or commit URLs. Relative links within
   `docs/specs/**` and external standards/reference links remain.

The compiler does not delete issue numbers or prose merely because it sounds
historical. #2641 showed that tense/phrase heuristics are too noisy for a
blocking decision. Authors use lifecycle metadata or the exact exclusion
markers when a whole prose span is execution-only.

No removed comment body, excluded span, local path, or removed link destination
may occur in any generated file. Provenance is retained structurally as the
source path, source line range, source digest, lifecycle, supersession IDs, and
sanitization counts. The generated manifest records counts and digests, never
the removed bytes.

## R4 — deterministic documents, chunks, and identity

All ordering uses bytewise ascending order under the C locale. JSON uses UTF-8,
unescaped slashes/Unicode, lexicographically sorted object keys, and one final
LF. JSONL uses that canonical object encoding with one record per LF-terminated
line. Timestamps, host paths, locale, timezone, filesystem order, Git branch,
and network state are not inputs.

For each catalog row the compiler records:

- `source_digest`: SHA-256 of the exact source bytes;
- `sanitized_digest`: SHA-256 of the complete sanitized UTF-8 text;
- `document_digest`: SHA-256 of canonical JSON containing the catalog row,
  `source_digest`, and `sanitized_digest`; and
- `document_version`: `v1-sha256-` followed by `document_digest`.

The corpus manifest maps every document version to its exact source and
sanitized digests. The mapping is verified on every read; a version/digest
mismatch is a refusal. There is no manually mutable version alias.

Sanitized text is divided by ATX headings of levels 1–3. A heading begins a
section; its body is packed in source order from complete lines into chunks of
at most 8,192 UTF-8 bytes. Heading lines are included. A fenced code block is
atomic. A single source line or fenced block larger than the limit is
unsupported and fails rather than changing boundaries heuristically.
Blank-only chunks are omitted, but a document whose sanitized text has no
non-whitespace content is refused.

Each chunk carries the closed shape:

```json
{
  "schema": "waaseyaa.agent-spec-chunk.v1",
  "id": "sha256:<full chunk digest>",
  "document_id": "revision-system-unified",
  "document_version": "v1-sha256-<full document digest>",
  "lifecycle": "live",
  "ordinal": 0,
  "heading": ["Revision system", "Storage identity"],
  "source_path": "docs/specs/revision-system-unified.md",
  "source_start_line": 1,
  "source_end_line": 42,
  "text": "..."
}
```

The chunk digest covers canonical JSON of every field except `id`. Chunks sort
by `(document_id, ordinal)`. `corpus_digest` is SHA-256 over canonical JSON of
the format version, complete validated catalog, document records, and ordered
chunk digests. `corpus_version` is `v1-sha256-<corpus_digest>`. Loading code
MUST recompute these identities; filenames or manifest claims alone are not
trusted.

## R5 — generated resource layout and live-only index

The v1 output is exactly:

```text
packages/bimaaji/resources/spec-corpus/v1/
  manifest.json
  chunks.live.jsonl
  chunks.nonlive.jsonl
```

`manifest.json` contains the schema/corpus version and digest, all document
records, lifecycle graph, chunk counts, and sanitization counts.
`chunks.live.jsonl` contains only chunks whose catalog lifecycle is `live`.
`chunks.nonlive.jsonl` contains `superseded`, `historical`, and `draft` chunks,
each with its explicit lifecycle. A lifecycle appearing in both files, an
unknown lifecycle, an empty live index, or count/digest disagreement is a
refusal.

This physical separation is the default-index control. #2662 may build FTS5
from `chunks.live.jsonl` by default and may consult `chunks.nonlive.jsonl` only
after an explicit labelled lifecycle filter. It MUST NOT reconstruct the
default by searching all chunks and filtering after ranking.

The resource contains sanitized chunks and provenance metadata, not raw
Markdown. `waaseyaa/bimaaji` owns the resource because it owns
`SpecIndexProvider`; the compiler may remain repository tooling. Merely shipping
the directory does not configure or activate a runtime search path.

## R6 — command and update behavior

The implementation MUST provide check and write modes over the same compiler.
Exact command/class names are proposed, not settled; the expected repository
shape is `bin/compile-agent-spec-corpus --check|--write` plus a focused library
under `bin/lib/`.

- Check mode compiles in memory, validates the checked-in resource, and exits
  `0` only for byte-identical output.
- Write mode builds into a newly created temporary sibling and validates it
  before publication. It swaps the output directory through a same-filesystem
  backup/rename sequence and restores the prior directory if publication
  fails. A validation refusal never touches the tracked output; a publish or
  restore failure is an exit-2 infrastructure fault and leaves recovery paths
  named in the diagnostic.
- Exit `1` means a source/catalog/generated-artifact contract violation. Exit
  `2` means invalid invocation or compiler I/O/infrastructure failure. Messages
  name the stable document ID/path and rule, never removed text.
- The check joins the governed preflight/CI roster. Write mode may be composed
  by `bin/refresh-governance-artifacts`; that integration is an implementation
  choice, but there MUST be only one writer.
- The compiler has no network access, issue-state lookup, GitHub dependency,
  database, embedding model, or workflow runtime.

## Acceptance scenarios

### A1 — live and superseded material stay separated

- GIVEN the two catalog rows in R1 and sources containing the same search term
- WHEN the corpus compiles
- THEN only the unified document's chunks occur in `chunks.live.jsonl`
- AND the retired two-axis document occurs in `chunks.nonlive.jsonl` labelled
  `superseded`
- AND both rows and their reciprocal link occur in `manifest.json`.

### A2 — an unclassified source fails closed

- GIVEN a new untracked-not-ignored `docs/specs/new-contract.md`
- AND no corresponding catalog row
- WHEN check or write mode runs
- THEN it exits `1`, names `new-contract.md` as unclassified, and leaves all
  generated bytes unchanged.

### A3 — review and execution material cannot leak

- GIVEN a source with a multiline `Spec reviewed` comment, a fenced-code
  example containing the same literal text, a Waaseyaa issue link, a
  `kitty-specs/` link, and an external standards link
- WHEN it compiles
- THEN the real review comment and internal destinations are absent
- AND fenced/inline-code literals, internal link labels, the external link,
  source line range, removal counts, and source digest remain.

### A4 — version and bytes cannot disagree

- GIVEN one byte changes in a source while a prior generated resource remains
- WHEN check mode runs
- THEN source, sanitized/document/chunk/corpus digests are recomputed
- AND the prior document/corpus versions are rejected
- AND exit is `1` rather than success with stale citations.

### A5 — output is host-independent

- GIVEN the same catalog and source bytes in two clean checkouts at different
  absolute paths, locales, timezones, and filesystem enumeration orders
- WHEN each compiles
- THEN all three generated files are byte-identical.

### A6 — invalid lifecycle graph cannot publish

- GIVEN a superseded row with no replacement, a missing/asymmetric target, a
  cycle, or a non-live replacement
- WHEN either mode runs
- THEN it exits `1` before writing output and identifies the offending IDs.

### A7 — packaged resource remains inert in this slice

- GIVEN a consumer installs the resulting `waaseyaa/bimaaji` package
- WHEN no `bimaaji.specs_directory` or #2662 adapter exists
- THEN this slice registers no new tool, command, HTTP route, MCP method, or
  automatic filesystem read.

## Planned verification mapping

| Requirement | Executable evidence required from implementation |
| --- | --- |
| R1/R2 catalog and lifecycle graph | Data-driven unit tests for every shape, path, completeness, reciprocity, target-state, cycle, transition, deletion, and rename refusal |
| R3 sanitization | Golden byte fixtures covering multiline review comments, fences, exact markers, every internal-target class, external/spec links, malformed constructs, line preservation, and removed-byte absence across every generated file |
| R4 identities/chunks | Determinism tests with reordered enumeration, duplicate headings, Unicode, exact 8,192-byte boundary, oversized line/fence refusals, and one-byte digest avalanche |
| R5 live separation | Fixture corpus proving live-only and labelled non-live files plus manifest count/digest recomputation |
| R6 atomicity/failures | Subprocess tests for check/write exits, stale output, read/write failure, hostile Git environment, different checkout roots, and byte-identical repeated writes |
| Package boundary | Packaged-form assertion that the sanitized resource ships, raw `docs/specs/` is not its substitute, and this slice does not activate search |

## Decisions still requiring implementation review

1. The exact lifecycle assignment of each existing specification is not
   inferred here. The implementation candidate must present the complete
   catalog as a reviewable table/diff, with special attention to documents
   whose prose says `Draft`, `Review candidate`, `Shipped`, or `Superseded`.
2. `bin/compile-agent-spec-corpus` and its library names are proposed. Review
   may choose equivalent repository-tooling names without changing the wire
   formats or requirements above.
3. #2662 owns the SQLite schema, query/ranking contract, explicit lifecycle
   filter vocabulary, installed-version reporting, and migration from today's
   path-based `SpecIndexProvider`. Those choices cannot weaken the live-only
   physical boundary or digest validation.
4. #2650/#2648 own root-dist and export allowlists. They must decide whether raw
   `docs/` ships in the root artifact; this contract only requires the compiled
   Bimaaji resource to remain separately identified and budgeted.
