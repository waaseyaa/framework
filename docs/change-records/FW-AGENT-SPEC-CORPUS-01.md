# FW-AGENT-SPEC-CORPUS-01 — versioned sanitized specification corpus

Status: design review candidate

Anchor mirror: waaseyaa/framework#2661

Parent program: #2653

Base: `3c3da0d0957e0eb391545ae8d65dacbdadae2f4e`

## Intent

Compile Waaseyaa's authoritative `docs/specs/` contracts into a deterministic,
lifecycle-labelled, sanitized resource that can ship with `waaseyaa/bimaaji`.
The default corpus index contains current contracts only. Retired and proposed
material remains available only with an explicit lifecycle label.

The enduring contract is `docs/specs/agent-spec-corpus.md`. This record owns
work sequencing and review decisions; it is not a second specification source.

## Evidence read before design

- #2661 outcome and acceptance; #2653 program boundary.
- #2641's measured 361 issue references, 319 correct closed references, and
  low precision of phrase/tense inference; its `Spec reviewed` comments are
  append-only history and must not feed current retrieval.
- ADR-022 D-7: `bimaaji_search_specs` is deliberately allowlisted but inert,
  and raw path results lack lifecycle context.
- `SpecIndexProvider`, `SearchSpecsTool`, and their tests: top-level glob,
  path-only entries, late file reads, and success-shaped empty results.
- `docs/specs/version-provenance.md`: root Composer version is not framework
  revision identity; generated content cannot bind to its own future commit.
- #2648/#2650: raw docs and an approved compiled corpus have separate
  distribution ownership.
- The design candidate contains 113 recursively enumerated Markdown sources:
  111 top-level files, including the new corpus contract, and two nested files.

## Decisions

1. Lifecycle metadata lives in one closed JSON catalog inside `docs/specs/`.
   Every recursively enumerated Markdown source is classified; omission never
   defaults to live.
2. The exact lifecycle values are live, superseded, historical, and draft.
   Supersession is reciprocal and terminates at a live document.
3. Generated identity is content-addressed. Source, sanitized document, chunk,
   and corpus digests map directly to `v1-sha256-*` versions; no mutable version
   alias, wall clock, host path, Git branch, or self-referential commit enters.
4. The default generated JSONL physically contains live chunks only. Non-live
   chunks are separate and always labelled.
5. Sanitization removes `Spec reviewed` comments, exact opt-out spans, and
   internal execution link destinations while preserving visible prose,
   external/spec links, source line mapping, and structural provenance.
6. The generated resource is owned by `waaseyaa/bimaaji` but remains inert.
   Search activation and FTS5 belong to #2662.
7. The compiler validates the current candidate and has no Git-history or
   mutable prior-state authority. Single-sided catalog/source omissions fail in
   code; lifecycle transitions and paired delete/rename changes fail or pass by
   governance review of the candidate diff.
8. Catalog, document, chunk, manifest, lifecycle-count, sanitization-count, and
   corpus-digest preimage shapes are closed. Corpus identity excludes only its
   own digest/version fields and binds both JSONL files in line order.
9. Repository-relative provenance remains. Sanitization removes absolute
   host-local filesystem paths and `file:` URLs rather than claiming every
   local-looking relative path disappears.

## Work packages

### WP-A — design and catalog review

- Land this change record and the enduring corpus contract.
- Produce a complete proposed lifecycle catalog for every current Markdown
  source as a separately reviewable implementation diff.
- Resolve ambiguous free-form status prose with the maintainer; do not infer it.

### WP-B — compiler and red-first contract tests

- Implement strict catalog/lifecycle validation, sanitization, deterministic
  chunks, and content-addressed identities.
- Prove every refusal class and check/write atomicity with temporary fixtures.
- Use the repository-owned file enumerator and scrubbed Git execution path.
- Implement only current-state validation; do not add a history reader or
  mutable state store to guess lifecycle evolution.

### WP-C — generated resource and governed drift check

- Generate the v1 Bimaaji resource from the reviewed catalog.
- Register one read-only drift check and one authoritative writer.
- Prove package inclusion, raw/generated separation, and zero runtime
  activation.

### WP-D — acceptance and handoff

- Review the generated corpus for removed review/internal execution material.
- Run full publication qualification on the exact candidate.
- Hand the immutable v1 resource contract to #2662 without editing its search
  implementation in this work package.

## Out of scope

- Client adapters (#2660), FTS/search activation (#2662), project-local MCP
  configuration (#2663), vector/embedding search, HTTP routes, remote calls,
  and any new specification/workflow runtime.
- Heuristic issue-state or tense classification. #2641 establishes that this
  is too noisy to make blocking or retrieval-authority decisions.
- Root distribution/export policy, owned by #2648/#2650.

## Open review items

1. Review and approve the initial lifecycle value for every existing source;
   the design supplies rules, not those historical judgments.
2. Confirm the proposed compiler command/library names during implementation.
3. #2662 must settle FTS schema, ranking, filters, and installed-version
   reporting while preserving this corpus's live-only default and identities.
4. Any future deletion/rename migration must define preserved citation
   identity in a later format; v1 review refuses such candidates.

## Candidate evidence

The design worktree is coordinator-leased and retained. No runtime source,
client adapter, MCP surface, corpus implementation, vendor tree, or generated
artifact is changed by WP-A. Verification for this design patch is limited to
diff inspection, Markdown/reference checks available without dependencies, and
`bin/git diff --check`; full gates belong to the implementation candidate.
