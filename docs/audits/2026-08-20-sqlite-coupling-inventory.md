# SQLite Coupling Inventory — 2026-08-20

> **Pinned to `3aca84d79b8afe0b60272ffc374a5f9299eb98c2` (`origin/main`), re-resolved 2026-08-21.**
> Every file-and-line reference below was resolved against that exact tree: each path resolves to a tracked
> file, and each cited line is within it. The `#2446` repairs (**#2450**, **#2452**) remain present and are
> reflected in §4.1 and §4.10 — including the four SQLite couplings those repairs added.
> The production surfaces added by **#2474** (the layout-draft acknowledgement seam, in `page-builder`,
> `publishing`, and `admin-surface`) were swept with the same discovery patterns and contain **no SQLite
> couplings** — they reach persistence only through a gateway interface. #2474's one addition to
> `support/s1-sqlite-construction-roster.json` is test-scope and therefore outside this inventory's scope.
> Two commits landed on main after the previous pin (`e77d610ad`): **#2477** (layout save advisory) and
> **#2459** (governed workflow assignment configuration). Neither added, removed, nor relocated a production
> SQLite coupling in this inventory's scope (`packages/*/src/**`, `packages/*/migrations/**`, `bin/**`,
> `public/**`). #2459 updated only the recorded `composer.lock` digest in
> `support/s1-sqlite-dependency-bytes.json`, which is outside the §4 occurrence tables. All 358 §4 sites
> re-resolved at this pin; no inventoried production file moved, so the cited line numbers are unchanged.
> Line numbers are evidence about this commit, not durable identifiers; re-resolve before relying on them.

**Scope:** An exhaustive, file-and-line inventory of SQLite-specific SQL, DDL, catalogue access, connection topology, and governance pins in Waaseyaa production source (`packages/*/src/**`, `packages/*/migrations/**`, `bin/**`, `public/**`). It exists to feed the assessment-surface line item of **#2447** — "inventory of SQLite-specific SQL and behavior with an owner/disposition for each occurrence" — and nothing else.

**What this audit does NOT do.** It does not authorize, imply, or recommend a PostgreSQL or MySQL support claim. Waaseyaa's honest support claim today is SQLite-only, and this document does not change it. #2447 and #2448 are both `status:blocked` behind Sheguiandah alpha field evidence; this is evidence-gathering in advance of that gate, not a plan of record. It does not rely on the premise that DBAL usage proves portability — #2447 explicitly rejects that premise, and §8 records several claims that were refuted precisely because a DBAL call site turned out to carry no engine assumption at all, and several others confirmed because a DBAL call site carried one anyway. It keeps the **PostgreSQL and MySQL columns separate throughout**, per #2448's rejection of inferring MySQL readiness from PostgreSQL results; there is deliberately no combined "portable?" verdict anywhere in this document, and the two columns disagree on 64 of 358 occurrences (§3 shows the derivation).

---

## 1. Method

### What was searched

Every file under `packages/*/src/`, `packages/*/migrations/`, `bin/`, and `public/`. Excluded: `**/tests/**`, `**/Testing/**`, `**/testing/**`, `**/tests-*/**`, `vendor/**`, `node_modules/**`, and `packages/admin/**` (the JS SPA has no SQL).

