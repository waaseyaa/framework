# Cookbook: Writing Your First Migration

**Audience:** application authors importing content into Waaseyaa for the
first time.
**Substrate:** `waaseyaa/migration` (M-002).
**Spec:** [`docs/specs/migration-platform.md`](../specs/migration-platform.md).

This walk-through ports a small CSV file into Waaseyaa entities, exercises
resume + rollback, and produces an audit trail you can inspect with the
status command. By the end you'll have run all six `import:*` commands.

The full reference scenario lives in
`packages/migration/tests/Integration/UsersCsvToWidgetsMigrationTest.php` —
this guide is the human-readable companion.

---

## Step 1 — Install `waaseyaa/migration`

Your application's `composer.json`:

```bash
composer require waaseyaa/migration:^0.2
```

Then run schema migrations to create the `migration_id_map` and
`migration_run_state` tables:

```bash
bin/waaseyaa migrate
```

Expected output:

```
✔  Applied 2026_05_13_000001_create_migration_id_map
✔  Applied 2026_05_13_000002_create_migration_run_state
2 migrations applied.
```

---

## Step 2 — Prepare your source CSV

Create `storage/imports/users.csv`:

```csv
id,username,bio_html,signup_year
1,alice,"<p>Hi, I'm <strong>Alice</strong>.</p>",2024
2,bob,"<p>Bob here. <script>alert(1)</script></p>",2025
3,carol,"<p>Carol the carpenter.</p>",2023
```

Note row 2's `<script>` tag — we'll use `HtmlSanitizeProcessor` to strip it.

---

## Step 3 — Define your destination entity type

The migration platform writes through the entity-storage coordinator, so you
need a destination entity type registered. For this cookbook, assume your app
already registers a `migration_test_widget` entity with fields `title`,
`body`, `value_int`, and `tags`.

If you're starting from scratch, see
[`docs/specs/entity-system.md`](../specs/entity-system.md) for entity
registration. The relevant fields:

| Field | Type | Notes |
|---|---|---|
| `title` | string | required |
| `body` | string (rich) | required |
| `value_int` | int | optional |
| `tags` | json | optional, defaults to `[]` |

---

## Step 4 — Write the `MigrationDefinition`

Create `src/Migration/UsersCsvToWidgetsMigration.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Migration;

use Psr\Container\ContainerInterface;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\Destination\EntityDestinationFactory;
use Waaseyaa\Migration\Plugin\Process\DefaultValueProcessor;
use Waaseyaa\Migration\Plugin\Process\HtmlSanitizeProcessor;
use Waaseyaa\Migration\Plugin\Process\PassThroughProcessor;
use Waaseyaa\Migration\Plugin\Process\TypeCoerceProcessor;
use Waaseyaa\Migration\Plugin\Source\CsvSource;

final class UsersCsvToWidgetsMigration
{
    public static function create(ContainerInterface $container): MigrationDefinition
    {
        $factory = $container->get(EntityDestinationFactory::class);

        return new MigrationDefinition(
            id: 'users_csv_to_widgets',
            source: new CsvSource(
                path: __DIR__ . '/../../storage/imports/users.csv',
                keyFields: ['id'],
            ),
            process: [
                'title'     => 'username',
                'body'      => new HtmlSanitizeProcessor('bio_html'),
                'value_int' => [
                    new PassThroughProcessor('signup_year'),
                    new TypeCoerceProcessor('int'),
                ],
                'tags'      => new DefaultValueProcessor([]),
            ],
            destination: $factory->forEntityType('migration_test_widget'),
            description: 'Import users.csv into migration_test_widget entities',
        );
    }
}
```

Three things worth noting:

1. `'title' => 'username'` is shorthand for `new PassThroughProcessor('username')` — just pull the CSV column straight through.
2. `'body' => new HtmlSanitizeProcessor('bio_html')` strips XSS attempts.
3. `'value_int' => [...]` is a **chain** — first pass through `signup_year`, then coerce to `int`.

---

## Step 5 — Register the migration via a provider

Create `src/Migration/MyAppMigrationProvider.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Migration;

use Psr\Container\ContainerInterface;
use Waaseyaa\Foundation\ServiceProvider;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\MigrationDefinition;

final class MyAppMigrationProvider extends ServiceProvider implements HasMigrationsInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    /** @return array<MigrationDefinition> */
    public function migrations(): array
    {
        return [
            UsersCsvToWidgetsMigration::create($this->container),
        ];
    }
}
```

