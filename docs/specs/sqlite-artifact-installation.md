# SQLite artifact installation

<!-- Spec introduced 2026-08-07 for #2288. -->

Waaseyaa applications may build content in a disposable SQLite database and
install that database as an artifact. An artifact installation is not a raw
file replacement: the serving host owns credentials, sessions, queues,
approvals, audit evidence, and other state that cannot be reconstructed by the
content build.

`waaseyaa/deployer` owns the installation contract. Applications provide the
artifact and their application-owned table allowlist; they do not enumerate
framework runtime tables.

## Ownership policies

The versioned framework catalogue assigns each framework runtime table one of
these policies:

- `artifact`: disposable or rebuildable framework state. The artifact copy is
  authoritative and a serving-only copy may be dropped.
- `preserve`: the serving table is authoritative. Its complete row set replaces
  any build-time rows in the candidate.
- `append_only`: the serving table is preserved exactly like `preserve`, with
  an additional postcondition that its ordered row digest and row count are
  unchanged. Artifact rows can never inject or rewrite audit evidence.
- `identity_merge`: artifact-only identities are retained, while every serving
  identity is inserted into the candidate with the serving row winning on a
  primary-key collision. This retains host-created accounts and credentials
  while allowing the artifact to introduce referenced content authors.

Tables not claimed by the framework catalogue are application-owned and must be
declared by the application. Cache and other safely disposable framework tables
are explicitly catalogued as `artifact`, never inferred from a name prefix.

## Discovery

The catalogue is framework code and is released with `waaseyaa/deployer`.
Consumer code may add application-owned runtime definitions, but must not copy
or extend the framework table list. Duplicate definitions and unknown policy
versions fail before any candidate is written.

The catalogue version is included in every installation report so an operator
can tie preservation evidence to the exact contract used.

## Candidate preparation

Preparation receives a checkpointed serving database and a reviewed artifact.
It writes a new candidate; it never mutates either input.

1. Both inputs must be regular, non-symlink SQLite files with
   `PRAGMA integrity_check = ok`.
2. The artifact table set must equal the application allowlist plus framework
   runtime tables present in either input, plus SQLite's internal tables.
   Unknown tables fail closed.
3. When a runtime table exists in both inputs, its complete table definition,
   columns, primary key, checks, foreign keys, indexes, and triggers must be
   compatible before rows move.
4. A serving-only runtime table is copied with its schema, indexes, and
   triggers. This supports lazily provisioned runtime stores without asking a
   content build to boot them. An artifact-only runtime table must be empty.
5. Preservation and identity merging run in one transaction on the candidate.
6. Declared account-reference columns are validated against the merged account
   table before commit. A table may explicitly declare framework principal
   sentinels such as anonymous `0` or the development administrator; no global
   sentinel exemption weakens authentication-owner columns. Missing accounts
   and malformed references fail closed.
7. Foreign-key checking and `integrity_check` must pass after commit.

The report contains table policy, pre/post row counts, and SHA-256 row digests.
It never contains row values, secrets, bearer tokens, or raw MCP arguments.

## Installation and restore

The privileged serving process creates a durable byte-for-byte backup before
activation. It fsyncs the candidate, renames the serving database aside, and
atomically renames the candidate into place. Verification failure restores the
previous database before returning an error. A forced failure hook exists only
as an injected test seam and cannot be selected through a public request.

Restore uses the same privileged path and the same integrity, ownership, mode,
fsync, atomic-rename, and postflight checks as installation. A backup is not
considered usable until that restore path has been exercised against a
disposable production-shaped fixture.

Applications that swap protected or public file trees must keep their tree
activation in the same rollback boundary: any failure after the first rename
restores the database and every activated tree before maintenance mode ends.

## Refusals

Installation fails before activation for:

- unknown tables or catalogue versions;
- incompatible runtime schemas;
- non-empty artifact-only runtime stores;
- dangling account references;
- append-only row-count or digest changes;
- failed integrity or foreign-key checks;
- paths that are symlinks, aliases of one another, or outside the caller's
  approved deployment root.

No override permits rewriting append-only state. Authorized retention remains
a separate, audited runtime operation.