Discovery was deliberately broad — SQLite-only catalogue access (`sqlite_master`, `sqlite_schema`, `pragma_table_list`, every `PRAGMA`), SQLite-only DDL (`AUTOINCREMENT`, `WITHOUT ROWID`, affinity reliance, `ALTER TABLE` limits), SQLite-only DML (`INSERT OR IGNORE`, `INSERT OR REPLACE`, `last_insert_rowid()`), JSON1 (`json_extract`, `'$.path'` arguments, and reliance on json_extract's return typing), FTS5 (`fts5`, `MATCH`, `bm25()`, `rank`), builtins whose semantics diverge (`julianday`, `strftime`, `max(a,b)`, `IFNULL`), locking and transaction semantics (`BEGIN IMMEDIATE`, `busy_timeout`, WAL, deferred-transaction snapshot upgrade), identifier-quoting and boolean-as-integer assumptions, and connection/DSN assumptions (`pdo_sqlite`, `:memory:`, `.sqlite` paths) in production wiring.

### How claims were verified

Every candidate occurrence was re-read at the cited line by an independent verifier that was permitted to refute it. A claim was marked confirmed only when (a) the construct is present verbatim at the stated 1-indexed line, (b) it is executable rather than prose, and (c) the verifier independently re-checked whether a driver guard actually guards it. That third step changed a substantial number of verdicts: several claimed guards turned out to be *schema-shape* checks (`schema()->fieldExists()`), *column-existence* checks, *table-presence* checks (`$schema->hasTable()`), *path-shape* checks (`if ($fileBacked)`), or *wrapper-class* checks (`$db instanceof DBALDatabase`) — none of which say anything about the driver. Conversely, several claimed gaps turned out to be genuinely guarded, and are recorded in §6 rather than counted as gaps.

**Coverage cross-check.** A repo-wide grep for `AUTOINCREMENT` in production scope returns 27 hits. This inventory carries 25 of them as executable occurrences; the remaining 2 (`packages/entity-storage/src/Schema/RevisionTableBuilder.php:264`, `packages/attachment/src/Schema/AttachmentSchema.php:50`) are the two the verifiers rejected as comment-only. The inventory and the raw grep reconcile exactly, re-verified at the pinned commit: all 25 executable lines appear in §4, and the 2 comment-only lines are exactly the two named above.

**Independent governed cross-check.** `support/s1-sqlite-construction-roster.json` — a
recorded roster enforced by `bin/check-s1-sqlite-contract` and
`bin/check-s1-schema-authority`, both blocking gates in `tools/preflight-gates.json` — enumerates **940**
SQLite-construction candidates repo-wide, of which **906 are test-scope** and **34 are production**, across
**13 production files**, classified as `tooling-query` (15), `offline-artifact-authority` (7),
`serving-entrypoint` (5), `runtime-capability-configuration` (3), `serving-connection-authority` (2),
`inherited-connection-migration` (1), and `test-utility` (1). That roster is built by a different mechanism
(regex patterns over `git ls-files`, with per-candidate `match_sha256` pinning) and for a different purpose
(governance), yet its production-scope count is the same order as this inventory's connection-construction
surface. It is corroboration, not proof: the roster covers *connection construction* only, not SQL dialect,
so it speaks to §4.2 and says nothing about the other eleven subsystems.

### What counts as one coupling

**One coupling = one `(file, line)` site.** Each row in §4 is one site, cited at the exact 1-indexed line where
the construct appears. Two statements on two lines are two rows, even when they serve one purpose — for example
`cli/src/Handler/DbInitHandler.php:76` and `:77` are two separate `$io->error(...)` calls and are counted
separately.

Where one line carries several *distinct* dialect constructs — `id INTEGER PRIMARY KEY AUTOINCREMENT` and a
`DATETIME` column and a `CHAR(64)` column emitted by one `CREATE TABLE` statement — the constructs are named
together in a single row, because the repair is one edit to one line. That is the only many-to-one relationship
in the document, it is visible in the construct cell wherever it occurs, and it never spans lines.

Every path is written in full — package-relative below `packages/`, repository-relative for `bin/`. No reference
is elided, so every row can be resolved by a script.

### The section-4 tables are the source of truth

**Every count in this document is derived from the §4 tables by parsing them.** No total is maintained by hand,
and no figure is carried forward from an earlier draft. The derivation is:

| Figure | How it is computed from §4 |
|---|---|
| Total occurrences | number of rows |
| Per-subsystem totals in the §4 headings | rows grouped by section |
| Disposition totals (§3) | rows grouped by the disposition column |
| Per-engine totals (§3) | rows grouped by the PostgreSQL column and, separately, by the MySQL column |
| Engine disagreement count | rows whose PostgreSQL cell differs from their MySQL cell |
| Per-package totals (§3) | rows grouped by the owning package path |
| Unguarded gaps | `structural-gap` + `mechanical-gap` |

Anyone can reproduce all of them from this file alone: parse every table row under a `### 4.x` heading whose
first cell matches `path:line`, then group. Validation is the same parse — every path must resolve to a tracked
file, every cited line must be within that file, every disposition must be one of the four defined below, every
engine cell must be one of the four defined below, and no `(file, line)` site may appear twice.

**This revision deliberately carries no history of how the inventory was produced.** An earlier draft reported
intermediate tallies from the tooling that generated it — raw row counts, duplicate counts, and a count of
references corrected between bases. Those numbers described a process whose raw dataset is not committed to
this repository, so they could not be reproduced from anything a reader can see, and they are removed rather
than restated. What remains is the inventory itself, which is checkable line by line against the tree at the
pinned commit.

### Disposition taxonomy

| Disposition | Definition |
|---|---|
| **structural-gap** | No textual substitute preserves the behaviour. Porting requires a design change, a schema/data migration, or a change to what is recorded as evidence. |
| **mechanical-gap** | A direct per-engine substitute exists and is local to the call site. The construct must change; the design does not. |
| **guarded** | An explicit **platform or driver predicate** — `instanceof SQLitePlatform`, `$platform === 'mysql'`, or equivalent — is evaluated *before* the construct runs, and diverts a non-SQLite driver away from it. Catching an exception that unsupported SQL throws is **not** a guard: the statement is still issued, the engine still rejects it, and only the failure is absorbed. Those are counted as gaps and listed separately in §5.1. |
| **portable** | Present and executable, was reported as a coupling, and is not one — the construct executes correctly on all three engines, or carries no engine assumption at all. Retained in the inventory so the count is auditable rather than curated. |

Engine columns are per-engine and never merged. **They record a static compatibility assessment against each
engine's published documentation. No statement in this document was executed against a PostgreSQL or MySQL
server, so no cell is an observation.** Read every value as "assessed as", not "observed to be":

| Cell | Means |
|---|---|
| `works` | **Assessed compatible** — the construct's documented grammar and semantics are supported by the engine. Not a test result. |
| `differs` | Assessed to execute, but with different semantics or a different failure mode. Includes the silent-wrong-answer cases, where nothing errors and the value is wrong. |
| `unsupported` | Assessed to be rejected by the engine, or the construct has no counterpart there. |
| `unknown` | Governance or dispatch logic where the engine columns do not apply. |

The distinction is not pedantry: §7.5 records a case where two careful readers of the *same published
PostgreSQL grammar* reached opposite conclusions about one line. A documented-compatibility assessment is the
strongest claim this method can support, and it is weaker than a passing test.

---

## 2. Headline findings

Lead with the structural gaps, because those are what set the assessment's cost.

**1. There is zero non-SQLite CI. This is a structural gap in the assessment itself, not just in the code.** No `services:` block exists in any of the 21 workflows under `.github/workflows/`, and no workflow file references postgres, mysql, mariadb, or pgsql in any form. `ci.yml` contains **19** `setup-php` steps: **15** request SQLite extensions, **3** specify no `extensions:` key at all, and **1** (`ci.yml:721`) requests `xml` only. The three that specify nothing still get whatever the runner image provides, which is SQLite-capable and not PostgreSQL- or MySQL-capable, so the absence of a pin is not the presence of an alternative. Two blocking gates enforce the state directly: `bin/check-s1-sqlite-contract:371` requires `pdo_sqlite` and `sqlite3` to be loaded in CI, and `:374-377` pins the SQLite **library version** to `>=3.40.0 <4.0.0`. Nothing in this inventory below the governance layer can be executed anywhere in CI today.

**2. Governance refuses the port before code can be written.** `bin/check-s1-sqlite-contract:204` requires the machine contract to declare `postgresql` among the *refused* alternate databases, and line 253 hardcodes that refusal list in the **checker**, not only in `support/s1-sqlite-v1.json` — so editing the JSON alone does not clear the gate. Line 179 pins `authority.engine = sqlite`; line 192 pins the search projection engine to SQLite. The gate is fail-closed and runs in pre-push and CI. Separately, `bin/check-support-contract:203-204` requires the published root `composer.json` to hard-require `ext-pdo_sqlite` and `ext-sqlite3`, which every downstream consumer install inherits. **The charter-level contract must change before the first line of driver code can land.**

**3. 135 of 358 confirmed occurrences are structural, and they cluster in five subsystems, not one.** Migration authority and the schema compiler (29), connection topology (20), the deploy artifact pipeline (20), database-enforced integrity triggers (19), and FTS5 search (17). That is 105 of the 135. The remaining 30 are spread across the entity query engine (9), governance gates (6), diagnostics (6), runtime-DDL/raw-PDO stores (4), schema emission (2), package DDL (2), and one in the audit append-only SQL guard.

**4. The recorded evidence is SQLite-shaped, not just the code.** Three separate identity mechanisms are defined over SQLite artefacts and cannot be reused after a port: `LogicalSchemaFingerprint` hashes SQLite's stored `CREATE` text from `sqlite_schema` (`:16`) under a domain constant literally named `waaseyaa.logical-sqlite-schema.v1` (`:33`); every recorded `diff_hash` is `SqliteCompiler` output, so `migrate --verify` would return `plan_mismatch` for every historical row (`VerifyRunner.php:140`); and the authority verdicts (`authority_missing` / `schema_drift` / `ledger_drift`) are derived from the same catalogue scan (`VerifyRunner.php:159`). Neither PostgreSQL nor MySQL exposes the original CREATE text — PG must reconstruct from `pg_catalog`, MySQL's `SHOW CREATE TABLE` returns a server-normalised rewrite. A port re-baselines every install.

**5. The v2 migration algebra has exactly one backend, and its refusals are framework-wide.** `packages/foundation/src/Schema/Compiler/` contains only a `Sqlite/` subtree. `AlterColumnTranslator.php:28` and `ForeignKeyTranslator.php:29/:40` are `: never` methods that throw unconditionally, so `AlterColumn`, `AddForeignKey`, and `DropForeignKey` can never compile for anyone — even though PG 14 and MySQL 8 both support all three natively. `SqliteCapabilities.php:58/:60` defines the compiler's capability surface as `version_compare()` against SQLite version strings, and `V2PlanExecutor.php:40` takes `SqliteCompiler` as a concrete constructor dependency. The `migrate` CLI wires that concrete type into `--dry-run` and `--verify` (`MigrateServiceProvider.php:102`, `MigrateHandler.php:39`, `DryRunPlanner.php:33`, `VerifyRunner.php:36`).

**6. Two silent-wrong-answer failure modes, distinct from the failures that error loudly.** `packages/entity-storage/src/Backend/SqlColumnBackend.php:293` reads booleans back through PHP truthiness of the raw column value. PDO_PGSQL returns `'t'`/`'f'` for a BOOLEAN column, and `(bool)'f' === true`, so FALSE reads back as **true**. This is classified `differs`, not `unsupported`, and the distinction is the whole point: the cast **executes successfully** on PostgreSQL and returns the wrong value. Nothing errors, nothing logs, and no test that only checks for exceptions would catch it. `packages/foundation/src/Diagnostic/HealthChecker.php:340` degrades `columnExists()` to `return false` inside a catch-all, which means column-vs-`_data` storage drift is reported **clean** on any non-SQLite driver rather than reported as unknown — a false pass in the very verifier #2447 would depend on.

**7. `bin/check-s1-sqlite-contract` pins exact source text in three packages.** Lines 304–308 assert three literal strings inside `packages/search/src/SearchServiceProvider.php`, including `DBALDatabase::createSqlite($searchDb, $environment)`; lines 311 and 314 assert the `createSqlite(...)` call text in `DbInitHandler.php` and `MigrateServiceProvider.php`. Any refactor of those call sites must update the gate roster in the same commit or fail pre-push. This is an ordering constraint on the work, independent of its difficulty.

**8. #2446 has landed, and its repairs are themselves SQLite-shaped.** #2446 — the p0 that made schema-authority
acquisition fail under concurrent boot — closed via **#2450** (`fix(#2446): claim the SQLite writer
position before reading schema authority`) and **#2452** (`fix(#2446): keep unchanged schema synchronization
read-only`). Both repairs are confirmed present at this base and both **add** SQLite couplings rather than
remove them. #2450 introduces `claimWriterPosition()`, which issues `INSERT OR IGNORE` as the transaction's
first statement precisely *because* SQLite's deferred-`BEGIN` snapshot-upgrade rule demands it — a second
`INSERT OR IGNORE` now exists at `MigrationRepository.php:165` for the first-install path. #2452 introduces
three `PRAGMA query_only` statements at `CoordinatedEntitySchemaExecutor.php:67`/`:69`/`:83`, correctly guarded
by `instanceof SQLitePlatform`, whose consequence is that the steady-state read-only optimisation exists only
on SQLite. The docblock at `:129-137` now states the ordering rule correctly, where the superseded text claimed
a write lock that `CREATE TABLE IF NOT EXISTS` does not take. **The
practical lesson for #2447 is that fixing SQLite-specific defects deepens SQLite specificity**, so the
inventory grows under maintenance unless portability is an explicit constraint on repairs.

### The five kinds of finding in this document

These are routinely conflated, and conflating them produces a wrong cost estimate. They are separated
throughout, and nothing in one category can be discharged by work in another.

| # | Kind | What it is | Where it lives | Who can discharge it |
|---|---|---|---|---|
| 1 | **Governance / refusal gates** | Machine contracts and blocking checkers that *refuse* a non-SQLite database by policy, independent of whether any code would work | §4.7, and headline findings 2 and 7 | A charter-level contract amendment. No amount of driver code clears it. |
| 2 | **Executable code coupling** | SQL, DDL, catalogue access, and connection construction that a non-SQLite engine would reject or mis-execute | §4.1–§4.6, §4.8–§4.12 — the 280 unguarded gaps | Code change, per site, in the owning package |
| 3 | **Migration-algebra capability ceilings** | Operations the framework's own v2 compiler refuses for *every* engine, SQLite included | §4.1, headline finding 5 | Compiler work. Note this is a pre-existing limitation surfaced by the audit, not a portability cost |
| 4 | **Historical evidence / fingerprint compatibility** | Recorded artefacts — `diff_hash`, `LogicalSchemaFingerprint`, S1 rosters — computed from SQLite-specific inputs, so they do not survive an engine change | §4.1, headline finding 4 | An operational re-baseline of every existing install. Not a code change |
| 5 | **Missing runtime / CI proof** | The absence of any non-SQLite service in which to execute a claim | Headline finding 1, §9 | Infrastructure. Until it exists, every engine verdict here is documented-behaviour reasoning, not observation |

Categories 1 and 5 are prerequisites: neither is made smaller by doing category 2 work, and category 2 work
cannot be validated without category 5. Category 4 is an operational cost that scales with the number of
installs, not with the size of the codebase. Category 3 would exist even if portability were abandoned.

---

## 3. Disposition summary

358 confirmed executable occurrences across 108 files. PostgreSQL and MySQL are reported separately and are
never combined. Every figure in this section is computed from the §4 tables by the derivation given in §1 —
none is maintained by hand.

| Disposition | Count | Share |
|---|---:|---:|
| structural-gap | 135 | 38% |
| mechanical-gap | 145 | 41% |
| guarded | 15 | 4% |
| portable | 63 | 18% |
| **Total** | **358** | |

### PostgreSQL position, by disposition

| Disposition | works | differs | unsupported | unknown | Total |
|---|---:|---:|---:|---:|---:|
| structural-gap | 7 | 1 | 124 | 3 | 135 |
| mechanical-gap | 31 | 11 | 97 | 6 | 145 |
| guarded | 9 | 0 | 6 | 0 | 15 |
| portable | 63 | 0 | 0 | 0 | 63 |
| **Total** | **110** | **12** | **227** | **9** | **358** |

### MySQL position, by disposition

| Disposition | works | differs | unsupported | unknown | Total |
|---|---:|---:|---:|---:|---:|
| structural-gap | 3 | 8 | 121 | 3 | 135 |
| mechanical-gap | 22 | 12 | 105 | 6 | 145 |
| guarded | 6 | 0 | 9 | 0 | 15 |
| portable | 61 | 2 | 0 | 0 | 63 |
| **Total** | **92** | **22** | **235** | **9** | **358** |

**The two columns disagree on 64 of 358 occurrences** — counted mechanically as rows whose PostgreSQL cell differs from their MySQL cell. The disagreements are not noise and they do not run in one direction. MySQL-only failures that PostgreSQL takes cleanly: `CREATE INDEX IF NOT EXISTS` (unsupported on stock MySQL 8, supported on PG 9.5+ and MariaDB 10.6), partial indexes with a `WHERE` clause, `DBALSchema.php:320` collapsing every declared `VARCHAR(n)` to unbounded `TEXT` (harmless on PG, fatal on MySQL wherever such a column sits in a PRIMARY KEY), double-quote identifier quoting under default `sql_mode`, `CAST(x AS TEXT)`, `DROP INDEX` without an `ON <table>` clause, and reserved-word column names (`key`, `signed`, `cursor`). PostgreSQL-only failures that MySQL takes cleanly: the entire `json_extract` surface, boolean-as-integer binding against a real BOOLEAN column, `DATETIME` as a type name, and `BLOB` as a type name. **Neither column can be inferred from the other.**

### Owning package

| Package | Occurrences | of which structural |
|---|---:|---:|
| foundation | 76 | 30 |
| entity-storage | 64 | 19 |
| deployer | 42 | 20 |
| database-legacy | 34 | 17 |
| cli | 27 | 15 |
| search | 23 | 17 |
| audit | 21 | 7 |
| bin (governance gates) | 19 | 6 |
| oidc | 10 | 2 |
| api | 6 | 0 |
| cache | 6 | 0 |
| attachment | 5 | 0 |
| ai-vector | 4 | 1 |
| field | 4 | 0 |
| queue | 4 | 0 |
| auth | 2 | 0 |
| frankenphp | 2 | 0 |
| media | 2 | 0 |
| migration | 2 | 0 |
| relationship | 2 | 1 |
| listing | 1 | 0 |
| notification | 1 | 0 |
| scheduler | 1 | 0 |
| **Total** | **358** | **135** |

**#1588 sequencing question, stated plainly:** `database-legacy` carries 34 occurrences, 17 of them structural, and is slated for retirement under #1588. Every structural item in it — the SQLite topology contract, the `createSqlite()` connection factory, the `pdo_sqlite`/`path`/`memory` DriverManager parameters — is precisely the code a port would have to rewrite. #2447 must decide whether portability work targets `database-legacy` as it stands or waits for its replacement. This audit takes no position; it records that the decision is unavoidable and that 17 structural gaps sit on the wrong side of it.

---

## 4. Per-subsystem inventory

Ordered by structural-gap count, most severe first. Every confirmed occurrence is enumerated; the value of this document is that it is exhaustive rather than representative.

---

### 4.1 Migration authority and the v2 schema compiler — 72 occurrences, 29 structural

**Owner:** `packages/foundation`, `packages/cli`.

**What the coupling is.** Three layers, each SQLite-shaped for a different reason. (a) The ledger itself is created and introspected with SQLite-only statements — `AUTOINCREMENT`, `INSERT OR IGNORE`, two `sqlite_master` probes, and six `PRAGMA` reads. (b) The v2 migration algebra compiles through exactly one backend, `SqliteCompiler`, whose op-support surface is defined by SQLite's `ALTER TABLE` limitations and whose capability predicates are `version_compare()` against a SQLite version string. (c) The schema fingerprint and the recorded `diff_hash` are both defined over SQLite artefacts.

**Why it is load-bearing.** `ledgerExists()` (which the `sqlite_master` probe at `:89` implements) gates `hasRun`, `getCompleted`, `allWithChecksums`, and `assertReadableLedger` — on a non-SQLite driver the ledger is simply unreadable, so nothing downstream runs. `migrate --dry-run` and `--verify` return exit code 2 without a compiler (`MigrateHandler.php:94`, `:112`). The `plan_mismatch` verdict at `VerifyRunner.php:140` is defined against SQLite-compiler output, which means every historically recorded row would fail verification after a compiler change even if the schema were identical.

**PostgreSQL position.** The ledger DDL and DML substitute cleanly (`information_schema.tables`, `information_schema.columns`, `pg_indexes`, `ON CONFLICT DO NOTHING`, identity columns). Notably, five statements reported as gaps are not: PG accepts the `NULL` column constraint in `ADD COLUMN` (`:267`, `:272`, `:304`), and `CREATE INDEX IF NOT EXISTS` (`:290`). The genuinely blocking items are the compiler monoculture and the fingerprint identity.

**MySQL position.** Same compiler and fingerprint blockers, plus three MySQL-only ones PG does not have: `CREATE UNIQUE INDEX IF NOT EXISTS` (`:290`) is a syntax error on stock MySQL 8, double-quote identifier quoting (`SqliteIdentifier.php:24`) is a string literal under default `sql_mode`, and `DROP INDEX` without `ON <table>` (`DropIndexTranslator.php:43`) is invalid because MySQL index names are table-scoped.

#### Ledger creation, upgrade, and readability — `packages/foundation/src/Migration/MigrationRepository.php`

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `MigrationRepository.php:57` | `AUTOINCREMENT` in `installOrUpgradeLedger()` | unsupported | unsupported | mechanical-gap |
| `MigrationRepository.php:61` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` | works | differs | portable |
| `MigrationRepository.php:89` | `sqlite_master` ledger-existence probe | unsupported | unsupported | mechanical-gap |
| `MigrationRepository.php:156` | `CREATE TABLE IF NOT EXISTS waaseyaa_schema_authority` | works | works | portable |
| `MigrationRepository.php:145` | `INSERT OR IGNORE` — the writer-position claim, now the transaction's first statement (#2450) | unsupported | unsupported | mechanical-gap |
| `MigrationRepository.php:165` | second `INSERT OR IGNORE` — first-install path, after the `CREATE TABLE` (added by #2450) | unsupported | unsupported | mechanical-gap |
| `MigrationRepository.php:119` | `UPDATE ... generation = generation + 1` | works | works | portable |
| `MigrationRepository.php:202` | `sqlite_master` (authority manifest read) | unsupported | unsupported | mechanical-gap |
| `MigrationRepository.php:209` | `PRAGMA table_info` (manifest read; name-only) | unsupported | unsupported | mechanical-gap |
| `MigrationRepository.php:261` | `PRAGMA table_info` (ledger schema upgrade) | unsupported | unsupported | mechanical-gap |
| `MigrationRepository.php:267` | `ALTER TABLE ADD COLUMN checksum VARCHAR(64) NULL` | works | works | portable |
| `MigrationRepository.php:272` | `ALTER TABLE ADD COLUMN diff_hash VARCHAR(64) NULL` | works | works | portable |
| `MigrationRepository.php:290` | `CREATE UNIQUE INDEX IF NOT EXISTS` | works | unsupported | mechanical-gap |
| `MigrationRepository.php:298` | `PRAGMA table_info` inside `acquireSchemaAuthority()` | unsupported | unsupported | mechanical-gap |
| `MigrationRepository.php:304` | `ALTER TABLE waaseyaa_schema_authority ADD COLUMN %s VARCHAR(64) NULL` | works | works | portable |
| `MigrationRepository.php:522` | `PRAGMA table_info` in `assertReadableLedger()` | unsupported | unsupported | mechanical-gap |
| `MigrationRepository.php:535` | `PRAGMA index_list` | unsupported | unsupported | mechanical-gap |
| `MigrationRepository.php:539` | `PRAGMA index_list` result-column shape (`name`, `unique`) | unsupported | unsupported | mechanical-gap |

`:145`, `:156`, `:165`, `:298` and `:304` are the lines the #2446 repairs (#2450, #2452) produced. Their dialect disposition and the concurrency defect that produced them are independent: the repair changed *which statement runs first*, not *which dialect it is written in*, so each is still an `INSERT OR IGNORE`, a `CREATE TABLE IF NOT EXISTS`, or a `PRAGMA table_info` after the fix as before it.

#### Schema fingerprint and transaction boundary

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `Migration/LogicalSchemaFingerprint.php:16` | `sqlite_schema` catalogue scan (`type, name, tbl_name, sql`) | unsupported | unsupported | structural-gap |
| `Migration/LogicalSchemaFingerprint.php:33` | domain constant `waaseyaa.logical-sqlite-schema.v1` | unsupported | unsupported | structural-gap |
| `Migration/SchemaMutationCoordinator.php:40` | `Connection::transactional()` (deferred BEGIN) | works | works | portable |
| `Migration/Migrator.php:209` | nested `Connection::transactional()` (`applyLegacy`) | works | works | portable |
| `Migration/Migrator.php:233` | nested `Connection::transactional()` (`applyV2`) | works | works | portable |

#### v2 plan execution and the SQLite compiler — `packages/foundation/src/Schema/Compiler/Sqlite/`

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `Migration/Executor/V2PlanExecutor.php:40` | `private SqliteCompiler $compiler` — hardcoded concrete dependency | unsupported | unsupported | structural-gap |
| `Migration/Executor/V2PlanExecutor.php:45` | SQLite-compiled SQL executed unmodified on the live connection | unsupported | unsupported | structural-gap |
| `SqliteCompiler.php:75` | `final readonly class SqliteCompiler` — the sole schema compiler | unsupported | unsupported | structural-gap |
| `SqliteCompiler.php:105` | op-dispatch table routing every `SchemaDiffOp` to a Sqlite translator | unsupported | unsupported | structural-gap |
| `Translator/SqliteColumnType.php:39` | `'boolean' => 'INTEGER'`, `'float' => 'REAL'` type map | works | works | structural-gap |
| `Translator/SqliteColumnType.php:55` | `VARCHAR(n)` emission | works | works | portable |
| `Translator/SqliteIdentifier.php:24` | double-quote identifier quoting | works | unsupported | mechanical-gap |
| `Translator/SqliteIdentifier.php:38` | boolean `DEFAULT` rendered as `1`/`0` | works | works | structural-gap |
| `Translator/AddColumnTranslator.php:28` | `ALTER TABLE %s ADD COLUMN %s %s` | works | works | portable |
| `Translator/DropColumnTranslator.php:42` | `ALTER TABLE %s DROP COLUMN %s` | works | works | portable |
| `Translator/RenameColumnTranslator.php:29` | SQLite-version capability gate on `RENAME COLUMN` | unsupported | unsupported | structural-gap |
| `Translator/RenameColumnTranslator.php:42` | `ALTER TABLE %s RENAME COLUMN %s TO %s` | works | works | portable |
| `Translator/RenameTableTranslator.php:22` | `ALTER TABLE %s RENAME TO %s` | works | works | portable |
| `Translator/AddIndexTranslator.php:49` | `CREATE [UNIQUE] INDEX %s ON %s (%s)` | works | works | portable |
| `Translator/DropIndexTranslator.php:43` | `DROP INDEX %s` with no `ON <table>` | works | unsupported | mechanical-gap |
| `Translator/AlterColumnTranslator.php:28` | `AlterColumn` unconditionally rejected (`: never`) | unsupported | unsupported | structural-gap |
| `Translator/ForeignKeyTranslator.php:29` | `AddForeignKey` rejected (`: never`) | unsupported | unsupported | structural-gap |
| `Translator/ForeignKeyTranslator.php:40` | `DropForeignKey` rejected (`: never`) | unsupported | unsupported | structural-gap |
| `SqliteCapabilities.php:58` | `version_compare($version, '3.25.0', '>=')` | unsupported | unsupported | structural-gap |
| `SqliteCapabilities.php:60` | `version_compare($version, '3.35.0', '>=')` | unsupported | unsupported | structural-gap |
| `Migration/LedgerSchema/V2_0001_add_checksum_columns.php:62` | `ColumnSpec(type: 'varchar', length: 64)` | works | works | portable |
| `Migration/LedgerSchema/V2_0001_add_checksum_columns.php:67` | `ColumnSpec(type: 'varchar', length: 64)` | works | works | portable |

`SqliteColumnType.php:39` and `SqliteIdentifier.php:38` are marked structural despite executing correctly on all three engines: they must be fixed together (an `INTEGER` column with a `DEFAULT 1` is self-consistent; a real `BOOLEAN` column with a `DEFAULT 1` is not on PG), and changing either changes every recorded `diff_hash`.

#### CLI migration wiring and verification — `packages/cli`

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `Provider/MigrateServiceProvider.php:102` | `SqliteCompiler` injected into `MigrateHandler` by concrete type | unsupported | unsupported | structural-gap |
| `Provider/MigrateServiceProvider.php:139` | `DBALDatabase::createSqlite()` — the migrate runtime connection | unsupported | unsupported | structural-gap |
| `Provider/MigrateServiceProvider.php:162` | `SELECT sqlite_version()` feeding `SqliteCapabilities::forVersion()` | unsupported | unsupported | structural-gap |
| `Handler/MigrateHandler.php:39` | `?SqliteCompiler` constructor dependency | unsupported | unsupported | structural-gap |
| `Command/Migrate/DryRunPlanner.php:33` | `SqliteCompiler` constructor dependency | unsupported | unsupported | structural-gap |
| `Command/Migrate/DryRunPlanner.php:80` | SQLite compilation of every pending v2 plan for the operator preview | unsupported | unsupported | structural-gap |
| `Command/Migrate/VerifyRunner.php:36` | `SqliteCompiler` constructor dependency | unsupported | unsupported | structural-gap |
| `Command/Migrate/VerifyRunner.php:140` | `plan_mismatch` verdict defined against SQLite-compiled plan hash | unsupported | unsupported | structural-gap |
| `Command/Migrate/VerifyRunner.php:159` | authority comparison derived from the `sqlite_schema` fingerprint | unsupported | unsupported | structural-gap |
| `Handler/DbInitHandler.php:72` | `DBALDatabase::createSqlite()` (install entry point) | unsupported | unsupported | structural-gap |
| `Handler/DbInitHandler.php:76` | operator error text: "Database at %s exists" — names the database by filesystem path | works | works | mechanical-gap |
| `Handler/DbInitHandler.php:77` | operator recovery text: `mv waaseyaa.sqlite waaseyaa.sqlite.bak` — single-file recovery instruction | works | works | mechanical-gap |
| `Handler/DbInitHandler.php:216` | `:memory:` sentinel branch (dry-run path) | unsupported | unsupported | structural-gap |
| `Handler/DbInitHandler.php:228` | `DBALDatabase::createSqlite()` (second construction site) | unsupported | unsupported | structural-gap |
| `Handler/DbInitHandler.php:275` | `:memory:` sentinel (`ensureParentDirectory`) | unsupported | unsupported | structural-gap |
| `Handler/DbInitHandler.php:299` | `:memory:` sentinel + `@touch()` file creation | unsupported | unsupported | structural-gap |
| `Handler/DbInitHandler.php:326` | `:memory:` sentinel + POSIX advisory lock in `dirname($dbPath)` | unsupported | unsupported | structural-gap |
| `Handler/MakeStorageMigrationHandler.php:87` | `TypeMapping::PLATFORM_SQLITE` hardcoded for every generated migration | unsupported | unsupported | mechanical-gap |
| `Site/Recipe/SubscriptionRecipe.php:125` | `AUTOINCREMENT` inside a generated migration template | unsupported | unsupported | mechanical-gap |

`SubscriptionRecipe.php:125` propagates: every site scaffolded from the recipe emits a SQLite-only migration into a downstream consumer's repository, so a later fix does not repair already-generated sites.

#### Migration generators — mostly portable, and one of only two well-guarded generators

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `Handler/AddTranslationsMigrationGenerator.php:172` | platform branch: explicit `mysql` and `postgresql` arms, SQLite fall-through | works | works | guarded |
| `Handler/AddTranslationsMigrationGenerator.php:201` | partial unique index `WHERE langcode = default_langcode`; MySQL diverted at `:193-198` | works | unsupported | guarded |
| `Handler/AddTranslationsMigrationGenerator.php:114` | `langcode VARCHAR(12) NOT NULL` | works | works | portable |
| `Handler/AddTranslationsMigrationGenerator.php:140` | `ALTER TABLE %s DROP COLUMN %s` | works | works | portable |
| `Handler/AddTranslationsMigrationGenerator.php:414` | `langcode VARCHAR(12)` in the two-axis translation table | works | works | portable |
| `Handler/AddRevisionsMigrationGenerator.php:156` | `ALTER TABLE %s ADD COLUMN vid INTEGER NOT NULL DEFAULT 0` | works | works | portable |
| `Handler/AddRevisionsMigrationGenerator.php:327` | `langcode VARCHAR(12) NOT NULL` | works | works | portable |
| `Handler/AddRevisionsMigrationGenerator.php:420` | `ALTER TABLE %s DROP COLUMN %s` (generated `down()`) | works | works | portable |

Worth flagging the asymmetry plainly: the translations generator branches on platform, its revisions sibling has no platform branch at all. Also, the code generated at `:172-188` calls `AbstractPlatform::getName()`, which was removed in DBAL 4.

---

### 4.2 Connection topology and kernel database bootstrap — 32 occurrences, 20 structural

**Owner:** `packages/database-legacy`, `packages/foundation`.

**What the coupling is.** Database identity is modelled as a **local filesystem path, not a connection descriptor**, and the S1 durability contract is expressed entirely in SQLite PRAGMA vocabulary with a fail-closed readback assertion at boot.

**Why it is load-bearing.** `SqliteTopology::assertSupportedPath()` at `:25` rejects any value matching a scheme/DSN pattern (`:37`) with S1-DB001 — so a PostgreSQL connection string cannot be *expressed* in configuration, let alone used. `configureAndVerify()` then executes three PRAGMA writes and asserts three PRAGMA readbacks, and `throwForPragmaMismatches()` (`:111-136`) is boot-blocking under S1-DB003. `DatabaseBootstrapper::boot()` (`:29`) unconditionally returns a SQLite connection with no branch and no configuration key that selects another driver. This is the single line that pins the framework's serving engine.

**PostgreSQL position.** Nothing here works. There is no PG factory anywhere in production wiring; `PRAGMA` is not a statement; WAL, `journal_mode`, and `busy_timeout` have no readback analogue (the nearest session settings are `lock_timeout` / `statement_timeout`). The one thing that improves is `DBALConsistentReadTransaction.php:29` — see §5.

**MySQL position.** Identical to PG for every topology item; the analogue for `busy_timeout` is `innodb_lock_wait_timeout`, again with different units and polarity.

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `database-legacy/src/SqliteTopology.php:15` | `BUSY_TIMEOUT_MS` contract constant | unknown | unknown | structural-gap |
| `database-legacy/src/SqliteTopology.php:25` | `:memory:` sentinel + DSN/URI rejection in `assertSupportedPath()` | unsupported | unsupported | structural-gap |
| `database-legacy/src/SqliteTopology.php:49` | `:memory:` allowed only in dev (production boot policy, S1-DB002) | unsupported | unsupported | structural-gap |
| `database-legacy/src/SqliteTopology.php:75` | `PRAGMA foreign_keys = ON` | unsupported | unsupported | mechanical-gap |
| `database-legacy/src/SqliteTopology.php:76` | `PRAGMA busy_timeout = 5000` | unsupported | unsupported | mechanical-gap |
| `database-legacy/src/SqliteTopology.php:78` | `PRAGMA journal_mode = WAL` | unsupported | unsupported | structural-gap |
| `database-legacy/src/SqliteTopology.php:87` | `PRAGMA foreign_keys` readback assertion | unsupported | unsupported | structural-gap |
| `database-legacy/src/SqliteTopology.php:88` | `PRAGMA busy_timeout` readback assertion | unsupported | unsupported | structural-gap |
| `database-legacy/src/SqliteTopology.php:89` | `PRAGMA journal_mode` readback assertion | unsupported | unsupported | structural-gap |
| `database-legacy/src/SqliteTopology.php:96` | `PRAGMA foreign_keys = ON` on the raw driver connection | unsupported | unsupported | mechanical-gap |
| `database-legacy/src/SqliteTopology.php:97` | `PRAGMA busy_timeout` on the raw driver connection | unsupported | unsupported | mechanical-gap |
| `database-legacy/src/SqliteTopology.php:99` | `PRAGMA journal_mode = WAL` on the raw driver connection | unsupported | unsupported | structural-gap |
| `database-legacy/src/SqliteTopology.php:103` | `PRAGMA foreign_keys` readback (driver connection) | unsupported | unsupported | structural-gap |
| `database-legacy/src/SqliteTopology.php:104` | `PRAGMA busy_timeout` readback (driver connection) | unsupported | unsupported | structural-gap |
| `database-legacy/src/SqliteTopology.php:105` | `PRAGMA journal_mode` readback (driver connection) | unsupported | unsupported | structural-gap |
| `database-legacy/src/SqliteTopology.php:125` | `journal_mode` must equal `wal` for file-backed databases (boot-blocking) | unsupported | unsupported | structural-gap |
| `database-legacy/src/SqliteDriverMiddleware.php:33` | PRAGMA contract re-established on every physical connect | unsupported | unsupported | **guarded** (§5) |
| `database-legacy/src/DBALDatabase.php:50` | `SQLitePlatform` instanceof guard on the schema-assets filter | works | works | **guarded** (§5) |
| `database-legacy/src/DBALDatabase.php:55` | `sqlite_` internal-object name-prefix exclusion | works | works | **guarded** (§5) |
| `database-legacy/src/DBALDatabase.php:87` | `pragma_table_list` table-valued function (SQLite >= 3.37) | unsupported | unsupported | **guarded** (§5) |
| `database-legacy/src/DBALDatabase.php:104` | `sqlite_master` catalogue scan (fallback for SQLite < 3.37) | unsupported | unsupported | **guarded** (§5) |
| `database-legacy/src/DBALDatabase.php:125` | `createSqlite()` — the only named connection factory; default path `:memory:` | unsupported | unsupported | structural-gap |
| `database-legacy/src/DBALDatabase.php:136` | hardcoded `'driver' => 'pdo_sqlite'` | unsupported | unsupported | structural-gap |
| `database-legacy/src/DBALDatabase.php:137` | `':memory:'` → `null` path translation in connection params | unsupported | unsupported | structural-gap |
| `database-legacy/src/DBALDatabase.php:138` | `'memory'` connection parameter (SQLite-only DBAL param) | unsupported | unsupported | structural-gap |
| `database-legacy/src/DBALDatabase.php:190` | `PRAGMA` treated as a result-returning verb in the query dispatcher | works | works | portable |
| `foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php:29` | production wiring hardcodes `DBALDatabase::createSqlite()` | unsupported | unsupported | structural-gap |
| `foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php:55` | default database path is `{projectRoot}/storage/waaseyaa.sqlite` | unsupported | unsupported | structural-gap |
| `foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php:89` | `:memory:` sentinel in the production-path guard | unsupported | unsupported | structural-gap |
| `foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php:115` | `:memory:` sentinel in `warnWhenInsideDocroot()` (FR-008) | unsupported | unsupported | mechanical-gap |
| `foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php:213` | `:memory:` sentinel in `isAbsoluteOrMemory()` | unsupported | unsupported | structural-gap |
| `foundation/src/Kernel/AbstractKernel.php:431` | `assert($this->database instanceof DBALDatabase)` | works | works | portable |

Two things the verifiers corrected and that matter for scoping. First, `DBALDatabase` itself is **not** SQLite-only — its constructor accepts any Doctrine `Connection`; the coupling is that `createSqlite()` is the only named factory and installs `SqliteDriverMiddleware` and the PRAGMA contract in the same expression. Second, the `if ($fileBacked)` conditions at `:77` and `:98` are memory-vs-file distinctions, **not** driver guards, so they do not appear in §5.

---

### 4.3 Deploy artifact pipeline — 42 occurrences, 20 structural

**Owner:** `packages/deployer`.

**What the coupling is.** The deploy model is "a database is one file you copy, hash, and rename". Backup is a byte copy verified by `hash_equals` on a sha256 digest (`RuntimeState/SqliteArtifactInstaller.php:40-44`); activation is a filesystem `rename()` (`RuntimeState/SqliteArtifactInstaller.php:93`, `:98`); the rollback path at `RuntimeState/SqliteArtifactInstaller.php:109-121` is built on that rename being atomic and reversible; quiescence is proved by `filesize($wal) !== 0` (`RuntimeState/SqliteArtifactPreparer.php:180`); validity is `PRAGMA integrity_check` returning `'ok'`. Schema cloning replays the stored `CREATE` text read straight out of `sqlite_master` (`RuntimeState/SqliteArtifactPreparer.php:247`, exec'd verbatim at `:254`).

**Why it is load-bearing.** Every safety property of the deploy — byte identity of the backup, atomicity of activation, the pre-install `wal_checkpoint(TRUNCATE)` busy=0 assertion at `RuntimeState/SqliteArtifactInstaller.php:186`/`:189`, the post-activation integrity gate at `RuntimeState/SqliteArtifactInstaller.php:217` that triggers rollback — is a property of the SQLite file model. None of them translate.

**PostgreSQL position.** No part of the pipeline survives. `pg_dump`/`pg_basebackup` give no byte identity; there is no whole-database integrity statement (amcheck is an extension covering indexes only); there is no sidecar-file quiescence signal; there is no `immutable=1` read-only promise. `PRAGMA foreign_keys = OFF` has no direct substitute — the nearest is `SET session_replication_role = replica`, which is superuser-only, i.e. a privilege-model change.

**MySQL position.** Equally unsupported for the file model. `SET FOREIGN_KEY_CHECKS = 0/1` is a clean substitute for the two FK pragmas; `REPLACE INTO` matches `INSERT OR REPLACE` semantics exactly; but there is no database-wide integrity statement (only per-table `CHECK TABLE`) and no `foreign_key_check` audit. MySQL's implicit DDL commit is an additional hazard for the transactional drop at `Installer` and `Preparer`.

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `RuntimeState/SqliteArtifactPreparer.php:47` | `sqlite_sequence` in the allowed-table set | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:56` | file-level database copy instead of a logical dump | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactPreparer.php:65` | `PRAGMA foreign_keys = OFF` | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:122` | `PRAGMA foreign_keys = ON` | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:123` | `PRAGMA foreign_key_check` | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactPreparer.php:156` | `sqlite_sequence` internal-name reservation | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:176` | `-wal`/`-shm` sidecar-file assertion | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactPreparer.php:180` | WAL sidecar emptiness as the quiescence proof | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactPreparer.php:198` | `sqlite:` PDO DSN with `mode=ro&immutable=1` | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactPreparer.php:199` | `sqlite:` PDO DSN (read-write) | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactPreparer.php:201` | raw `new \PDO` bypassing `DatabaseInterface` entirely | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactPreparer.php:209` | `PRAGMA integrity_check` | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactPreparer.php:233` | `sqlite_master` table enumeration | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:239` | `sqlite_master` `tableExists()` probe | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:247` | `sqlite_master` DDL replay (schema cloning from stored CREATE text) | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactPreparer.php:267` | `PRAGMA table_xinfo` | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:268` | `PRAGMA foreign_key_list` | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:272` | `PRAGMA index_list` | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:282` | `PRAGMA index_xinfo` | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:285` | `sqlite_master` trigger query | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:294` | `sqlite_master` schema-SQL lookup (required; throws when absent) | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:306` | `sqlite_master` schema-SQL lookup (optional) | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:321` | `PRAGMA table_info` (copyRows column list) | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:331` | `INSERT OR REPLACE` | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:344` | `PRAGMA table_info` (profile column list) | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:386` | `PRAGMA table_info` (account-reference verification) | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:397` | integer-or-numeric-string account-id acceptance | works | works | portable |
| `RuntimeState/SqliteArtifactPreparer.php:421` | hardcoded double-quote identifier quoting | works | differs | mechanical-gap |
| `RuntimeState/SqliteArtifactPreparer.php:428` | SQLite-specific error message in `prepareStatement()` | works | works | mechanical-gap |
| `RuntimeState/SqliteArtifactInstaller.php:38` | WAL checkpoint before install | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactInstaller.php:40` | backup by byte-copying the database file | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactInstaller.php:93` | atomic activation by filesystem rename (preserve current) | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactInstaller.php:98` | atomic activation by filesystem rename (promote candidate) | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactInstaller.php:181` | raw `new \PDO` with `sqlite:` DSN | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactInstaller.php:185` | `PRAGMA busy_timeout` | unsupported | unsupported | mechanical-gap |
| `RuntimeState/SqliteArtifactInstaller.php:186` | `PRAGMA wal_checkpoint(TRUNCATE)` + three-column result assertion | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactInstaller.php:187` | `PRAGMA integrity_check` (pre-install) | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactInstaller.php:198` | `-wal`/`-shm` sidecar absence assertion | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactInstaller.php:199` | sidecar diagnostic message (condition has no counterpart) | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactInstaller.php:214` | `sqlite:` PDO DSN with `mode=ro&immutable=1` | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactInstaller.php:217` | `PRAGMA integrity_check` (post-activation; triggers rollback) | unsupported | unsupported | structural-gap |
| `RuntimeState/SqliteArtifactInstaller.php:219` | SQLite-specific error message | works | works | mechanical-gap |

**Scoping caveat, stated plainly:** the verifier found that neither `SqliteArtifactPreparer` nor `SqliteArtifactInstaller` has a production caller inside the monorepo — the only references are their own definitions and the allowlist at `bin/check-s1-sqlite-contract:63-66`. They are `@api` surfaces for downstream deploy tooling. That does not reduce their portability cost, but it does mean their blast radius sits outside this repository and cannot be measured from here.

---

### 4.4 Database-enforced integrity triggers and partial indexes — 22 occurrences, 19 structural

**Owner:** `packages/entity-storage`, `packages/foundation`, `packages/audit`, `packages/oidc`.

**What the coupling is.** Sixteen `CREATE TRIGGER ... RAISE(ABORT, ...)` statements plus five partial unique indexes, all in shipped migrations, all unguarded (their only enclosing conditions are `$schema->hasTable()` presence checks).

**Why it is load-bearing.** These are not conveniences. The genesis-marker migration's header comment at `:53-55` states the intent exactly: enforcement happens "independently of the application-layer request validation". Append-only provenance for the configuration manifest, monotonic replay high-water, audit checkpoint succession immutability, application-master-rekey event immutability, "exactly one active OIDC signing key", and "one open rekey failure per adapter" all exist **only** as database objects. Dropping them during a port silently removes the invariant rather than breaking the build.

**PostgreSQL position.** Three independent blockers per trigger: `CREATE TRIGGER IF NOT EXISTS` does not exist (PG has `CREATE OR REPLACE TRIGGER`); `RAISE(ABORT, ...)` requires a PL/pgSQL function plus a `CREATE TRIGGER ... EXECUTE FUNCTION` binding; and PG's trigger `WHEN` clause forbids subqueries, which four of the manifest-replay triggers use. PG does support `UPDATE OF <columns>` and it does support partial indexes verbatim, so all five partial unique indexes are PG-clean.

**MySQL position.** Worse on triggers (no `IF NOT EXISTS`, no `WHEN` clause at all, needs `SIGNAL SQLSTATE '45000'` inside the body) and worse on indexes (no partial indexes; the workaround is a generated column plus a unique index, with different NULL semantics).

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `entity-storage/migrations/2026_08_15_000004_configuration_manifest_replay.php:60` | `signed INTEGER NOT NULL CHECK (signed IN (0,1))` — `SIGNED` is a MySQL reserved word | works | unsupported | mechanical-gap |
| `entity-storage/migrations/2026_08_15_000004_configuration_manifest_replay.php:69` | `CREATE TRIGGER IF NOT EXISTS ... WHEN NOT EXISTS (subquery) ... RAISE(ABORT)` | unsupported | unsupported | structural-gap |
| `entity-storage/migrations/2026_08_15_000004_configuration_manifest_replay.php:80` | `BEFORE UPDATE OF authority_id, activation_sequence ... WHEN NOT EXISTS (subquery)` | unsupported | unsupported | structural-gap |
| `entity-storage/migrations/2026_08_15_000004_configuration_manifest_replay.php:91` | `WHEN NEW.last_sequence <= OLD.last_sequence ... RAISE(ABORT)` (monotonic high-water) | unsupported | unsupported | structural-gap |
| `entity-storage/migrations/2026_08_15_000004_configuration_manifest_replay.php:99` | activation-exists guard, `WHEN NOT EXISTS (subquery)` | unsupported | unsupported | structural-gap |
| `entity-storage/migrations/2026_08_15_000004_configuration_manifest_replay.php:110` | `BEFORE UPDATE ... RAISE(ABORT)` append-only refusal | unsupported | unsupported | structural-gap |
| `entity-storage/migrations/2026_08_15_000004_configuration_manifest_replay.php:117` | `BEFORE DELETE ... RAISE(ABORT)` append-only refusal | unsupported | unsupported | structural-gap |
| `entity-storage/migrations/2026_08_15_000004_configuration_manifest_replay.php:124` | entry-contract UPDATE refusal | unsupported | unsupported | structural-gap |
| `entity-storage/migrations/2026_08_15_000004_configuration_manifest_replay.php:131` | entry-contract DELETE refusal | unsupported | unsupported | structural-gap |
| `entity-storage/migrations/2026_08_19_000005_configuration_genesis_marker.php:42` | `PRAGMA table_info(%s)` with an unquoted interpolated table name | unsupported | unsupported | mechanical-gap |
| `entity-storage/migrations/2026_08_19_000005_configuration_genesis_marker.php:48` | `ALTER TABLE ADD COLUMN is_genesis INTEGER NOT NULL DEFAULT 0 CHECK (...)` | unsupported | unsupported | mechanical-gap *(disputed — §7)* |
| `entity-storage/migrations/2026_08_19_000005_configuration_genesis_marker.php:58` | `CREATE TRIGGER IF NOT EXISTS ... WHEN ... RAISE(ABORT)` (genesis activation guard) | unsupported | unsupported | structural-gap |
| `entity-storage/migrations/2026_08_19_000005_configuration_genesis_marker.php:69` | `CREATE TRIGGER IF NOT EXISTS ... RAISE(ABORT)` (genesis candidate guard) | unsupported | unsupported | structural-gap |
| `foundation/migrations/2026_08_15_000002_application_master_rekey.php:110` | partial unique index `WHERE resolved_at IS NULL` | works | unsupported | structural-gap |
| `foundation/migrations/2026_08_15_000002_application_master_rekey.php:157` | `CREATE TRIGGER ... RAISE(ABORT)` (update protection) | unsupported | unsupported | structural-gap |
| `foundation/migrations/2026_08_15_000002_application_master_rekey.php:160` | `CREATE TRIGGER ... RAISE(ABORT)` (delete protection) | unsupported | unsupported | structural-gap |
| `audit/migrations/2026_08_15_000007_audit_checkpoint_succession.php:46` | `CREATE TRIGGER IF NOT EXISTS ... RAISE(ABORT)` | unsupported | unsupported | structural-gap |
| `audit/migrations/2026_08_15_000007_audit_checkpoint_succession.php:49` | `CREATE TRIGGER IF NOT EXISTS ... RAISE(ABORT)` (delete half) | unsupported | unsupported | structural-gap |
| `audit/migrations/2026_08_15_000007_audit_checkpoint_succession.php:52` | `CREATE TRIGGER IF NOT EXISTS ... RAISE(ABORT)` (pruned-evidence update) | unsupported | unsupported | structural-gap |
| `audit/migrations/2026_08_15_000007_audit_checkpoint_succession.php:55` | `CREATE TRIGGER IF NOT EXISTS ... RAISE(ABORT)` (pruned-evidence delete) | unsupported | unsupported | structural-gap |
| `oidc/migrations/2026_08_15_000007_oidc_signing_key_lifecycle.php:118` | partial unique index `WHERE state = 'active-sign-and-verify'` | works | unsupported | structural-gap |
| `oidc/migrations/2026_08_15_000007_oidc_signing_key_lifecycle.php:122` | partial unique index `WHERE state = 'staged-verify-only'` | works | unsupported | structural-gap |

---

### 4.5 Full-text search (FTS5) — 23 occurrences, 17 structural

**Owner:** `packages/search`. Cross-reference: **#2056**.

**What the coupling is.** The entire search subsystem is one FTS5 virtual table plus a metadata sidecar, wired unconditionally. There is no second implementation of `SearchIndexerInterface`, `SearchProviderInterface`, or `SearchContentCatalogueInterface` anywhere in the repository.

**Why it is load-bearing.** `Fts5SearchProvider.php:67` is the single candidate-generation statement for all search. `Fts5SearchIndexer.php:42` configures the tokenizer as `unicode61, remove_diacritics 0` plus `tokenchars` for three apostrophe variants — that configuration deliberately **preserves** diacritics and is load-bearing for Anishinaabemowin text, so any substitute must reproduce the same token boundaries or search results change. `SearchServiceProvider.php:147` refuses any non-path value for `search.database` as a configuration contract, and `:164` builds its own SQLite connection when `search.database` is set, meaning even a PostgreSQL application would receive a SQLite search projection.

**PostgreSQL position.** Nothing in the FTS5 surface survives. The equivalent is a `tsvector` column plus a GIN index, `@@` matching, and `ts_rank_cd` — a different storage object and a different query grammar, not different syntax. The weighting model also differs in kind: FTS5 weights per column at query time, PG weights per lexeme at index time via `setweight()`.

**MySQL position.** Also unsupported. `FULLTEXT KEY` on an InnoDB table with `MATCH ... AGAINST(... IN BOOLEAN MODE)`; no per-column weighting at all. MySQL adds two extra failures PG does not have: the sidecar's `TEXT PRIMARY KEY` and literal `DEFAULT` on a TEXT column (`Fts5SearchIndexer.php:47`), and `CREATE INDEX IF NOT EXISTS` (`:61`).

**Mitigating fact worth recording:** `safeScore()` at `Fts5SearchProvider.php:173-189` re-scores every surviving row in PHP, so `bm25()` affects candidate truncation order, not the final ranking. The interfaces themselves are clean — they carry no SQL, no table names, and no SQLite types, and `ensureSchema()` is not on the interface. The gap is a single-implementation seam with no driver switch, which is design work rather than substitution.

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `Fts5/Fts5SearchProvider.php:59` | FTS5 phrase-query string construction (double-quoted token) | unsupported | unsupported | structural-gap |
| `Fts5/Fts5SearchProvider.php:67` | `<table> MATCH ?` against a virtual table + rank ordering | unsupported | unsupported | structural-gap |
| `Fts5/Fts5SearchProvider.php:144` | `sqlite_master` schema-presence probe | unsupported | unsupported | mechanical-gap |
| `Fts5/Fts5SearchProvider.php:317` | FTS5 hidden `rank` column | unsupported | unsupported | structural-gap |
| `Fts5/Fts5SearchProvider.php:321` | `bm25()` auxiliary function with per-column weights | unsupported | unsupported | structural-gap |
| `Fts5/Fts5SearchIndexer.php:38` | `CREATE VIRTUAL TABLE ... USING fts5` | unsupported | unsupported | structural-gap |
| `Fts5/Fts5SearchIndexer.php:39` | FTS5 `UNINDEXED` column modifier | unsupported | unsupported | structural-gap |
| `Fts5/Fts5SearchIndexer.php:42` | tokenizer config (`unicode61`, `remove_diacritics 0`, `tokenchars`) | unsupported | unsupported | structural-gap |
| `Fts5/Fts5SearchIndexer.php:47` | sidecar runtime DDL (`TEXT PRIMARY KEY`, `TEXT NOT NULL DEFAULT ''`) | works | unsupported | mechanical-gap |
| `Fts5/Fts5SearchIndexer.php:61` | `CREATE INDEX IF NOT EXISTS` (recurs at `:62-63`) | works | unsupported | mechanical-gap |
| `Fts5/Fts5SearchIndexer.php:83` | raw `INSERT` into an FTS5 virtual table | unsupported | unsupported | structural-gap |
| `Fts5/Fts5SearchIndexer.php:126` | raw `INSERT` into an FTS5 virtual table (batch reindex) | unsupported | unsupported | structural-gap |
| `Fts5/Fts5SearchIndexer.php:183` | `DROP TABLE` of an FTS5 virtual table inside a transaction | unsupported | unsupported | structural-gap |
| `Fts5/Fts5SearchIndexer.php:202` | `DELETE FROM` an FTS5 virtual table by UNINDEXED column | unsupported | unsupported | structural-gap |
| `Fts5/Fts5SearchContentCatalogue.php:42` | raw `SELECT` over `search_metadata` with ISO-8601 text ordering | works | works | portable |
| `Fts5/Fts5SearchContentCatalogue.php:89` | `sqlite_master` schema-presence probe | unsupported | unsupported | mechanical-gap |
| `SearchServiceProvider.php:36` | unconditional binding of `SearchIndexerInterface` to the FTS5 implementation | unsupported | unsupported | structural-gap |
| `SearchServiceProvider.php:91` | unconditional binding of `SearchProviderInterface` | unsupported | unsupported | structural-gap |
| `SearchServiceProvider.php:100` | unconditional binding of `SearchContentCatalogueInterface` | unsupported | unsupported | structural-gap |
| `SearchServiceProvider.php:147` | config contract refuses any non-path `search.database` value | unsupported | unsupported | structural-gap |
| `SearchServiceProvider.php:150` | `SqliteTopology::assertEnvironmentAllowsPath()` | unsupported | unsupported | structural-gap |
| `SearchServiceProvider.php:153` | `:memory:` sentinel in production wiring | unsupported | unsupported | mechanical-gap |
| `SearchServiceProvider.php:164` | `DBALDatabase::createSqlite()` — provider builds its own SQLite connection | unsupported | unsupported | structural-gap |

`:150` and `:164` are pinned verbatim by `bin/check-s1-sqlite-contract:304-308`; they cannot be edited without updating the gate in the same commit.

---

### 4.6 Entity query engine, storage driver, and parameter binding — 23 occurrences, 9 structural

**Owner:** `packages/entity-storage`, `packages/database-legacy`, `packages/relationship`.

**What the coupling is.** Two things. (a) Six independent emission sites interpolate `json_extract(<alias>._data, '$.<field>')` into raw SQL, across two files in `entity-storage` plus one in `relationship`. (b) Two `INNER`/`LEFT JOIN` sites compare a `varchar(128)` `entity_id` column against an integer base-table id.

**Why it is load-bearing.** The `json_extract` at `SqlEntityQuery.php:661` is inside the compiled **Protected-policy authorization-input projection**; the only alternative branch throws `ProtectedEntityReadProjectionException::cannotCompile`, so on PostgreSQL the closed Protected-entity read projection cannot compile at all. The cross-type join at `SqlStorageDriver.php:565` and `SqlColumnTranslationHydrator.php:88` is not a text problem: `TranslationSchemaHandler:353` declares `entity_id` as `varchar(128)` while `SqlSchemaHandler:764` declares the base id as `'serial'`, so fixing it means re-typing a column and migrating existing rows.

**None of the six `json_extract` sites has a driver guard.** Every enclosing condition the verifiers checked is a *schema-shape* test — `$bundle === null && isset(getDataStoredCoreFieldNames()[$field]) && $this->hasDataColumn()` at `:381`, `$this->columnCache[$cacheKey]` at `:399`, `$backendId === ReservedBackendIds::SQL_COLUMN` at `:661`, `schema()->fieldExists()` at `RelationshipTraversalService.php:493`. A non-SQLite connection reaches all of them.

**PostgreSQL position.** No `json_extract` function exists. The substitute is `_data::jsonb ->> 'field'`, which **always returns text** — so the int/float rules in `coerceConditionValue()` invert, and the `CAST(... AS TEXT)` compensation at `:1336` becomes redundant. Boolean binding (`ParameterTypeInferrer.php:16`) is the framework-wide rule behind three separate entity-storage boolean coercions and is the single point where the PG BOOLEAN mismatch could be fixed.

**MySQL position.** `JSON_EXTRACT` exists and accepts the same `'$.field'` path, but returns a JSON-typed value; text equality needs `JSON_UNQUOTE`. The `CAST(x AS TEXT)` at `:1336` is a *syntax error* on MySQL (`CAST ... AS CHAR` is required) — the one place in this subsystem where MySQL fails and PG does not. The cross-type join coerces implicitly rather than erroring, which defeats the index and can raise collation warnings.

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `entity-storage/src/SqlEntityQuery.php:381` | `json_extract(alias._data, '$.<field>')` into `whereRaw`/`orderByRaw` (Data-hinted core field) | unsupported | differs | structural-gap |
| `entity-storage/src/SqlEntityQuery.php:399` | `json_extract(...)` fallback for a routed field with no real column | unsupported | differs | structural-gap |
| `entity-storage/src/SqlEntityQuery.php:416` | `json_extract(_data, '$.<field>')` unqualified legacy path | unsupported | differs | structural-gap |
| `entity-storage/src/SqlEntityQuery.php:462` | `_data` blob-column probe (`schema()->fieldExists()`) gating json_extract routing | works | works | portable |
| `entity-storage/src/SqlEntityQuery.php:661` | `json_extract(...)` in the compiled Protected-policy authorization-input projection | unsupported | differs | structural-gap |
| `entity-storage/src/SqlEntityQuery.php:1336` | `CAST(<json_extract expr> AS TEXT) IN (?)` compensating json_extract return typing | works | unsupported | structural-gap |
| `entity-storage/src/SqlEntityQuery.php:1539` | `consistentReadTransaction()` as the contextual-read authorization boundary | works | works | portable |
| `entity-storage/src/Query/JsonFieldName.php:38` | allowlist regex guarding the raw `'$.<field>'` interpolation sink | works | works | portable |
| `entity-storage/src/ResolvedField.php:47` | `isJsonExtract` flag on the resolved-field value object | works | works | portable |
| `entity-storage/src/Driver/SqlStorageDriver.php:222` | reliance on driver `lastInsertId()` for the DB-assigned entity id | differs | works | mechanical-gap |
| `entity-storage/src/Driver/SqlStorageDriver.php:544` | hardcoded ANSI double-quote identifier quoting fallback (sql-column path) | works | differs | mechanical-gap |
| `entity-storage/src/Driver/SqlStorageDriver.php:565` | `INNER JOIN t.entity_id = pri.<idKey>` — TEXT column vs INTEGER column | unsupported | differs | structural-gap |
| `entity-storage/src/Driver/SqlStorageDriver.php:603` | hardcoded ANSI double-quote identifier quoting fallback (sql-blob path) | works | differs | mechanical-gap |
| `entity-storage/src/Driver/SqlStorageDriver.php:780` | `json_extract(_data, '$.<field>')` twin sink for `findBy()`/`count()` | unsupported | differs | structural-gap |
| `entity-storage/src/Hydration/SqlColumnTranslationHydrator.php:88` | `LEFT JOIN t.entity_id = pri.<idKey>` — TEXT vs INTEGER (second emission site) | unsupported | differs | structural-gap |
| `entity-storage/src/Hydration/SqlColumnTranslationHydrator.php:236` | hardcoded ANSI double-quote identifier quoting fallback | works | differs | mechanical-gap |
| `entity-storage/src/EntityRepository.php:2520` | `SELECT MAX(<idKey>) AS max_id FROM <entityType>` with unquoted identifiers | works | works | portable |
| `database-legacy/src/Query/DBALInsert.php:66` | unqualified `$this->connection->lastInsertId()` | differs | works | mechanical-gap |
| `database-legacy/src/Query/ParameterTypeInferrer.php:16` | PHP `bool` bound as `ParameterType::INTEGER` | unsupported | works | mechanical-gap |
| `database-legacy/src/Query/ParameterTypeInferrer.php:33` | bool array elements as `ArrayParameterType::INTEGER`; list type from first element | unsupported | works | mechanical-gap |
| `database-legacy/src/DBALConsistentReadTransaction.php:29` | `setTransactionIsolation(REPEATABLE_READ)` | works | works | portable |
| `relationship/src/RelationshipTraversalService.php:497` | `json_extract` with a `'$.path'` argument | unsupported | differs | mechanical-gap |
| `relationship/src/RelationshipTraversalService.php:500` | `CAST(json_extract(...) AS INTEGER)` for status/start_date/end_date | unsupported | unsupported | structural-gap |

`RelationshipTraversalService.php:500` is structural because three things differ at once: the JSON function, its return typing, and the `CAST` target keyword (MySQL requires `SIGNED`, not `INTEGER`). The NULL-tolerant timeline-overlap predicates at `:513-528` depend on all three, so no single textual substitute preserves the predicate.

---

### 4.7 Governance gates — 19 occurrences, 6 structural

**Owner:** `bin/`.

**What the coupling is.** Executable PHP, not SQL, so the engine columns are informational only. These gates make SQLite the contractual serving engine and fail closed. They are listed because they are a hard ordering constraint: the contract changes before the code can.

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `bin/check-s1-sqlite-contract:41` | pattern table enumerating and pinning every SQLite connection-construction site | unknown | unknown | structural-gap |
| `bin/check-s1-sqlite-contract:46` | every `pdo_sqlite` token must be classified in the reviewed allowlist | unknown | unknown | structural-gap |
| `bin/check-s1-sqlite-contract:179` | contract pins `authority.engine = sqlite` | unsupported | unsupported | structural-gap |
| `bin/check-s1-sqlite-contract:186` | contract pins `journal_mode = wal` (mirrored at `:242`) | unsupported | unsupported | mechanical-gap |
| `bin/check-s1-sqlite-contract:187` | contract pins `busy_timeout_ms = 5000` (mirrored at `:242`, `:345`) | unsupported | unsupported | mechanical-gap |
| `bin/check-s1-sqlite-contract:192` | contract pins the search projection engine to `sqlite` | unsupported | unsupported | structural-gap |
| `bin/check-s1-sqlite-contract:204` | contract must declare postgresql/mysql/mariadb among **refused** alternates | unsupported | unsupported | structural-gap |
| `bin/check-s1-sqlite-contract:253` | the refusal list is hardcoded in the **checker**, not only in the JSON | unsupported | unsupported | structural-gap |
| `bin/check-s1-sqlite-contract:304` | asserts three exact source strings in `SearchServiceProvider.php` (incl. `:307`) | unsupported | unsupported | mechanical-gap |
| `bin/check-s1-sqlite-contract:371` | CI must have `pdo_sqlite` and `sqlite3` loaded | unsupported | unsupported | mechanical-gap |
| `bin/check-s1-sqlite-contract:374` | SQLite library version pinned `>=3.40.0 <4.0.0` | unsupported | unsupported | mechanical-gap |
| `bin/check-support-contract:131` | support matrix declares sqlite role `s1-database-runtime` | unsupported | unsupported | mechanical-gap |
| `bin/check-support-contract:203` | root `composer.json` must require `ext-pdo_sqlite` | unsupported | unsupported | mechanical-gap |
| `bin/check-support-contract:204` | root `composer.json` must require `ext-sqlite3` | unsupported | unsupported | mechanical-gap |
| `bin/check-support-contract:284` | CI runtime SQLite version probe (`SQLite3::version()`) | unsupported | unsupported | mechanical-gap |
| `bin/check-s1-schema-authority:52` | runtime-DDL exemption keyed to `SqliteEmbeddingStorage.php` by exact path | unsupported | unsupported | mechanical-gap |
| `bin/check-s1-schema-authority:183` | runtime-DDL exemption keyed to `Fts5SearchIndexer.php` by exact path | unsupported | unsupported | mechanical-gap |
| `bin/check-s1-configuration-activation:49` | contention error-pattern roster | works | works | portable |
| `bin/test-quality-inventory:185` | database-test classification keyed on `PDO\|DBALDatabase\|createSqlite\|sqlite:` | differs | differs | mechanical-gap |

`bin/check-s1-configuration-activation:49` is worth calling out as a positive: its regex is `/\b(?:SQLITE_BUSY|database is locked|DeadlockException|LockWaitTimeoutException)\b/i` — the last two are DBAL's driver-neutral retryable-exception classes. The codebase already knows the right shape for engine-neutral contention detection; `packages/audit/src/Writer/DatabaseStrictPrivilegedReadLedger.php:126` is simply missing it.

Also visible in the schema-authority roster near `:183`: `BroadcastStorage.php` and `SqlState.php` are classified `authoritative-bypass-remediation-required`, confirming the raw-PDO escape hatches are already tracked debt independent of this audit.

---

### 4.8 Diagnostics and `schema:check` — 14 occurrences, 6 structural

**Owner:** `packages/foundation`. Cross-reference: **#1625**.

**What the coupling is.** `HealthChecker`'s entity-table drift comparison reads schema through `PRAGMA table_info` and compares against an expected-schema model written in **SQLite storage classes**, normalised through a lookup map that contains only `TEXT`/`VARCHAR`/`CLOB`/`INTEGER`/`SERIAL`/`REAL`/`BLOB`.

**Why it matters for #2447 specifically.** This is the verifier a portability effort would depend on, and it is itself a portability-shaped defect. `:642` is the existing #1625 workaround — it strips `VARCHAR(n)` to `VARCHAR` and then to `TEXT` so migration-created columns do not read as drift against entity-derived expectations. That regex does not match PostgreSQL's `character varying` at all. No PostgreSQL type name appears anywhere in the file: no `character varying`, `bigint`, `boolean`, `jsonb`, `bytea`, `timestamptz`, or `double precision`. Unmapped names fall through unchanged at `:656-657`, so **every** PG column would read as drift.

**Guard status is uneven and the verifiers corrected the initial claim.** `:518` and `:340` are genuinely guarded (catch-all → null / false). `:466`, `:559`, `:561`, `:612`, `:634`, `:642`, `:646` are **not** — the only `try`/`catch` blocks in the file are at `:186`, `:338`, and `:517`, and none enclose `detectSubtableColumnDrift()` (`:462`) or `detectTableDrift()` (`:559`) or their callers. `checkSchemaDrift()` is public and is the primary `schema:check` path, so it throws on a non-SQLite driver.

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `Diagnostic/HealthChecker.php:187` | `SELECT 1` liveness probe | works | works | portable |
| `Diagnostic/HealthChecker.php:340` | `PRAGMA table_info` in `columnExists()`; `catch (\Throwable)` then `return false` — error tolerance, not a platform guard (§5.1) | unsupported | unsupported | mechanical-gap |
| `Diagnostic/HealthChecker.php:466` | `PRAGMA table_info` in `detectSubtableColumnDrift()` — unguarded, throws | unsupported | unsupported | mechanical-gap |
| `Diagnostic/HealthChecker.php:518` | `PRAGMA foreign_keys` FK-enforcement check; `catch (\Throwable) { return null; }` — error tolerance, not a platform guard (§5.1) | unsupported | unsupported | mechanical-gap |
| `Diagnostic/HealthChecker.php:559` | `PRAGMA table_info` in `detectTableDrift()` — unguarded | unsupported | unsupported | structural-gap |
| `Diagnostic/HealthChecker.php:561` | PRAGMA result-column shape (`type` / `notnull` / `pk`) | unsupported | unsupported | structural-gap |
| `Diagnostic/HealthChecker.php:612` | SQLite storage classes as expected column types | unsupported | unsupported | structural-gap |
| `Diagnostic/HealthChecker.php:634` | `_data` expected as `TEXT` | unsupported | unsupported | structural-gap |
| `Diagnostic/HealthChecker.php:642` | `VARCHAR(n)` suffix stripping (the #1625 workaround) | unsupported | unsupported | structural-gap |
| `Diagnostic/HealthChecker.php:646` | SQLite affinity normalization map (`:646-654`) | unsupported | unsupported | structural-gap |
| `Diagnostic/DiagnosticCode.php:70` | `FK_ENFORCEMENT_DISABLED` defined as a SQLite condition | unsupported | unsupported | mechanical-gap |
| `Diagnostic/DiagnosticCode.php:114` | `DATABASE_UNREACHABLE` remediation names a "valid SQLite file" | unsupported | unsupported | mechanical-gap |
| `Diagnostic/DiagnosticCode.php:116` | `DATABASE_SCHEMA_DRIFT` remediation: "delete the SQLite database and restart" | unsupported | unsupported | mechanical-gap |
| `Diagnostic/DiagnosticCode.php:122` | remediation instructing `PRAGMA foreign_keys = ON` | unsupported | unsupported | mechanical-gap |

The `HealthChecker.php:340` degradation deserves the emphasis given in §2: it is a **false pass**, not a skip. Column-vs-`_data` storage drift would be reported clean on any non-SQLite driver.

---

### 4.9 Runtime DDL and raw-PDO stores — 14 occurrences, 4 structural

**Owner:** `packages/ai-vector`, `packages/cache`, `packages/api`, `packages/foundation`.

**What the coupling is.** Three stores that bypass `DatabaseInterface` and create their own tables at runtime, plus the kernel/router wiring that binds the concrete SQLite embedding class by name.

**Guard status.** The claimed guards at `SearchRouter.php:56` and `HttpKernel.php:235` are `class_exists(SqliteEmbeddingStorage::class)` — **package**-presence checks that return 501 when `waaseyaa/ai-vector` is absent. They say nothing about the driver. `getNativeConnection()` returns a `\PDO` for `pdo_pgsql` and `pdo_mysql` too, so construction succeeds on a PostgreSQL install and the writes fail later.

**PostgreSQL position.** `INSERT OR REPLACE` is a syntax error in three places (`SqliteEmbeddingStorage.php:34`, `DatabaseBackend.php:115`, and — outside this subsystem — `SqliteArtifactPreparer.php:331`); `ON CONFLICT ... DO UPDATE` is the substitute and the composite primary keys already supply conflict targets. `PRAGMA table_info` in `DatabaseBackend::ensureTable()` is the highest-blast-radius item here.

**MySQL position.** `REPLACE INTO` or `ON DUPLICATE KEY UPDATE` substitute for the upserts. MySQL adds a distinct failure PG does not have: `LIKE ... ESCAPE '\'` at `DatabaseBackend.php:224` is an unterminated string under default `sql_mode`, and the sidecar `TEXT` primary key at `SqliteEmbeddingStorage.php:110` is rejected without a prefix length.

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `ai-vector/src/SqliteEmbeddingStorage.php:16` | raw `\PDO` constructor dependency | works | works | portable |
| `ai-vector/src/SqliteEmbeddingStorage.php:34` | `INSERT OR REPLACE` | unsupported | unsupported | mechanical-gap |
| `ai-vector/src/SqliteEmbeddingStorage.php:110` | runtime DDL with a composite `TEXT` primary key | works | unsupported | mechanical-gap |
| `ai-vector/src/AiVectorServiceProvider.php:50` | unconditional binding of `EmbeddingStorageInterface` to the SQLite implementation | unsupported | unsupported | structural-gap |
| `foundation/src/Http/Router/SearchRouter.php:72` | direct construction of `SqliteEmbeddingStorage` from the DBAL native connection | unsupported | unsupported | structural-gap |
| `foundation/src/Kernel/HttpKernel.php:240` | direct construction of `SqliteEmbeddingStorage` at kernel boot | unsupported | unsupported | structural-gap |
| `foundation/src/Kernel/EventListenerRegistrar.php:148` | concrete `SqliteEmbeddingStorage` in a public method signature | unsupported | unsupported | structural-gap |
| `cache/src/Backend/DatabaseBackend.php:43` | raw `\PDO` constructor dependency | works | works | portable |
| `cache/src/Backend/DatabaseBackend.php:115` | `INSERT OR REPLACE` — every cache write | unsupported | unsupported | mechanical-gap |
| `cache/src/Backend/DatabaseBackend.php:224` | `LIKE ... ESCAPE '\'` tag matching | differs | unsupported | mechanical-gap |
| `cache/src/Backend/DatabaseBackend.php:248` | `PRAGMA table_info` in `ensureTable()` | unsupported | unsupported | mechanical-gap |
| `api/src/Controller/BroadcastStorage.php:39` | `assert($database instanceof DBALDatabase)` | works | works | portable |
| `api/src/Controller/BroadcastStorage.php:41` | `assert($nativeConn instanceof \PDO)` | works | works | portable |
| `api/src/Controller/BroadcastStorage.php:83` | `PDO::lastInsertId()` with no sequence name | differs | works | mechanical-gap |

`DatabaseBackend.php:248` is called from `:133`, `:144`, `:152`, `:192`, and `:274` — every read, write, invalidate, and generation lookup — and its `try`/`catch` at `:250-255` converts the pragma failure into a hard `[S1-DB106]` `RuntimeException` rather than degrading. On a non-SQLite driver every cache operation fails.

`DatabaseBackend.php:224` is classified mechanical rather than structural despite the case-fold difference (PG's `LIKE` is case-sensitive, SQLite's is ASCII-case-insensitive) because cache tags are framework-generated with fixed casing, so no caller relies on the case-insensitive behaviour.

---

### 4.10 Schema emission, type mapping, and the sql-column backend — 35 occurrences, 2 structural

**Owner:** `packages/entity-storage`, `packages/database-legacy`.

**What the coupling is.** Only two structural items, but they are the two that block table creation. `TranslationSchemaHandler.php:353` declares `entity_id varchar(128)` and — unlike the revision tables, which carry no FK — declares a real `FOREIGN KEY` at `:398-405` referencing the base table's `serial` id. `DBALSchema.php:320` maps `'varchar'`/`'string'` to DBAL `'text'`, silently discarding the declared length even though `mapFieldOptions()` at `:353-355` carries `length` through.

**Why they are load-bearing.** `TranslationSchemaHandler.php:353` is the highest-severity item in the whole inventory: PostgreSQL rejects the cross-type foreign key at DDL time ("foreign key constraint cannot be implemented: Key columns are of incompatible types"), which blocks table creation for **every** sql-column translatable entity type, and fixing it means changing the declared id domain and migrating existing rows. `DBALSchema.php:320` is the root cause of three downstream MySQL failures (`SqlSchemaHandler:1009`, `:1106`, `TranslationSchemaHandler:353`), because MySQL rejects a `TEXT` column in a PRIMARY KEY without a prefix length. It is also the same defect surface as **#1625**.

**Everything else here is mechanical or portable, and several reported gaps were refuted.** `'serial'` and `'int'` are *abstract* type keys that DBAL translates per platform, so `SqlSchemaHandler.php:764` emits correct DDL on all three engines despite the SQLite reasoning in its comment. `ColumnSpecMap.php:36` maps decimal to text on every engine, so ordering is lexicographic everywhere and nothing diverges. `TypeMapping`'s blast radius is much smaller than reported: it is **not** on the sql-column DDL path (`SqlColumnSchemaBuilder::buildColumnSpec()` at `:185` uses `ColumnSpecMap` → `DBALSchema`); its only production consumers are the CLI migration emitter and `MakeStorageMigrationHandler:87`.

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `entity-storage/src/Schema/TranslationSchemaHandler.php:353` | `entity_id varchar(128)` with a FOREIGN KEY to a `serial` base id | unsupported | unsupported | structural-gap |
| `database-legacy/src/Schema/DBALSchema.php:320` | `'varchar','string' => 'text'` (declared length silently dropped) | works | unsupported | structural-gap |
| `database-legacy/src/Schema/DBALSchema.php:258` | explicit `SQLitePlatform` refusal for `addPrimaryKey()` | works | works | **guarded** (§5) |
| `database-legacy/src/Schema/DBALSchema.php:339` | `'serial'` → DBAL autoincrement option (the correct portability seam) | works | works | portable |
| `database-legacy/src/Schema/DBALSchema.php:346` | columns default to nullable "to match SQLite behavior" | works | works | portable |
| `entity-storage/src/SqlSchemaHandler.php:564` | ANSI double-quote identifier quoting fallback (ternary at `:563-565`) | works | differs | mechanical-gap |
| `entity-storage/src/SqlSchemaHandler.php:568` | raw `ALTER TABLE %s ADD COLUMN %s INTEGER` (revision_author backfill) | works | works | portable |
| `entity-storage/src/SqlSchemaHandler.php:764` | `'serial'` downgraded to `'int'` for sql-blob translatable types | works | works | portable |
| `entity-storage/src/SqlSchemaHandler.php:1009` | revision-table `entity_id varchar(128)` in a composite PRIMARY KEY | works | unsupported | mechanical-gap |
| `entity-storage/src/SqlSchemaHandler.php:1106` | translation-revision `entity_id varchar(128)` in a composite PRIMARY KEY | works | unsupported | mechanical-gap |
| `entity-storage/src/SqlSchemaHandler.php:1281` | ANSI double-quote identifier quoting fallback (partial UUID index) | works | differs | mechanical-gap |
| `entity-storage/src/SqlSchemaHandler.php:1294` | `CREATE INDEX IF NOT EXISTS` emitted on the MySQL/MariaDB degrade branch | unknown | unsupported | mechanical-gap |
| `entity-storage/src/SqlSchemaHandler.php:1304` | `CREATE UNIQUE INDEX IF NOT EXISTS ... WHERE` (partial unique index) | works | unsupported | **guarded** (§5) |
| `entity-storage/src/SqlSchemaHandler.php:1325` | platform detection defaults to `'sqlite'` for any non-`DBALDatabase` | unknown | unknown | mechanical-gap |
| `entity-storage/src/SqlSchemaHandler.php:1333` | `str_contains($platformClass, 'sqlite')` dispatch by class-name substring | works | works | mechanical-gap |
| `entity-storage/src/Backend/StrictLedgerSchema.php:17` | `INTEGER PRIMARY KEY AUTOINCREMENT` in raw runtime DDL | unsupported | unsupported | mechanical-gap |
| `entity-storage/src/Backend/StrictLedgerSchema.php:25` | `CREATE INDEX IF NOT EXISTS` in raw runtime DDL | works | unsupported | mechanical-gap |
| `entity-storage/src/Backend/StrictLedgerSchema.php:28` | `CREATE UNIQUE INDEX IF NOT EXISTS` (once-only receipt invariant) | works | unsupported | mechanical-gap |
| `entity-storage/src/Backend/TypeMapping.php:63` | `PLATFORM_SQLITE` as a first-class dialect branch; no MySQL arm | unknown | unknown | mechanical-gap |
| `entity-storage/src/Backend/TypeMapping.php:65` | unknown platform falls back to SQLite column types (`// safe fallback`) | unknown | unknown | mechanical-gap |
| `entity-storage/src/Backend/TypeMapping.php:91` | `platformKey()` returns `PLATFORM_SQLITE` for any unrecognised platform | works | unknown | mechanical-gap |
| `entity-storage/src/Backend/TypeMapping.php:114` | `'bool' => 'INTEGER'` in the SQLite arm | unknown | unknown | mechanical-gap |
| `entity-storage/src/Backend/TypeMapping.php:120` | `'decimal' => 'TEXT'` in the SQLite arm vs `NUMERIC(p,s)` on Postgres | unknown | unknown | mechanical-gap |
| `entity-storage/src/Backend/SqlColumnQueryTranslator.php:140` | boolean coerced to integer `0`/`1` before binding | unsupported | works | mechanical-gap |
| `entity-storage/src/Backend/SqlColumnBackend.php:275` | boolean written as integer `0`/`1` | unsupported | works | mechanical-gap |
| `entity-storage/src/Backend/SqlColumnBackend.php:293` | boolean read back via PHP truthiness of the raw column value — the cast **executes** on PG and silently yields `true` for `'f'` | differs | works | mechanical-gap |
| `entity-storage/src/Backend/SqlColumnSchemaBuilder.php:125` | raw `CREATE INDEX` with unquoted identifiers | works | works | portable |
| `entity-storage/src/Backend/SqlColumnSchemaBuilder.php:158` | `CREATE INDEX IF NOT EXISTS` with unquoted identifiers (additive migration) | works | unsupported | mechanical-gap |
| `entity-storage/src/Schema/ColumnSpecMap.php:36` | `'decimal','numeric' => 'text'` in the canonical field-type map | works | works | portable |
| `entity-storage/src/EntitySchemaSync.php:71` | unconditional entry into the schema coordinator | works | works | portable |
| `entity-storage/src/CoordinatedEntitySchemaExecutor.php:30` | `DBALDatabase`-only requirement (S1-DB107) | works | works | portable |
| `entity-storage/src/CoordinatedEntitySchemaExecutor.php:41` | `SchemaMutationCoordinator::execute()` at boot | works | works | portable |
| `entity-storage/src/CoordinatedEntitySchemaExecutor.php:67` | `PRAGMA query_only` read-back, to restore the prior value (added by #2452) | unsupported | unsupported | **guarded** (§5) |
| `entity-storage/src/CoordinatedEntitySchemaExecutor.php:69` | `PRAGMA query_only = ON` — the read-only planning probe (added by #2452) | unsupported | unsupported | **guarded** (§5) |
| `entity-storage/src/CoordinatedEntitySchemaExecutor.php:83` | `PRAGMA query_only = OFF` in the `finally` block (added by #2452) | unsupported | unsupported | **guarded** (§5) |

Two defects worth separating out. `SqlSchemaHandler.php:1294` sits inside a **real** platform guard (`if ($platform === 'mysql' || $platform === 'mariadb')` at `:1285`) but the degrade branch itself emits `CREATE INDEX IF NOT EXISTS`, which MySQL 8 rejects — a guard that emits invalid SQL on its own target engine is not a guard against the failure. And `:1325` fails open: any `DatabaseInterface` implementation that is not `DBALDatabase` is silently classified SQLite for DDL-dialect purposes, and `:1333`'s `'unknown'` tail falls through to the PG/SQLite-only partial-index syntax at `:1304`. The same fail-open default appears at `AttachmentSchema.php:595` (§4.12) and `TypeMapping.php:65`/`:91`.

`SqlColumnBackend.php:293` is the silent-wrong-answer item flagged in §2.

---

### 4.11 Package migration DDL — 45 occurrences, 2 structural

**Owner:** `packages/audit`, `foundation`, `entity-storage`, `field`, `queue`, `oidc`, `api`, `auth`, `notification`, `media`, `cache`, `migration`.

**What the coupling is.** Twenty-five `AUTOINCREMENT` tokens, seven `DATETIME` type names, one `BLOB`, two `REAL` timestamp columns, two reserved-word column names, and one two-argument `MAX(a, b)`. Almost all of it is mechanical.

**Why two are structural.** `audit/migrations/2026_08_12_000003_audit_runtime_schema.php:15` and `audit/migrations/2026_08_15_000007_audit_checkpoint_succession.php:14` are not surrogate keys. The keyword substitutes fine on both engines, but the ordering property does not: lines `:42-45` of the same audit migration compute checkpoint segments from `MAX(id)`/`COUNT(*)` over `audit_event`, and `AuditCheckpointHasher` chains on that ordering. PG and MySQL both allow a transaction holding id=5 to commit *after* one holding id=6, so a checkpoint computed from `MAX(id)` can straddle a hole. SQLite's S1 single-writer model makes that impossible. The succession table additionally carries a self-referential FOREIGN KEY (`rolled_back_sequence` → `sequence`) at `:31`, making ordering part of the integrity model.

**PostgreSQL position.** `AUTOINCREMENT` → `GENERATED BY DEFAULT AS IDENTITY` or `SERIAL`; `DATETIME` → `TIMESTAMP`/`TIMESTAMPTZ` (PG has no `DATETIME` type at all); `BLOB` → `bytea`; `MAX(a,b)` → `GREATEST(a,b)`; `REAL` is 4-byte single precision on PG, which destroys sub-second resolution for a Unix-epoch float and needs `DOUBLE PRECISION`.

**MySQL position.** `AUTOINCREMENT` → `AUTO_INCREMENT` (a different spelling, not the same keyword); `DATETIME` and `REAL` are native and work; `BLOB` works but caps at 65,535 bytes, which serialized cache payloads can exceed. MySQL-only failures PG does not have: reserved-word columns `key` (`foundation/migrations/2026_08_12_000001_rate_limit_window_schema.php:18`), `signed` (§4.4), and `cursor` (`foundation/migrations/2026_08_15_000002_application_master_rekey.php:100`); `TEXT NOT NULL DEFAULT ''` (MySQL rejects a literal DEFAULT on TEXT/BLOB); `CREATE INDEX IF NOT EXISTS` (`MigrationIdMapSchema.php:121`).

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `audit/migrations/2026_08_12_000003_audit_runtime_schema.php:15` | `AUTOINCREMENT` on the `audit_event` hash-chain ordering key | unsupported | works | structural-gap |
| `audit/migrations/2026_08_15_000007_audit_checkpoint_succession.php:14` | `AUTOINCREMENT` on the succession sequence key (self-referential FK at `:31`) | unsupported | unsupported | structural-gap |
| `audit/migrations/2026_08_12_000003_audit_runtime_schema.php:18` | `ALTER TABLE ADD COLUMN` with interpolated type fragments | works | works | portable |
| `audit/migrations/2026_08_12_000003_audit_runtime_schema.php:28` | `AUTOINCREMENT` + `DATETIME` (`privileged_read_ledger`) | unsupported | works | mechanical-gap |
| `audit/migrations/2026_08_12_000003_audit_runtime_schema.php:32` | `AUTOINCREMENT` + `DATETIME` (`audit_retention_policy`) | unsupported | works | mechanical-gap |
| `audit/migrations/2026_08_12_000003_audit_runtime_schema.php:35` | `AUTOINCREMENT` + `DATETIME` (`audit_checkpoint`) | unsupported | works | mechanical-gap |
| `audit/migrations/2026_08_12_000003_audit_runtime_schema.php:37` | `ALTER TABLE audit_checkpoint ADD COLUMN pruned INTEGER NOT NULL DEFAULT 0` | works | works | portable |
| `audit/migrations/2026_08_12_000004_strict_audit_ledger_schema.php:12` | `AUTOINCREMENT` | unsupported | unsupported | mechanical-gap |
| `audit/migrations/2026_08_12_000005_approval_event_schema.php:12` | `AUTOINCREMENT` | unsupported | unsupported | mechanical-gap |
| `audit/migrations/2026_05_25_000002_create_audit_retention_policy_table.php:27` | `AUTOINCREMENT` (duplicates the `000003:32` DDL) | unsupported | unsupported | mechanical-gap |
| `audit/migrations/2026_05_25_000002_create_audit_retention_policy_table.php:32` | `DATETIME` type name | unsupported | works | mechanical-gap |
| `foundation/migrations/2026_08_15_000002_application_master_rekey.php:97` | `AUTOINCREMENT` (`failure_id`) | unsupported | unsupported | mechanical-gap |
| `foundation/migrations/2026_08_15_000002_application_master_rekey.php:100` | unquoted reserved-word identifier `cursor` | works | unsupported | mechanical-gap |
| `foundation/migrations/2026_08_15_000002_application_master_rekey.php:144` | `AUTOINCREMENT` (`event_id`) | unsupported | unsupported | mechanical-gap |
| `foundation/migrations/2026_08_12_000001_rate_limit_window_schema.php:18` | unquoted reserved-word identifier `key` | works | unsupported | mechanical-gap |
| `foundation/migrations/2026_08_12_000001_rate_limit_window_schema.php:19` | unquoted identifier `count` | works | works | portable |
| `entity-storage/migrations/2026_08_12_000001_entity_mutation_authority.php:16` | raw `CREATE TABLE` bypassing `DBALSchema` (4×`VARCHAR(191)` composite PK) | works | differs | mechanical-gap |
| `entity-storage/migrations/2026_08_12_000001_entity_mutation_authority.php:21` | `aggregate_version INTEGER NOT NULL CHECK (aggregate_version > 0)` | works | works | portable |
| `entity-storage/migrations/2026_08_12_000001_entity_mutation_authority.php:22` | `length()` in a CHECK constraint | works | differs | portable |
| `field/migrations/2026_05_25_000003_create_classification_label_definition_table.php:27` | `AUTOINCREMENT` | unsupported | unsupported | mechanical-gap |
| `field/migrations/2026_05_25_000004_create_retention_policy_table.php:27` | `AUTOINCREMENT` | unsupported | unsupported | mechanical-gap |
| `field/migrations/2026_05_25_000004_create_retention_policy_table.php:35` | `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | unsupported | works | mechanical-gap |
| `field/migrations/2026_05_25_000005_index_classification_label.php:58` | `CREATE INDEX IF NOT EXISTS` inside a bare `catch (\Throwable)` — error tolerance, not a platform guard (§5.1) | works | unsupported | mechanical-gap |
| `queue/migrations/2026_04_24_000001_create_queue_tables.php:15` | `AUTOINCREMENT` (`waaseyaa_queue_jobs`) | unsupported | unsupported | mechanical-gap |
| `queue/migrations/2026_04_24_000001_create_queue_tables.php:32` | `AUTOINCREMENT` (`waaseyaa_failed_jobs`) | unsupported | unsupported | mechanical-gap |
| `queue/src/Migration/CreateQueueTables.php:18` | `AUTOINCREMENT` — byte-for-byte duplicate of the migration, +2 line offset | unsupported | unsupported | mechanical-gap |
| `queue/src/Migration/CreateQueueTables.php:35` | `AUTOINCREMENT` (duplicate failed-jobs table) | unsupported | unsupported | mechanical-gap |
| `oidc/migrations/2026_04_26_000001_oidc_client_schema.php:25` | `AUTOINCREMENT` | unsupported | unsupported | mechanical-gap |
| `oidc/migrations/2026_04_26_000001_oidc_client_schema.php:37` | raw type fragments interpolated into `ALTER TABLE ADD COLUMN` | works | works | portable |
| `oidc/migrations/2026_04_26_000001_oidc_client_schema.php:39` | boolean-as-INTEGER column | works | works | portable |
| `oidc/migrations/2026_04_26_000001_oidc_client_schema.php:55` | `ALTER TABLE ADD COLUMN` with a `$sqliteFragment` parameter (`:48`) | works | differs | mechanical-gap |
| `oidc/migrations/2026_05_25_000004_oidc_user_consent_schema.php:25` | `AUTOINCREMENT` | unsupported | unsupported | mechanical-gap |
| `oidc/migrations/2026_08_15_000007_oidc_signing_key_lifecycle.php:88` | `MAX(a, b)` as a two-argument scalar function | unsupported | unsupported | mechanical-gap |
| `oidc/migrations/2026_07_15_000005_oidc_secret_storage.php:18` | `ALTER TABLE ADD COLUMN token_lookup CHAR(64) NULL` | works | works | portable |
| `api/migrations/2026_08_12_000001_broadcast_schema.php:16` | `AUTOINCREMENT` (`_broadcast_log`) | unsupported | unsupported | mechanical-gap |
| `api/migrations/2026_08_12_000001_broadcast_schema.php:20` | `created_at REAL` | differs | works | mechanical-gap |
| `api/migrations/2026_08_12_000001_broadcast_schema.php:32` | `created_at REAL` / `expires_at REAL` (`_broadcast_retained`) | differs | works | mechanical-gap |
| `auth/migrations/2026_08_12_000001_auth_runtime_schema.php:17` | `AUTOINCREMENT` (`auth_tokens`) | unsupported | unsupported | mechanical-gap |
| `notification/migrations/2026_04_24_000001_create_notification_tables.php:18` | `AUTOINCREMENT` | unsupported | unsupported | mechanical-gap |
| `media/migrations/2026_05_25_000005_create_media_version_table.php:30` | `AUTOINCREMENT` (DIR-005 versioned blob) | unsupported | unsupported | mechanical-gap |
| `media/migrations/2026_05_25_000005_create_media_version_table.php:50` | `DESC` ordering in an index | works | works | portable |
| `cache/migrations/2026_08_12_000001_cache_items_schema.php:20` | `data BLOB NOT NULL` | unsupported | works | mechanical-gap |
| `cache/migrations/2026_08_15_000002_cache_generation.php:24` | singleton-row CHECK pattern | works | works | portable |
| `migration/src/Schema/MigrationIdMapSchema.php:105` | `CREATE TABLE IF NOT EXISTS` renderer (docblock claims cross-driver portability) | works | works | portable |
| `migration/src/Schema/MigrationIdMapSchema.php:121` | `CREATE [UNIQUE] INDEX IF NOT EXISTS` renderer | works | unsupported | mechanical-gap |

`MigrationIdMapSchema` is the one schema descriptor in the corpus written cross-driver on purpose — its docblock at `:86-87` claims "portable SQL — works on SQLite, MySQL, Postgres without driver branching". The claim holds for PostgreSQL and is falsified for MySQL by exactly one line, `:121`.

The `queue` duplication (`queue/migrations/2026_04_24_000001_create_queue_tables.php:15`/`:32` vs `queue/src/Migration/CreateQueueTables.php:18`/`:35`) is byte-for-byte at a +2 line offset. Both copies must be repaired together.

---

### 4.12 Remaining production couplings — 17 occurrences, 1 structural

**Owner:** `packages/audit`, `attachment`, `scheduler`, `auth`, `oidc`, `frankenphp`, `listing`.

The single structural item is `AppendOnlyAuditDatabase.php:215`, and it is a security control rather than a schema concern. The comment at `:208-210` states that matching string literals **first** is what keeps a quote inside a literal from being mistaken for an identifier delimiter — an invariant that holds for SQLite's `''` escaping but not for PG's `E'...\'...'` / `$$…$$` syntax or MySQL's default `'...\'...'`. A backslash-escaped quote terminates the match early and desynchronises the alternation, so following text is scanned as if it were code. This guard is the sole enforcement layer for the append-only invariant (#1648 is the prior incident in this exact code).

| file:line | construct | PG | MySQL | disposition |
|---|---|---|---|---|
| `audit/src/Storage/AppendOnlyAuditDatabase.php:215` | single-quoted string-literal alternative modelling only SQLite `''` escaping | differs | differs | structural-gap |
| `audit/src/Storage/AppendOnlyAuditDatabase.php:217` | double-quote identifier delimiter handling | works | works | portable |
| `audit/src/Storage/AppendOnlyAuditDatabase.php:218` | backtick identifier delimiter handling | works | works | portable |
| `audit/src/Storage/AppendOnlyAuditDatabase.php:219` | bracket identifier delimiter handling | works | works | portable |
| `audit/src/Writer/DatabaseStrictPrivilegedReadLedger.php:114` | SQLite-specific contention retry gate | differs | differs | mechanical-gap |
| `audit/src/Writer/DatabaseStrictPrivilegedReadLedger.php:126` | `SQLITE_BUSY` / `SQLITE_LOCKED` error-string matching | differs | differs | mechanical-gap |
| `attachment/src/Schema/AttachmentSchema.php:191` | transactional-DDL platform branch in recovery guidance | works | works | **guarded** (§5) |
| `attachment/src/Schema/AttachmentSchema.php:475` | `CREATE INDEX IF NOT EXISTS` vs MySQL `information_schema.statistics` probe | works | works | **guarded** (§5) |
| `attachment/src/Schema/AttachmentSchema.php:562` | partial `UNIQUE INDEX ... WHERE is_active = 1` with a MySQL early return at `:549` | works | unsupported | **guarded** (§5) |
| `attachment/src/Schema/AttachmentSchema.php:595` | platform detection defaults to SQLite for non-DBAL databases | unsupported | unsupported | mechanical-gap |
| `attachment/src/AttachmentActiveInvariant.php:43` | boolean-as-integer allow-list `[true, 1, '1']` | works | works | portable |
| `scheduler/src/Lease/DatabaseLease.php:164` | `julianday('now')` as the lease authority's clock | unsupported | unsupported | mechanical-gap |
| `auth/src/DatabaseRateLimiter.php:45` | `INSERT ... ON CONFLICT (col) DO UPDATE ... excluded.` | works | unsupported | mechanical-gap |
| `oidc/src/Consent/ConsentRepository.php:68` | `INSERT OR IGNORE` | unsupported | unsupported | mechanical-gap |
| `frankenphp/src/Binary/Installer.php:203` | generated Windows `php.ini` enables `pdo_sqlite` only | unsupported | unsupported | mechanical-gap |
| `frankenphp/src/Binary/Installer.php:204` | generated Windows `php.ini` enables `sqlite3` only | unsupported | unsupported | mechanical-gap |
| `listing/src/ListingResolver.php:716` | string-normalised scalar equality for driver-emitted types | differs | works | mechanical-gap |

Three items deserve a note rather than a table row. `DatabaseLease.php:164` is mechanical but carries a trap: the obvious PG/MySQL substitutes (`now()`, `CURRENT_TIMESTAMP`, `NOW()`) are frozen at transaction start or truncated to seconds, and either would silently break the monotonicity check at `:166` and every acquire/renew/expiry decision built on it — the correct substitutes are `clock_timestamp()` and `NOW(3)`. `auth/src/DatabaseRateLimiter.php:45` is the mirror image of the usual pattern: it is already PostgreSQL-native and MySQL is the engine that fails, which is why it must not be filed under "already remediated". And `ListingResolver.php:716` is latent rather than present-tense — the failure requires a native `BOOLEAN` column, and the framework declares booleans as `INTEGER` everywhere the verifiers checked.

---

## 5. Guarded by design — 15 occurrences

These are the pattern the rest of the surface should be measured against. In each case an explicit **driver or platform** check — not a schema-shape, table-presence, path-shape, or wrapper-class check — causes a non-SQLite driver to skip the SQLite construct or take a documented alternative path. They are the existence proof that the codebase can express engine divergence when it chooses to; the 280 unguarded gaps are a choice not to, not an impossibility.

| file:line | construct | guard | PG | MySQL |
|---|---|---|---|---|
| `database-legacy/src/DBALDatabase.php:50` | `SQLitePlatform` instanceof guard on the schema-assets filter | this line — closure returns `true` immediately off SQLite (`:45-68`) | works | works |
| `database-legacy/src/DBALDatabase.php:55` | `sqlite_` internal-object name-prefix exclusion | `DBALDatabase.php:50` | works | works |
| `database-legacy/src/DBALDatabase.php:87` | `pragma_table_list` (SQLite >= 3.37) | `DBALDatabase.php:50`; sole callsite is `:59`, after the early return | unsupported | unsupported |
| `database-legacy/src/DBALDatabase.php:104` | `sqlite_master` fallback scan (SQLite < 3.37) | `DBALDatabase.php:50`, via the catch at `:98-100` | unsupported | unsupported |
| `database-legacy/src/SqliteDriverMiddleware.php:33` | PRAGMA contract re-applied on every physical connect | `DBALDatabase.php:132-139` — the middleware is installed in the same expression that sets `'driver' => 'pdo_sqlite'`, and no other code path installs it | unsupported | unsupported |
| `database-legacy/src/Schema/DBALSchema.php:258` | explicit `SQLitePlatform` **refusal** for `addPrimaryKey()` | this line (`:253-274`) | works | works |
| `entity-storage/src/SqlSchemaHandler.php:1304` | partial `CREATE UNIQUE INDEX IF NOT EXISTS ... WHERE` | `SqlSchemaHandler.php:1285` — mysql/mariadb early return at `:1285-1301` | works | unsupported |
| `cli/src/Handler/AddTranslationsMigrationGenerator.php:172` | platform branch in the generated migration | this line — explicit `mysql` arm at `:173-177`, `postgresql` arm at `:178-188` | works | works |
| `cli/src/Handler/AddTranslationsMigrationGenerator.php:201` | partial unique index in the generated migration | `AddTranslationsMigrationGenerator.php:193-198` — MySQL substitutes a full `UNIQUE (uuid, langcode)` index, documented at `:192` | works | unsupported |
| `attachment/src/Schema/AttachmentSchema.php:191` | transactional-DDL platform branch in recovery guidance | this line (`:190-200`), backed by `detectDatabasePlatform()` at `:591-605` | works | works |
| `attachment/src/Schema/AttachmentSchema.php:475` | `CREATE INDEX IF NOT EXISTS` | `AttachmentSchema.php:450` platform detect, `:451-459` unknown-skip, `:469` MySQL `mysqlIndexExists()` probe | works | works |
| `attachment/src/Schema/AttachmentSchema.php:562` | partial `UNIQUE INDEX ... WHERE is_active = 1` | `AttachmentSchema.php:549` — `if ($platform === 'mysql' \|\| $platform === 'mariadb') { …warning…; return; }`, plus a try/catch at `:570-581` | works | unsupported |
| `entity-storage/src/CoordinatedEntitySchemaExecutor.php:67` | `PRAGMA query_only` read-back | `if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) { return true; }` at `:63-65` | unsupported | unsupported |
| `entity-storage/src/CoordinatedEntitySchemaExecutor.php:69` | `PRAGMA query_only = ON` | same guard, `:63-65` | unsupported | unsupported |
| `entity-storage/src/CoordinatedEntitySchemaExecutor.php:83` | `PRAGMA query_only = OFF` | same guard, `:63-65` | unsupported | unsupported |

**`packages/attachment/src/Schema/AttachmentSchema.php` is the reference implementation.** It detects the platform from the DBAL platform class, skips with a warning on unknown platforms, takes a probe-based path on MySQL, emits the partial index only where it is supported, names the fallback in the warning it emits, and still wraps the whole thing in a degrade-to-warning catch. Its one defect is the fail-open default at `:595`. Every unguarded gap in §4 could have been written this way.

**The three `PRAGMA query_only` guards (added by #2452) are the strongest guard form in the
codebase** — an explicit `instanceof SQLitePlatform` check that returns before the PRAGMA is reached. They are
also the clearest example of a guard that is correct and still consequential: off SQLite, `requiresMutation()`
returns `true` unconditionally, so #2452's steady-state read-only optimisation does not apply. A ported install
would take a write transaction on every boot again — the exact contention profile #2446 was filed for. The
guard is not a defect; the fact that the repair is SQLite-shaped is a portability finding, and it is recorded
here rather than in §4 because no code would need to change for the guard itself to remain correct.

### 5.1 Error-tolerated, not guarded

These three were classified `guarded` in the first draft. They are not guards, and they are counted as gaps in
§4. In each case the SQLite-only statement **is issued**, the engine **does** reject it, and a `catch` absorbs
the resulting exception. That changes the *failure mode* — silent degradation instead of a stack trace — but it
does not change the portability status, and calling it a guard overstates how much of the surface is already
handled.

| file:line | construct | tolerance mechanism | consequence off SQLite |
|---|---|---|---|
| `foundation/src/Diagnostic/HealthChecker.php:340` | `PRAGMA table_info` in `columnExists()` | `catch (\Throwable)` at `:345`, logs at info, falls through to `return false` | **False pass.** Column-vs-`_data` storage drift is reported *clean*, not *unknown* |
| `foundation/src/Diagnostic/HealthChecker.php:518` | `PRAGMA foreign_keys` | `catch (\Throwable) { return null; }` at `:519-521` | The FK-enforcement check silently disappears from the health report |
| `field/migrations/2026_05_25_000005_index_classification_label.php:58` | `CREATE INDEX IF NOT EXISTS` | bare `catch (\Throwable)` at `:62-65` with an explanatory comment | On MySQL the index is never created; retention jobs full-scan, with no error and no warning |

The distinction matters for #2447 specifically: an assessment that greps for `catch` and counts those sites as
"already handled" would under-count the surface by exactly these three, and would inherit a health checker that
reports success on an engine it cannot actually inspect.

**Two further constructs were reported as guards and are not**, and are likewise counted as gaps in §4:
`SqliteTopology.php:77`/`:98` (`if ($fileBacked)` is memory-vs-file, not engine), and the
`instanceof DBALDatabase` ternaries at `SqlStorageDriver.php:542`, `:601`,
`SqlColumnTranslationHydrator.php:233`, `SqlSchemaHandler.php:563`, `:1279` (wrapper-class, not driver —
`DBALDatabase` accepts any Doctrine `Connection`).

---

## 6. Comment-only mentions — 28 occurrences

Prose about SQLite rather than executable coupling. They are not gaps and are not counted in the 358. They matter as **intent signal**: they show where the design was reasoned about in SQLite terms even when the emitted code is portable, and several of them are the clearest available statement of *why* an adjacent line looks the way it does.

| file:line | what the prose says |
|---|---|
| `entity-storage/src/SqlEntityQuery.php:855` | why `coerceConditionValue()`'s table is tuned to SQLite json_extract return typing |
| `entity-storage/src/SqlEntityQuery.php:1328` | pins the CAST path to SQLite json_extract semantics (executable line is `:1336`) |
| `entity-storage/src/SqlEntityQuery.php:1386` | deferred-transaction snapshot reliance (executable line `:1389` is driver-neutral PHP) |
| `entity-storage/src/SqlSchemaHandler.php:556` | FTS5 virtual/shadow-table incompatibility drives the targeted-ALTER strategy (#1653) |
| `entity-storage/src/Backend/SqlColumnQueryTranslator.php:130` | "SQLite type affinity comparisons commute correctly" |
| `entity-storage/src/Backend/SqlColumnBackend.php:28` | class-docblock storage contract written in SQLite terms (bool INTEGER 0/1, datetime TEXT, decimal TEXT) |
| `entity-storage/src/Schema/RevisionTableBuilder.php:264` | documents that `'serial'` maps to SERIAL/BIGSERIAL on Postgres — i.e. argues the abstraction *is* platform-aware |
| `database-legacy/src/SelectInterface.php:24` | `json_extract` named as the canonical `condition()` expression example |
| `database-legacy/src/SelectInterface.php:48` | `json_extract` named in the `whereRaw()` contract |
| `database-legacy/src/SelectInterface.php:69` | `json_extract` named in the `orderByRaw()` contract |
| `foundation/src/Migration/SchemaMutationCoordinator.php:12` | class contract written for SQLite writer semantics |
| `foundation/src/Migration/Migrator.php:38` | "the repository acquires SQLite writer authority before reading ledger state" — satisfied at this commit by `claimWriterPosition()` (`MigrationRepository.php:145`, #2450) |
| `foundation/src/Diagnostic/HealthChecker.php:116` | "SQLite foreign-key enforcement (skipped for non-SQLite drivers)" — guard is at `:117-120` |
| `cli/src/Handler/AddTranslationsMigrationGenerator.php:189` | PK **widening** deferred to `SqlSchemaHandler::sync()` on next boot |
| `cli/src/Handler/AddTranslationsMigrationGenerator.php:279` | PK **narrowing** deferred to `SqlSchemaHandler::sync()` on next boot |
| `cli/src/Handler/SearchReindexHandler.php:50` | clear-then-batch strategy because "FTS5 has no INSERT OR REPLACE" |
| `search/src/Fts5/Fts5SearchIndexer.php:79` | "FTS5 does not support INSERT OR REPLACE — delete first" |
| `search/src/Fts5/Fts5SearchIndexer.php:179` | "FTS5 tokenizers cannot be altered in place" — why `removeAll()` is the upgrade boundary |
| `oidc/migrations/2026_04_26_000001_oidc_client_schema.php:45` | empty `down()`: "Additive SQLite schema: dropping columns is version-dependent; leave no-op" |
| `attachment/src/Schema/AttachmentSchema.php:50` | docblock column listing `id INTEGER PK AUTOINCREMENT` (real table is built via `SchemaInterface` with `'serial'`) |
| `graphql/src/Resolver/EntityResolver.php:61` | `_data` blob performance note referencing the json_extract fallback |
| `graphql/src/Resolver/EntityResolver.php:83` | why the R15 query-field allowlist exists (the sink is in entity-storage) |
| `graphql/src/Resolver/EntityResolver.php:463` | `assertQueryableFields()` docblock, same transitive dependency |
| `api/src/JsonApiController.php:83` | audit R2 WP1 injection fix, describing the entity-storage json_extract sink |
| `api/src/JsonApiController.php:1256` | `validateQueryFields()` docblock referencing `SqlEntityQuery`'s raw interpolation |
| `listing/src/ListingResolver.php:388` | `buildQueryPlan()` bundle-subtable demotion rule, described in json_extract terms |
| `entity/src/Attribute/Field.php:26` | `stored:` semantics documented by reference to json_extract routing |
| `field/src/FieldStorage.php:19` | enum docblock referencing json_extract resolution |

The `oidc` empty-`down()` convention at `2026_04_26_000001:45` recurs across the audit, field, and oidc migration corpora. It is an intent signal worth naming: **migration reversibility was abandoned because of SQLite's `ALTER TABLE` restrictions**, on engines that do not have those restrictions.

---

## 7. Rejected claims and verifier disagreements

Keeping this visible is what makes the counts in §3 trustworthy. The refutations below — 28 comment-only (§7.1), 4 double-counted callees (§7.2), and 1 out of scope (§7.3) — are excluded from the 358.

### 7.1 Refuted as comment-only — 28

Enumerated in §6.

### 7.2 Refuted as double-counting a callee — 4

| claimed | why refuted |
|---|---|
| `foundation/src/Migration/MigrationRepository.php:117` | `$this->ensureSchemaAuthorityManifestColumns();` — a PHP call with no SQL. The `PRAGMA table_info` it reaches is counted once, at `:298`. |
| `foundation/src/Migration/SchemaMutationCoordinator.php:41` | `$this->repository->acquireSchemaAuthority();` — call site. Its couplings are counted at `MigrationRepository.php:145`/`:156`/`:165`/`:298`. |
| `foundation/src/Migration/SchemaMutationCoordinator.php:48` | call site of `LogicalSchemaFingerprint::capture()`. Counted at `LogicalSchemaFingerprint.php:16`. Confirms, though, that the SQLite catalogue scan sits on the commit path of every coordinated transition. |
| `cli/src/Handler/DbInitHandler.php:82` | `installOrUpgradeLedger()` call site. Counted at `MigrationRepository.php:57`/`:61` (CREATE TABLE) and the upgrade path it calls at `:261`/`:267`/`:272`/`:290`. |

### 7.3 Refuted as out of scope — 1

| claimed | why refuted |
|---|---|
| `entity-storage/src/Testing/EntityMutationAuthoritySchema.php:18` | Excluded by the `Testing/` scope rule, declares itself a test fixture at `:9`, **and** contains no SQLite-only construct — plain `VARCHAR`/`INTEGER` with a composite PK. Worth a separate note for someone else: it sits under `src/` and is therefore production-autoloadable via PSR-4, which is a packaging concern unrelated to this audit. |

### 7.4 Claims confirmed present but refuted as couplings

These 63 occurrences carry the `portable` disposition in §3 and §4. The recurring refutations, grouped:

- **DBAL-wrapper assertions are not driver assertions.** `AbstractKernel.php:431`, `CoordinatedEntitySchemaExecutor.php:30`, `BroadcastStorage.php:39` all assert `DBALDatabase` or `\PDO`, neither of which carries engine information. This is precisely the inverse of #2447's rejected premise and cuts the same way: a DBAL type in a signature proves nothing either direction.
- **PostgreSQL accepts more than the finders assumed.** The `NULL` column constraint in `ADD COLUMN` (`MigrationRepository.php:267`/`:272`/`:304`) is documented PG grammar; `CHAR(64)` ignores trailing blanks in `bpchar` comparison and the values are exactly 64 chars (`oidc/migrations/2026_07_15_000005_oidc_secret_storage.php:18`); `INTEGER` + `CHECK` is valid (`entity-storage/migrations/2026_08_12_000001_entity_mutation_authority.php:21`); descending indexes are real on MySQL 8, the stated target (`media/migrations/2026_05_25_000005_create_media_version_table.php:50`).
- **The abstraction layer is doing its job in places.** `DBALSchema.php:339` (`'serial'` → per-platform identity DDL) is the correct portability seam, and it is exactly what the raw-DDL sites bypass. `SqlSchemaHandler.php:568`, `:764` and `ColumnSpecMap.php:36` emit identically on all three engines.
- **PHP-side guards and value objects are not SQL.** `JsonFieldName.php:38` (injection allowlist), `ResolvedField.php:47` (a boolean flag with three callsites), `AttachmentActiveInvariant.php:43` (an INTEGER column, so `'t'`/`'f'` cannot arise), `AppendOnlyAuditDatabase.php:217`/`:218`/`:219` (recognising PG's and MySQL's own identifier delimiters is engine-*readiness*, not lock-in).
- **One coupling runs backwards.** `DBALConsistentReadTransaction.php:29` and `SqlEntityQuery.php:1539`: SQLite maps every level at or above READ COMMITTED to `PRAGMA read_uncommitted = 1`, so SQLite is the degraded engine here. PG 14 and InnoDB both give a real MVCC snapshot. Porting *improves* the contextual Protected-entity read rather than breaking it.

### 7.5 Verifier disagreements carried forward

The two verification passes disagreed on eight rows. Rather than smooth these over, the carried verdict and the dissent are both recorded. All eight remain in the 358; only their disposition is contested. Two of those rows group four related sites each, so the table covers fourteen sites.

| file:line | carried | dissent | basis for the carried verdict |
|---|---|---|---|
| `SqliteTopology.php:75`, `:76`, `:96`, `:97` | mechanical-gap | structural-gap | The PRAGMA *setters* have a direct substitute or are removable; the fail-closed property that makes boot impossible is the readback assertion, already counted as structural at `:87`–`:89` and `:103`–`:105`. Counting both as structural double-attributes one failure. |
| `SqliteDriverMiddleware.php:33` | guarded | structural-gap | The installation-site proof is stronger than the assertion of absence: `DBALDatabase.php:132-139` installs the middleware in the same expression as `'driver' => 'pdo_sqlite'`, and a grep found no other installer. |
| `entity-storage/migrations/2026_08_15_000004_configuration_manifest_replay.php:60` | mechanical-gap | portable | The dissent is correct that `INTEGER` + `CHECK` is valid PG. The carried verdict adds a finding the dissent missed: `SIGNED` is a MySQL reserved word, so the unquoted column name is a syntax error there regardless of the CHECK. |
| `entity-storage/migrations/2026_08_19_000005_configuration_genesis_marker.php:48` | mechanical-gap | portable | **Unresolved, but the documented grammar favours the dissent.** One verifier read PostgreSQL's `ALTER TABLE ADD COLUMN` as accepting an inline `CHECK`; the other read it as requiring a separate `ADD CONSTRAINT`. The PostgreSQL 14+ `ALTER TABLE` synopsis gives `ADD [ COLUMN ] [ IF NOT EXISTS ] column_name data_type [ COLLATE collation ] [ column_constraint [ ... ] ]`, and `column_constraint` includes `CHECK ( expression )` (<https://www.postgresql.org/docs/14/sql-altertable.html>, cross-referenced with the `column_constraint` production in <https://www.postgresql.org/docs/14/sql-createtable.html>). On that reading the dissent is right and the line is PG-portable. It is **still carried as the more severe verdict**. Two readings of the same published grammar reached opposite conclusions, so this document will not promote the row to `works` on a citation alone. `works` here remains a static assessment (§1), not an executed result. Resolve by running the statement against a real PostgreSQL server, not by re-reading. |
| `bin/check-s1-sqlite-contract:371` | mechanical-gap | structural-gap | Adding PG/MySQL extensions alongside is a workflow edit; the structural governance blockers are `:179` and `:204`. |
| `DbInitHandler.php:216`, `:275`, `:299`, `:326` | structural-gap | mechanical-gap | These are the path-not-DSN identity model, the same construct both passes independently call structural at `DatabaseBootstrapper.php:89`/`:213`. Classifying the same construct differently by file would make the counts incoherent. |
| `audit/migrations/2026_08_12_000003_audit_runtime_schema.php:15` | structural-gap | mechanical-gap | The dissent traced consumers the first pass did not: `:42-45` computes checkpoint segments from `MAX(id)` and `AuditCheckpointHasher` chains on that ordering, so commit-order guarantees are load-bearing and a keyword swap does not preserve them. |
| `audit/migrations/2026_08_15_000007_audit_checkpoint_succession.php:14` | structural-gap | mechanical-gap | Same reasoning plus the self-referential FOREIGN KEY at `:31`. |

Net effect if every dissent were carried instead: structural would move from 135 to somewhere between 129 and
141. The headline conclusions do not depend on which way these eight land.

**These remain disagreements.** None is resolved here. Where an authoritative grammar reference exists it is
cited above, but a citation is not a resolution: `works` in this document means *assessed compatible from
published documentation* (§1), and none of these eight has been executed against a real server (§9). They are
listed so that a reader can see exactly where the method reached its limit, rather than inferring a false
uniformity from the tables.

---

## 8. Sequencing considerations for #2447

Bounded and non-authorizing. This section says what an assessment would have to **budget for**, not what should be built. #2447 and #2448 remain blocked; nothing here changes that, and nothing here is a work plan.

**A. Governance precedes code, and this is not negotiable by ordering the work differently.** `bin/check-s1-sqlite-contract:204` requires the machine contract to declare `postgresql` among the refused alternate databases, and `:253` hardcodes that refusal in the checker as well as the JSON. `:179` pins `authority.engine = sqlite`; `:192` pins the search projection engine. The gate is fail-closed and runs in pre-push and CI. An assessment must budget a charter-level contract amendment as a **prerequisite**, and must account for the fact that `bin/check-support-contract:203-204` propagates `ext-pdo_sqlite`/`ext-sqlite3` into every published consumer manifest.

**B. There is no environment to assess in.** Zero postgres/mysql/mariadb services exist in any of the 21 workflows; of `ci.yml`'s 19 `setup-php` steps, 15 request SQLite extensions, 3 specify none, and 1 requests `xml` only; `bin/check-s1-sqlite-contract:371-377` requires the SQLite extensions and pins the SQLite library to `>=3.40.0 <4.0.0`. Standing up a service container is the smallest unit of work that converts any claim in this document from static to verified. Until then the coverage limits in §9 apply in full.

**C. #2446 has landed; treat this inventory's line numbers as commit-scoped.** #2446 — the p0 concurrent-boot
failure — is closed at this commit by #2450 and #2452, and their rewrites moved much of §4.1. The forward
lesson is the one in headline finding 8: both repairs *added* SQLite-specific code, correctly and for good
reasons. An assessment should expect this surface to grow under ordinary maintenance, and should re-resolve
every reference against the then-current tree rather than treating any pinned inventory as durable.

**D. #1588 forces a decision before any `database-legacy` work.** 34 occurrences, 17 structural, in a package slated for retirement. The connection factory (`createSqlite()`, `pdo_sqlite`, `path`, `memory`), the topology contract, the parameter-type inferrer, and the DBAL schema type map are all in it, and all four are on the critical path. #2447 must decide whether to assess `database-legacy` as it stands or to treat its replacement as a precondition. This audit deliberately takes no position; it records that deferring the decision means the inventory's largest structural cluster has no owner.

**E. Two independent tracks, and they are not substitutes for each other.** PostgreSQL and MySQL diverge on 64 of 358 occurrences, in both directions (§3). A PostgreSQL assessment budgets the `json_extract` surface, boolean binding, `DATETIME`, `BLOB`, and the trigger rewrite. A MySQL assessment budgets `CREATE INDEX IF NOT EXISTS`, partial indexes, `DBALSchema.php:320`'s `VARCHAR(n)` → `TEXT` collapse, double-quote quoting, `CAST ... AS TEXT`, `DROP INDEX` without `ON`, and reserved-word column names. **Neither budget informs the other**, which is #2448's stated position and the reason every table in this document keeps the columns apart.

**F. Assess the verifier before assessing what it verifies.** `HealthChecker`'s drift comparison is defined in SQLite storage classes (`:612`, `:634`, `:646`) and its `VARCHAR(n)` normalisation at `:642` is already the #1625 workaround. On any non-SQLite driver it either throws (`:559`, unguarded) or returns a false pass (`:340`). An assessment that uses `schema:check` as evidence is using an instrument calibrated to the engine it is trying to leave.

**G. Costs cluster, and the cluster order is not the same as the count order.** By structural-gap count: migration authority + schema compiler (29), connection topology (20), deploy artifact pipeline (20), integrity triggers (19), FTS5 (17). But three of those have qualitatively different costs. The deploy pipeline (§4.3) has no production caller inside this repo, so its cost lands downstream and cannot be sized from here. FTS5 (§4.5) is a rewrite with a hard correctness constraint — the `remove_diacritics 0` + `tokenchars` tokenizer configuration is load-bearing for Anishinaabemowin text and any substitute must reproduce the same token boundaries. The 19 integrity triggers are the cheapest to *write* and the most dangerous to get wrong, because silently dropping one removes an invariant without failing a build.

**H. Recorded evidence must be re-baselined, and that cost is separate from the code.** Every `diff_hash`, every `LogicalSchemaFingerprint` value, and every S1 recorded roster is a SQLite artefact. A compiler change turns every historical `migrate --verify` row into `plan_mismatch` (`VerifyRunner.php:140`). This is not a code-change cost; it is an operational migration for every existing install.

---

## 9. Coverage limits

What this audit could not determine, and therefore what a real service is still required for.

**It is a static inventory. Every engine position is a documented-behaviour claim, not an observed one.** No PostgreSQL or MySQL instance was contacted. No statement in this document was executed against either engine. Where a verifier wrote "PG rejects this at plan time" or "MySQL 8 does not support IF NOT EXISTS on CREATE INDEX", that is a reading of the engines' documented grammar, not a reproduction. §7.5 records one case (`entity-storage/migrations/2026_08_19_000005_configuration_genesis_marker.php:48`) where two careful readers of PostgreSQL's `ALTER TABLE` grammar reached opposite conclusions — a direct demonstration of the limit.

Specifically undetermined:

- **Runtime behaviour under load.** Nothing about lock contention, connection pooling, plan stability, or the actual throughput consequence of the index-defeating cross-type joins at `SqlStorageDriver.php:565` and `SqlColumnTranslationHydrator.php:88`.
- **Concurrency semantics.** #2446's failure mode has since been reproduced and repaired upstream of this audit (#2450, #2452), but *this document* still reasons about SQLite snapshot-upgrade behaviour rather than observing it, and it has observed nothing at all about how the equivalent contention behaves on PostgreSQL's MVCC or MySQL's InnoDB; the commit-ordering hazard for `audit_event`'s hash chain (§4.11) is a documented property of MVCC identity columns, not a measured one. Whether a checkpoint computed from `MAX(id)` actually straddles a hole under concurrent load is unknown.
- **Collation and text semantics.** PostgreSQL's locale-dependent collation versus SQLite's byte ordering, the practical effect on `ORDER BY` for every text column, and the case-sensitivity change at `DatabaseBackend.php:224` are all unmeasured. The claim that no caller relies on SQLite's case-insensitive `LIKE` is an inference from how cache tags are generated, not a proof.
- **Type-round-trip fidelity.** The `SqlColumnBackend.php:293` boolean read-back failure is derived from PDO_PGSQL's documented `'t'`/`'f'` representation. The actual behaviour depends on driver configuration (`ATTR_STRINGIFY_FETCHES`, emulated prepares) that cannot be read from source.
- **Whether the schema even creates.** The highest-severity item, `TranslationSchemaHandler.php:353`'s cross-type FOREIGN KEY, is predicted to fail at DDL time on both engines. That prediction has not been executed. Neither has the MySQL `TEXT`-in-PRIMARY-KEY prediction that follows from `DBALSchema.php:320`.
- **Blast radius outside this repository.** `SqliteArtifactPreparer` and `SqliteArtifactInstaller` have no in-repo production caller; their consumers are downstream deploy tooling that this audit cannot see. `SubscriptionRecipe.php:125` emits SQLite-only DDL into scaffolded consumer repositories that likewise cannot be enumerated from here.
- **Whether the 280 unguarded gaps are the complete set.** Discovery was broad and the `AUTOINCREMENT` cross-check reconciles exactly (§1), but a static sweep cannot prove absence. Constructs that are portable in syntax and divergent only in behaviour — implicit casts, NULL ordering, aggregate return types, integer division, string concatenation with NULL — are the class most likely to have been missed, precisely because grep does not find them.

- **How long these references stay correct.** This inventory is pinned to
  `3aca84d79b8afe0b60272ffc374a5f9299eb98c2`. Line numbers are evidence about that one commit, not durable
  identifiers, and ordinary merges invalidate them quickly — the #2446 repairs alone moved much of §4.1 and
  added four couplings. Re-resolve before relying on any reference here.

**The single thing that would change the epistemic status of this document is a postgres service and a mysql service in CI, running the existing suites.** Everything above is a map of where to look. None of it is evidence that the map is right.
