# Coverage diff path lifecycle

Issue: #2297

## Problem

The changed-line coverage parser selected a package source path only when a matching `+++` line appeared, but never cleared that path. Test, documentation, deletion, and other later hunks were consequently attributed to the last source file whenever their line numbers happened to be executable there.

## Decision

Treat the active source path as file-scoped parser state. Reset it on every `diff --git` boundary and derive it afresh from every `+++` line; only `packages/*/src/*.php` paths may activate coverage collection.

## Verification

- Preserve the existing threshold pass and failure fixtures.
- Add adversarial fixtures for source followed by tests and docs, deletion transitions, a non-source preamble, and multiple source files.
- Re-run the failure diff from MCP issue #2295 to prove unrelated hunks no longer inflate its executable-line denominator.
- Run the complete repository verification suite before review.

## Non-goals

- No coverage threshold change.
- No exclusions or package-specific exceptions.
- No release or deployment.
