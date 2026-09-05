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

1. This design candidate makes `docs/specs/agent-spec-corpus.md` the 111th
   top-level Markdown file; together with two nested Markdown files, the
   recursively enumerated corpus contains 113 sources. Status prose is not
   machine-readable or uniform: examples include `LIVE`, `Shipped`, `Draft`,
   `Review candidate`, and explicit supersession banners.
2. Fifty-three specification files contain append-only `<!-- Spec reviewed ...
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
  "documents": [
    {
      "id": "entity-storage-two-axis",
      "lifecycle": "superseded",
      "path": "docs/specs/entity-storage-two-axis.md",
      "superseded_by": ["revision-system-unified"],
      "supersedes": []
    },
    {
      "id": "revision-system-unified",
      "lifecycle": "live",
      "path": "docs/specs/revision-system-unified.md",
      "superseded_by": [],
      "supersedes": ["entity-storage-two-axis"]
    }
  ],
  "schema": "waaseyaa.agent-spec-catalog.v1"
}
```

The top-level keys are exactly `documents` and `schema`; `schema` has the exact
value shown. All five keys on each entry are required and no additional key is
accepted. `id` matches `^[a-z][a-z0-9-]*$`. `path` is a canonical
repository-relative path under `docs/specs/`, with `/` separators and no `.`
or `..` segments. Documents are sorted by `id`; every ID and path is unique.
`supersedes` and `superseded_by` are duplicate-free lists sorted by ID. The
catalog itself uses the canonical JSON encoding in R4 and ends in one LF.

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

The compiler validates one candidate's complete current state. It can refuse a
source without a row or a row without a source, but it has no prior-state
authority from which to infer that a source and its row were deleted together,
or that a row changed lifecycle in the same candidate. Review of the catalog
and source diff is therefore the authority for those changes; the compiler
MUST NOT claim to detect them by consulting Git history, a mutable cache, or a
previous generated corpus. Ordinary Markdown byte changes are accepted as
current-state inputs and produce new document and corpus versions; they do not
constitute lifecycle transitions.

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
same reviewed candidate so main never carries a dangling replacement. The
compiler proves that the candidate's final graph is closed; required review
proves the transition is atomic relative to its accepted parent. A draft
becomes live by changing its catalog row. A current contract with no
replacement may become historical after explicit review. Version 1 does not
support deleting or renaming a catalogued document: retain it as
superseded/historical, or approve a later format migration that preserves
citation identity. Paired source-and-row deletion or a path change is a
governance refusal visible in the candidate diff, not a stateless compiler
diagnostic.

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
   with its visible label, remove an internal bare/autolink URL, and remove a
   bare absolute host-local filesystem path. Internal execution targets are
   exactly: `kitty-specs/**`, `docs/history/**`, `docs/change-records/**`,
   `changes/**`, `.github/**`, `work/**`, absolute host-local filesystem
   paths, `file:` URLs, Claude session URLs, and Waaseyaa Framework GitHub
   issue, pull, Actions, or commit URLs. A host-local path is recognized only
   as a Windows drive-rooted or UNC path, or a POSIX path rooted at `/home`,
   `/Users`, `/tmp`, `/private/tmp`, or `/mnt/<drive-letter>`. A
   site-root URI such as `/admin` is not a filesystem path.
   Repository-relative provenance and links within `docs/specs/**` remain, as
   do external standards/reference links.

The compiler does not delete issue numbers or prose merely because it sounds
historical. #2641 showed that tense/phrase heuristics are too noisy for a
blocking decision. Authors use lifecycle metadata or the exact exclusion
markers when a whole prose span is execution-only.

A removed occurrence contributes no bytes to generated retrieval text or
metadata. The compiler does not claim that the same byte sequence is globally
absent when it also occurs in retained prose, a code span, or a fenced example.
Repository-relative source provenance is retained structurally as the source
path, source line range, source digest, lifecycle, supersession IDs, and
sanitization counts. The generated manifest records counts and digests, never
the removed occurrence.

## R4 — deterministic documents, chunks, and identity

Examples in this contract are pretty-printed for review; generated JSON bytes
use the compact canonical encoding below.

