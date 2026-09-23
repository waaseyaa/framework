# Persistence and execution checks

Use for packages that write storage, create tables, run jobs or retries, or
report mutation outcomes.

## Storage access

- Entity persistence goes through `EntityRepository` and a storage driver. Supporting tables (join tables, counters, logs) may use `DatabaseInterface` directly. Raw PDO (`new \PDO`, `$pdo->exec`, `$pdo->prepare`) bypasses the framework database layer and is a finding unless a recorded exception names why.
- Check every backend the package claims (for example the sovereignty profiles' SQLite, Postgres and pgvector options). A package that only works on one backend must say so.
- Look for untyped query parameters and backend-specific SQL.

## Schema authority

- Every table the package creates must come from a migration or a coordinated schema transition (`SchemaMutationCoordinator`, `CoordinatedEntitySchemaExecutor`). A table created lazily at runtime (`CREATE TABLE IF NOT EXISTS` on first use) sits outside the schema-authority manifest and makes later transitions refuse with S1-DB109 (#3110). Record the table name, the creating code and the first code path that triggers it.
- Check the deployer runtime-table catalogue (`FrameworkRuntimeTableCatalogue`) agrees with what the package actually creates and how each table is classified.
- Check upgrade behavior on an existing database, not just a fresh one: new columns, new tables, and a second run changing nothing.

## Transactions and outcomes

- Identify transaction boundaries and what is atomic. A multi-step write without a transaction needs a documented recovery story.
- Check concurrency and fencing: revisions, mutation tokens, unique constraints, and whether counted limits (slots, quotas) are enforced by the database or only by application code.
- For retries and jobs, check idempotency, claim/release semantics and duplicate delivery.
- Compare the reported outcome with the durable one. A caller told "failed" when the write committed, or "done" when it didn't, is a finding (#3035).
- Test restart and recovery: a crash between steps, a partially applied migration, a job interrupted mid-run.
