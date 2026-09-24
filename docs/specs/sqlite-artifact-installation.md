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
Operational audit-retention rules are serving-owned `preserve` state; a content
artifact cannot silently replace the host's authorization to prune evidence.

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

1. Both inputs must be regular, non-symlink SQLite files with no committed WAL
   frames and with `PRAGMA integrity_check = ok`. A zero-byte WAL and its SHM
   metadata are harmless build residue; a non-empty WAL is refused rather than
   reading a main file while committed state may still live outside it.
2. The artifact table set must equal the application allowlist plus framework
   runtime tables present in either input, plus SQLite's internal tables.
   Unknown tables fail closed.
3. When a runtime table exists in both inputs, its complete table definition,
   columns, primary key, checks, foreign keys, complete index SQL (including
   partial-index predicates), and triggers must be compatible before rows move.
   The `user` table has one explicit, one-way compatibility transition: a
   serving six-column schema (`uid`, `uuid`, `bundle`, `name`, `langcode`,
   `_data` in that order) may be paired with the current artifact schema
   when the artifact adds only nullable TEXT-affinity
   `identity_name_key` and `identity_mail_key` columns and the named unique,
   non-partial indexes `user_identity_name_key_unique` and
   `user_identity_mail_key_unique`. Every pre-existing schema component must
   still match; reverse, reordered, defaulted, partial, renamed, or otherwise
   undeclared changes fail closed. User identity merging uses the declared
   stable `uuid`: a uid/uuid remap is refused, a matching uid+uuid artifact
   row is removed before inserting the serving row, and all other unique
   collisions abort the transaction.
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

## Schema authority reconciliation

`waaseyaa_schema_authority` is itself a framework runtime table, catalogued
`artifact`: its aggregate `schema_fingerprint`, `ledger_fingerprint`,
`source_catalog_fingerprint`, and `generation` arrive from the artifact
untouched by ordinary preservation. Runtime preservation can still change the
candidate's actual logical schema relative to what the artifact's own
manifest describes — a serving-only runtime table it just cloned, for
example — so preparation reconciles the aggregate fingerprint before commit
rather than leaving a candidate whose recorded manifest describes a database
that no longer exists.

When the artifact carries a fingerprinted manifest (`schema_fingerprint` and
`ledger_fingerprint` both non-null), preparation requires the artifact's
recorded values to equal its own computed schema and ledger fingerprints —
checked against the read-only artifact connection before the candidate file
exists at all, so a stale manifest fails before a single row moves — and
captures those values, together with the artifact's full schema object set.
Inside the candidate transaction, after runtime preservation, preparation
first requires the candidate's own (copied) manifest row to still equal
exactly what was captured, then requires every schema object that differs
between the captured artifact snapshot and the prepared candidate to belong
to a non-`artifact`-policy catalogue table, then conditionally re-records
only `schema_fingerprint` to the candidate's computed value — `UPDATE ...
WHERE authority_id = 1 AND schema_fingerprint = <artifact's recorded
value>`, requiring exactly one affected row. The bind check exists because a
serving-only table's cloned schema can include a trigger that fires during
row copying and mutates data anywhere, including this very manifest row; see
`docs/change-records/FW-3149.md` for the reproduction. `ledger_fingerprint`,
`source_catalog_fingerprint`, and `generation` are left exactly as the
artifact recorded them: `waaseyaa_migrations` is itself catalogued `artifact`
and untouched by the handoff, so the candidate's ledger is already
byte-identical to the artifact's, and `generation` counts governed
schema-mutation transitions, not artifact handoffs. After commit, preparation
asserts the candidate's recorded schema and ledger fingerprints equal their
freshly computed values, using the identical computation the serving host's
own schema-authority pre-state assertion and `migrate --verify` use, and
discards the candidate on any mismatch — this is the only guard that catches
a cloned trigger mutating a *different* artifact-policy table's data, such as
an extra row inserted into the migration ledger. An artifact without a
fingerprinted manifest — a fresh install or a pre-fingerprint adoption — is
left completely untouched. A manifest table present but missing a required
fingerprint column is a different case again — an installation too old for
this contract — and fails closed rather than being treated as no manifest.

This fingerprint computation must describe exactly one algorithm. The
deployer package computes it independently of the serving host's own
migration-ledger implementation (rather than depending on it, which would tie
the deployer's isolated installation boundary to the full framework
dependency graph); a parity test pins the two byte-identical against each
other so they cannot drift apart unnoticed.

## Installation and restore

The privileged serving process creates a durable byte-for-byte backup before
activation. The generic installer first checkpoints the serving database and
requires all sidecars to disappear, then records its hash and refuses activation
if the database changes while the candidate is prepared. It fsyncs the
candidate, renames the serving database aside, and
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
- a stale or too-old artifact schema-authority manifest, a candidate
  schema-authority manifest that no longer matches the values captured from
  the artifact, an unbounded schema difference outside runtime-policy tables,
  a schema-authority manifest that changed concurrently, or a post-commit
  schema-authority verification mismatch;
- paths that are symlinks, aliases of one another, or outside the caller's
  approved deployment root.

No override permits rewriting append-only state. Authorized retention remains
a separate, audited runtime operation.