All ordering uses bytewise ascending order under the C locale. Canonical JSON
uses UTF-8, `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, recursively
lexicographically sorted object keys, no insignificant whitespace, and one
final LF for a complete `.json` file. Digest preimages use the same encoding
without the final LF. JSONL is the concatenation of canonical record preimages
plus one LF per record; a zero-record JSONL file is zero bytes. Timestamps,
host paths, locale, timezone, filesystem order, Git branch, and network state
are not inputs.

Every Markdown source MUST end in LF. Sanitization preserves that LF and every
other source newline; it does not add, remove, or normalize trailing blank
lines. An ATX heading is recognized only when a line starts in column 1 with
one to three `#` bytes followed by a space or tab. Its label removes the marker,
leading separator whitespace, trailing space/tab, and an optional closing run
of `#` bytes preceded by whitespace. An empty derived label is a refusal. The
chunk heading array is the active heading stack: a heading removes entries at
its level and deeper, then appends its label; skipped levels add no
placeholders. Preamble chunks before the first recognized heading carry an
empty heading list.

A fenced block opens only on a line with zero to three leading spaces followed
by at least three identical backticks or tildes. It closes only with the same
character, at least the opening run length, zero to three leading spaces, and
trailing space/tab only. Backtick info strings cannot contain a backtick.
Inline code spans use matching, equal-length backtick runs outside fences.
Heading, comment, marker, and link recognition is suspended inside those
regions exactly as R3 states. These lexical rules, rather than a host Markdown
renderer, fix recognition for v1.

For each catalog row the compiler records:

- `source_digest`: SHA-256 of the exact source bytes;
- `sanitized_digest`: SHA-256 of the complete sanitized UTF-8 text;
- `document_digest`: SHA-256 of the closed document preimage below; and
- `document_version`: `v1-sha256-` followed by `document_digest`.

The document preimage has exactly these fields; `catalog` is the exact closed
row from R1 and object keys are encoded canonically:

```json
{
  "catalog": {
    "id": "revision-system-unified",
    "lifecycle": "live",
    "path": "docs/specs/revision-system-unified.md",
    "superseded_by": [],
    "supersedes": ["entity-storage-two-axis"]
  },
  "sanitized_digest": "<64 lowercase hexadecimal characters>",
  "schema": "waaseyaa.agent-spec-document-preimage.v1",
  "source_digest": "<64 lowercase hexadecimal characters>"
}
```

The corpus manifest maps every document version to its exact source and
sanitized digests. The mapping is verified on every read; a version/digest
mismatch is a refusal. There is no manually mutable version alias.

Sanitized text is divided by the v1 ATX headings defined above. A heading
begins a section; its body is packed greedily in source order from the maximum
number of complete lines that fit into chunks of at most 8,192 bytes. Heading
lines are included. A fenced code block is atomic. A single source line or
fenced block larger than the limit is unsupported and fails rather than
changing boundaries heuristically. Blank-only chunks are omitted, but a
document whose sanitized text has no non-whitespace content is refused.

Each chunk carries the closed shape:

```json
{
  "document_id": "revision-system-unified",
  "document_version": "v1-sha256-<full document digest>",
  "heading": ["Revision system", "Storage identity"],
  "id": "sha256:<full chunk digest>",
  "lifecycle": "live",
  "ordinal": 0,
  "schema": "waaseyaa.agent-spec-chunk.v1",
  "source_end_line": 42,
  "source_path": "docs/specs/revision-system-unified.md",
  "source_start_line": 1,
  "text": "..."
}
```

`ordinal` starts at zero and is contiguous within a document. Source line
bounds are one-based and inclusive, with end greater than or equal to start.
`heading` is a list of zero or more non-empty strings. `text` contains the
exact sanitized bytes for those complete source lines and therefore ends in
LF. The chunk digest covers the canonical JSON
preimage consisting of every displayed field except `id`; `id` is exactly
`sha256:<64 lowercase hexadecimal digest>`. Chunks sort by `(document_id,
ordinal)` within their lifecycle file.

Each manifest document record has this exact closed shape:

