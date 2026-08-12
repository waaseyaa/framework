# S1 SQLite topology contract

Status: implementation candidate  
Contract version: `s1-sqlite-v1`  
Change record: `S1-FW-DB-01` (`F-DB-001`, `F-DB-002`)

## Purpose

S1 certifies one deliberately small persistence topology. Dependency
abstractions and locally portable SQL do not certify another database engine,
filesystem, or deployment shape. The machine authority is
`support/s1-sqlite-v1.json`; prose and code may narrow it but may not widen it.

## Certified topology

- Exactly one application node owns exactly one authoritative SQLite database
  file.
- The authoritative file is on a local, non-network filesystem. The supported
  consumer point is ext4. A deployment preflight must verify the actual mount;
  path spelling alone cannot establish that a POSIX path is local.
- File-backed connections use WAL, enable foreign-key enforcement, and use a
  bounded `5000` millisecond busy timeout. Startup verifies the effective value
  of every PRAGMA on every new connection and refuses a mismatch.
- `:memory:` is permitted only in development and test contexts. Production
  refuses it before a connection is created.
- At most one additional local SQLite file may hold the search projection. It
  is non-authoritative, has the same connection invariants, and is disposable
  only when a deterministic reindex from the authoritative database succeeds.
- The S1 serving envelope is one application node. Multiple local PHP-FPM
  workers may share its one SQLite file subject to the later concurrency,
  migration-lock, and scheduler-fencing contracts; this package does not claim
  those later guarantees are already implemented.

## Refusal boundary

Configuration that looks like a URI or DSN is not interpreted as a filename.
`mysql:`, `pgsql:`, `postgresql:`, `sqlite:`, `file:`, URI authority forms, and
other schemes fail with a stable topology diagnostic. Windows UNC/device paths
and slash-form network shares fail. MySQL, MariaDB, PostgreSQL, replication,
database failover, shared/network filesystems, horizontal application scaling,
and H1 are unsupported.

An ordinary absolute local Windows drive path remains a valid library input for
local development. It is not part of the certified Linux consumer point.

## Authority and verification

`DBALDatabase::createSqlite()` is the common connection boundary for the
authoritative database and optional search projection. It validates path shape,
establishes the PRAGMAs, then reads them back. `DatabaseBootstrapper` owns the
environment-specific production refusal and directory preparation.

The repository checker binds the JSON contract to runtime constants,
documentation, package composition, and reproducible verification commands.
GitHub Actions may execute those commands, but neither GitHub nor any other
forge is a contract or evidence authority.

## Explicit non-claims

WAL is not replication, high availability, or a substitute for backup. The
optional search projection is not a second source of truth. This contract does
not close schema authority, optimistic concurrency, scheduler fencing, backup,
restore, or disaster-recovery findings owned by later packages.