Wire it into your app's `composer.json`:

```json
{
    "extra": {
        "waaseyaa": {
            "providers": [
                "MyApp\\Migration\\MyAppMigrationProvider"
            ]
        }
    }
}
```

Rebuild the manifest:

```bash
bin/waaseyaa optimize:manifest
```

---

## Step 6 — Run the migration

```bash
bin/waaseyaa import:run users_csv_to_widgets
```

Expected output:

```
Running migration "users_csv_to_widgets"...
Source: csv (3 records)
Destination: migration_test_widget

✔  Wrote 3 records in 0.04s
   Errors: 0  (warn threshold: 0.01, halt threshold: 0.10)
   Run id: 01HXYZ...
```

---

## Step 7 — Inspect status

```bash
bin/waaseyaa import:status users_csv_to_widgets
```

Expected output:

```
Migration: users_csv_to_widgets
  Description:    Import users.csv into migration_test_widget entities
  Source:         csv
  Destination:    migration_test_widget
  Last run:       2026-05-13T17:42:11Z  (success)
  Records written: 3 of 3
  Errors:          0
  Resume cursor:   <complete>
```

`import:status` is read-only and does **not** acquire the per-migration lock —
safe to run any time, even during an active migration.

---

## Step 8 — Simulate an interruption + resume

To exercise resume, kill the process mid-stream (Ctrl-C on a long-running
migration) or set `errorRateHalt` low so an injected error trips abort.

After interruption:

```bash
bin/waaseyaa import:status users_csv_to_widgets
```

Output (truncated):

```
Records written: 2 of 3
Resume cursor:   <after source-id sha256:9c1f...>
Last error:      MigrationAbortedException: error-rate halt threshold tripped
```

Resume:

```bash
bin/waaseyaa import:resume users_csv_to_widgets
```

The runner re-streams the source, fast-forwards past the cursor, and resumes
writes. Idempotent — already-written records (per `migration_id_map`) are
skipped.

---

## Step 9 — Rollback to start fresh

To unwind the migration entirely:

```bash
bin/waaseyaa import:rollback users_csv_to_widgets
```

Expected output:

```
Rolling back migration "users_csv_to_widgets"...
✔  Removed 3 destination entities
✔  Removed 3 id-map rows
```

The runner walks `migration_id_map` in reverse insertion order. For each row
it calls `DestinationPluginInterface::rollback()` on the destination, then
removes the id-map row on success. A failed rollback halts the walk with the
remaining id-map rows intact — re-run after fixing the underlying problem.

---

## Step 10 — Reset (advanced)

`import:reset` drops `migration_id_map` rows **without** calling rollback. Use
it only when destination state has already been wiped externally (e.g.
`DROP TABLE migration_test_widget`):

```bash
bin/waaseyaa import:reset users_csv_to_widgets
```

Most operators never need this command — it exists for recovery after manual
intervention has already left the id-map and destination out of sync.

---

## Where to next

- Bulk-run every registered migration in dependency order:
  ```bash
  bin/waaseyaa import:run-all
  ```
  The runner walks the dependency graph and acquires the lock **per
  migration**, not globally.

- Ship a reusable source reader: see
  [Authoring a Migration Source Reader](../extension-authoring/migration-source-readers.md).

- Write a custom process plugin: see
  [Authoring a Migration Process Plugin](../extension-authoring/migration-process-plugins.md).

- Read the full spec:
  [`docs/specs/migration-platform.md`](../specs/migration-platform.md).

- Stale lock recovery (rare): `rm storage/migration-locks/<id>.lock`.

---

## Troubleshooting

| Symptom | Likely cause | Action |
|---|---|---|
| `MigrationConcurrencyException` | Another process holds the lock. | Wait or `rm storage/migration-locks/<id>.lock` if stale. |
| `SourceReadException` | Source file unreadable / malformed. | Inspect the source; `import:resume` after fix. |
| `DestinationWriteException` | Access denied, validation, or schema mismatch. | Inspect logs (`entity.lifecycle` channel); fix; `import:resume`. |
| `MigrationAbortedException` | Error-rate halt threshold tripped. | Raise threshold or fix data; `import:resume`. |
| `MigrationCycleException` | Two migrations declare each other as dependencies. | Fix `$dependencies` declarations. |
| `MigrationPluginCollisionException` | Two plugins claim the same id. | Rename one. |