```json
{
  "chunk_count": 3,
  "document_digest": "<64 lowercase hexadecimal characters>",
  "document_version": "v1-sha256-<document_digest>",
  "id": "revision-system-unified",
  "lifecycle": "live",
  "path": "docs/specs/revision-system-unified.md",
  "sanitization_counts": {
    "excluded_spans": 0,
    "internal_references": 2,
    "spec_reviewed_comments": 1
  },
  "sanitized_digest": "<64 lowercase hexadecimal characters>",
  "source_digest": "<64 lowercase hexadecimal characters>",
  "superseded_by": [],
  "supersedes": ["entity-storage-two-axis"]
}
```

All digest fields are raw 64-character lowercase hexadecimal strings unless a
field explicitly carries the `sha256:` or `v1-sha256-` prefix. All counts are
JSON integers greater than or equal to zero, and each document's `chunk_count`
is at least one. A comment or paired exclusion span
contributes one to its corresponding count, regardless of line
count. Each stripped Markdown-link destination, bare/autolink URL, or bare absolute
host-local path contributes one to `internal_references`. Corpus-level
sanitization counts are exact sums of the document counts.

The manifest has exactly these fields and nested count keys. This one-document
example uses concrete JSON value types; digest strings are illustrative:

```json
{
  "chunk_counts": {
    "draft": 0,
    "historical": 0,
    "live": 1,
    "superseded": 0,
    "total": 1
  },
  "corpus_digest": "0000000000000000000000000000000000000000000000000000000000000000",
  "corpus_version": "v1-sha256-0000000000000000000000000000000000000000000000000000000000000000",
  "document_counts": {
    "draft": 0,
    "historical": 0,
    "live": 1,
    "superseded": 0,
    "total": 1
  },
  "documents": [
    {
      "chunk_count": 1,
      "document_digest": "0000000000000000000000000000000000000000000000000000000000000000",
      "document_version": "v1-sha256-0000000000000000000000000000000000000000000000000000000000000000",
      "id": "revision-system-unified",
      "lifecycle": "live",
      "path": "docs/specs/revision-system-unified.md",
      "sanitization_counts": {
        "excluded_spans": 0,
        "internal_references": 0,
        "spec_reviewed_comments": 0
      },
      "sanitized_digest": "0000000000000000000000000000000000000000000000000000000000000000",
      "source_digest": "0000000000000000000000000000000000000000000000000000000000000000",
      "superseded_by": [],
      "supersedes": []
    }
  ],
  "format_version": 1,
  "sanitization_counts": {
    "excluded_spans": 0,
    "internal_references": 0,
    "spec_reviewed_comments": 0
  },
  "schema": "waaseyaa.agent-spec-corpus-manifest.v1"
}
```

The four lifecycle count keys are always present even when zero; `total` equals
their sum. `documents` is non-empty, is sorted by `id`, contains one record
per validated catalog row, and reconstructs the complete catalog by projecting
each record's `id`, `path`, `lifecycle`, `supersedes`, and
`superseded_by`. Every manifest object is closed: extra fields or count keys
are a refusal.

`corpus_digest` is SHA-256 of canonical JSON without a trailing LF for this
exact preimage. The example is type-correct and corresponds to the manifest
shape above; each member of a `digests` list is the raw 64-character lowercase
hex digest from that JSONL record's `id`:

```json
{
  "chunk_counts": {
    "draft": 0,
    "historical": 0,
    "live": 1,
    "superseded": 0,
    "total": 1
  },
  "chunk_digests": [
    {
      "digests": ["0000000000000000000000000000000000000000000000000000000000000000"],
      "file": "chunks.live.jsonl"
    },
    {
      "digests": [],
      "file": "chunks.nonlive.jsonl"
    }
  ],
  "document_counts": {
    "draft": 0,
    "historical": 0,
    "live": 1,
    "superseded": 0,
    "total": 1
  },
  "documents": [
    {
      "chunk_count": 1,
      "document_digest": "0000000000000000000000000000000000000000000000000000000000000000",
      "document_version": "v1-sha256-0000000000000000000000000000000000000000000000000000000000000000",
      "id": "revision-system-unified",
      "lifecycle": "live",
      "path": "docs/specs/revision-system-unified.md",
      "sanitization_counts": {
        "excluded_spans": 0,
        "internal_references": 0,
        "spec_reviewed_comments": 0
      },
      "sanitized_digest": "0000000000000000000000000000000000000000000000000000000000000000",
      "source_digest": "0000000000000000000000000000000000000000000000000000000000000000",
      "superseded_by": [],
      "supersedes": []
    }
  ],
  "format_version": 1,
  "manifest_schema": "waaseyaa.agent-spec-corpus-manifest.v1",
  "sanitization_counts": {
    "excluded_spans": 0,
    "internal_references": 0,
    "spec_reviewed_comments": 0
  },
  "schema": "waaseyaa.agent-spec-corpus-preimage.v1",
  "validated_catalog": {
    "documents": [
      {
        "id": "revision-system-unified",
        "lifecycle": "live",
        "path": "docs/specs/revision-system-unified.md",
        "superseded_by": [],
        "supersedes": []
      }
    ],
    "schema": "waaseyaa.agent-spec-catalog.v1"
  }
}
```

The `chunk_digests` array always contains those two file objects in the shown
order; each digest list follows JSONL line order. The only derived manifest
fields excluded from the preimage are `corpus_digest` and `corpus_version`,
avoiding self-reference. `corpus_version` is exactly
`v1-sha256-<corpus_digest>`. Loading code MUST reconstruct the catalog from the
document records, recompute document and ordered chunk identities, recompute
the preimage and counts, and compare them; filenames or manifest claims alone
are not trusted.

## R5 — generated resource layout and live-only index

The v1 output is exactly:

```text
packages/bimaaji/resources/spec-corpus/v1/
  manifest.json
  chunks.live.jsonl
  chunks.nonlive.jsonl
```

`manifest.json` has the closed R4 shape. Its document records carry the complete
lifecycle graph and its count objects carry all four lifecycle keys.
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
- The resource parent and any existing `v1` MUST be real directories inside
  the repository; a symlink or non-directory publication/control path is an
  exit-`2` refusal before mutation.
- Write mode refuses pre-existing sibling paths `v1.tmp`, `v1.backup`, or
  `v1.failed`, builds into newly created
  `packages/bimaaji/resources/spec-corpus/v1.tmp`, and validates it before
  publication. A compile or validation refusal removes `v1.tmp` and leaves
  `v1` untouched; inability to remove the temporary directory is an exit-`2`
  infrastructure fault that names it. If `v1` exists, write mode renames
  `v1` to `v1.backup`; it then renames `v1.tmp` to `v1` on the same
  filesystem and validates the published directory again. A failure before the
  first rename leaves `v1`
  untouched. A failure after backup restores `v1.backup` to `v1`; if an
  invalid new `v1` exists, it is first retained as `v1.failed` and that
  exact repository-relative recovery path is reported. If there was no prior
  `v1`, failed post-swap validation retains the invalid output as `v1.failed`
  and leaves `v1` absent. Successful publication removes `v1.backup` last.
  Cleanup failure is exit `2`, leaves the
  validated `v1` authoritative and the backup intact, and names the backup; a
  later write refuses that residue rather than deleting it implicitly. A
  publish or restore failure is likewise exit `2` and names every recovery
  path.
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

### A8 — current-state completeness is exact and prior-state review is honest

- GIVEN a source is removed while its catalog row remains, or a row is removed
  while its source remains
- WHEN either compiler mode runs
- THEN it exits `1` before changing generated bytes and names the unmatched
  path
- AND a candidate that removes or renames both sides, or changes lifecycle,
  requires governance review against its accepted parent because the stateless
  compiler does not claim prior-state authority.

## Planned verification mapping

| Requirement | Executable evidence required from implementation |
| --- | --- |
| R1/R2 catalog and lifecycle graph | Data-driven unit tests for every shape, path, current-state completeness, reciprocity, target-state, and cycle; candidate-diff evidence for lifecycle transitions and paired deletion/rename refusal |
| R3 sanitization | Golden byte fixtures covering multiline review comments, fences, exact markers, every internal-target class, external/spec links, malformed constructs, line preservation, removed occurrences, and an identical retained-literal control |
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
