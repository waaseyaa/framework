# Authoring a Migration Source Reader

**Audience:** package authors shipping reusable source readers (e.g.
`waaseyaa-migrate-source-wordpress`, `waaseyaa-migrate-source-drupal`).
**Substrate:** `waaseyaa/migration` (M-002).
**Spec:** [`docs/specs/migration-platform.md`](../specs/migration-platform.md).
**Charter:** `docs/specs/stability-charter.md` §5.8.

> The contracts described here are `@api` stable surface. Breaking changes
> follow the charter's deprecation cycle.

---

## 1. What is a source reader?

A **source reader** is a Composer package that ships one or more
implementations of `Waaseyaa\Migration\Plugin\SourcePluginInterface`. Each
implementation streams records from an external system (a file format, an API,
a legacy CMS database) and emits them as `SourceRecord` instances for the
migration runner to process.

Source readers are **separate packages** from the framework. The framework
ships the substrate (`waaseyaa/migration`); readers ship the format
knowledge.

---

## 2. Package skeleton

A typical source-reader package looks like:

```
waaseyaa-migrate-source-xml/
├── composer.json
├── src/
│   ├── XmlLineSource.php          # SourcePluginInterface implementation
│   └── XmlMigrationProvider.php   # ServiceProvider implements HasMigrationsInterface
└── tests/
    └── XmlLineSourceConformanceTest.php
```

### 2.1 `composer.json`

```json
{
    "name": "myvendor/waaseyaa-migrate-source-xml",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.5",
        "waaseyaa/migration": "^0.2"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "MyVendor\\WaaseyaaMigrateSourceXml\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "MyVendor\\WaaseyaaMigrateSourceXml\\Testing\\": "testing/",
            "MyVendor\\WaaseyaaMigrateSourceXml\\Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true
    },
    "extra": {
        "waaseyaa": {
            "providers": [
                "MyVendor\\WaaseyaaMigrateSourceXml\\XmlMigrationProvider"
            ]
        }
    }
}
```

Notes:

- Pin to a **major** of `waaseyaa/migration` (`^0.2`, later `^1.0`). The
  stability charter guarantees no breaking changes within a major after v1.0;
  v0.x retains rolling-deprecation freedom.
- The `extra.waaseyaa.providers` array surfaces your provider class to the
  framework's `PackageManifestCompiler`.
- The `Testing/` directory is autoload-dev only — production consumers
  installing with `--no-dev` will not Reflection-load these classes (and
  thus will not attempt to resolve `PHPUnit\Framework\TestCase`).

---

## 3. The `SourcePluginInterface` contract

```php
namespace Waaseyaa\Migration\Plugin;

interface SourcePluginInterface
{
    public function id(): string;
    public function stability(): string;             // 'stable' | 'experimental'
    public function records(): iterable;             // yields SourceRecord
    public function sourceIdFor(SourceRecord $record): SourceId;
    public function count(): ?int;                   // null when unknown
}
```

### 3.1 `id()`

Returns a stable identifier for this source plugin. Format: `/^[a-z][a-z0-9_]*$/`.
Reserved ids (process-plugin namespace) live in
`Waaseyaa\Migration\Plugin\ReservedPluginIds`. Source-plugin ids are not
centrally reserved, but use a `<vendor>_<format>` convention (`xml_line`,
`wordpress_wxr`, `csv`).

The `id()` value is **stable across calls** — the conformance suite will
fail your plugin if it ever changes.

### 3.2 `stability()`

Return `'stable'` once the plugin's API + behaviour are frozen. Returning
`'experimental'` is permitted during early development; the framework will
emit a one-time `migration.deprecation` log entry the first time the plugin
runs in any given process. Consumers can opt in to experimental plugins by
ignoring the channel.

### 3.3 `records()` — the streaming contract

`records()` MUST be a generator (or other lazy iterable). Implementations MUST
NOT eager-load the full source dataset into memory. The conformance suite
imports a 50 MB fixture and asserts the process memory stays under
`MigrationDefinition::$memoryBudgetBytes` (default 256 MB).

```php
public function records(): iterable
{
    $handle = \fopen($this->path, 'r');
    try {
        while (($line = \fgets($handle)) !== false) {
            yield new SourceRecord(
                sourceType: $this->id(),
                fields: $this->parseLine($line),
            );
        }
    } finally {
        \fclose($handle);
    }
}
```

### 3.4 `sourceIdFor()` — deterministic stable IDs

```php
public function sourceIdFor(SourceRecord $record): SourceId
{
    return new SourceId(
        sourceType: $record->sourceType,
        keys: ['id' => (string) $record->field('id')],
    );
}
```

`SourceId::hash()` is a sha256 of the canonical JSON form of
`(sourceType, sorted-key keys)`. Hashing is deterministic across PHP minors,
locales, and machines (FR-027).

**Determinism is the cardinal contract.** Two calls to `sourceIdFor()` with
the same record MUST return `SourceId`s that hash identically. Otherwise:

- `import:run` cannot detect idempotent re-runs (every re-import looks like
  a fresh record).
- `import:resume` may skip records or re-process them.
- `import:rollback` cannot find rows to unwind.

If your source has no natural primary key, **invent one and document it** —
typically a positional offset or content hash of the entire record. Never
return random or time-derived data.

### 3.5 `count()`

Return the total number of records when cheap to compute (e.g. CSV line
count). Return `null` when unknown (e.g. streaming HTTP source). The runner
uses `count()` for progress bars; `null` simply hides the ETA.

`count()` MUST NOT consume the stream — it is allowed to be a no-op that
returns `null`.

---

## 4. Registration

Source plugins are instantiated directly inside a `MigrationDefinition`.
There is no global plugin registry; a provider contributes the complete
definition through `HasMigrationsInterface`.

```php
namespace MyVendor\WaaseyaaMigrateSourceXml;

use Waaseyaa\Foundation\ServiceProvider;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;

final class XmlMigrationProvider extends ServiceProvider implements HasMigrationsInterface
{
    public function migrations(): array
    {
        return [XmlToContentMigration::create()];
    }
}
```

---

## 5. Running the conformance suite

`Waaseyaa\Migration\Testing\SourceConformanceTestCase` ships under
`packages/migration/testing/` (autoload-dev). Subclass it and implement the
three factory methods:

```php
namespace MyVendor\WaaseyaaMigrateSourceXml\Tests;

use Waaseyaa\Migration\Testing\SourceConformanceTestCase;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use MyVendor\WaaseyaaMigrateSourceXml\XmlLineSource;

final class XmlLineSourceConformanceTest extends SourceConformanceTestCase
{
    protected function createSource(): SourcePluginInterface
    {
        $fixturePath = __DIR__ . '/Fixtures/small.xml';
        return new XmlLineSource($fixturePath, keyFields: ['id']);
    }

    protected function createLargeSource(): SourcePluginInterface
    {
        // For the 50 MB streaming-memory test.
        $fixturePath = __DIR__ . '/Fixtures/large.xml';
        return new XmlLineSource($fixturePath, keyFields: ['id']);
    }

    protected function expectedRecordCount(): ?int
    {
        // Match the createSource() fixture row count.
        return 12;
    }
}
```

The eight conformance gates assert:

1. `id()` matches the format regex and is stable across calls.
2. `stability()` returns `'stable'` or `'experimental'`.
3. `records()` is a generator (not an array).
4. Iterating `records()` twice yields the same `SourceId`s in the same order.
5. `count()` either matches `expectedRecordCount()` or returns `null`.
6. Every yielded `SourceRecord` has a non-empty `sourceType` matching
   `id()`.
7. `sourceIdFor()` is deterministic — invoked twice on the same record, it
   returns equal hashes.
8. Streaming a 50 MB fixture stays within the 256 MB memory budget.

A passing conformance suite is the **release gate** for a source-reader
package — it is the contract every Waaseyaa consumer relies on.

---

## 6. Packaging + naming conventions

| Aspect | Convention |
|---|---|
| Composer name | `<vendor>/waaseyaa-migrate-source-<format>` |
| Plugin id | `<vendor>_<format>` or just `<format>` if uncontested |
| Version pinning | `waaseyaa/migration: "^<MAJOR>.<MINOR>"` (track minor; framework's stability charter scopes breaking changes to majors after v1.0) |
| Testing namespace | `<Vendor>\\Waaseyaa<Name>\\Testing\\` under `autoload-dev` only |
| `extra.waaseyaa.providers` | List your provider FQCN(s) |

---

## 7. Operator-facing surface

Operators of consuming apps will use your reader via:

```
bin/waaseyaa import:run my_xml_import
bin/waaseyaa import:status my_xml_import
bin/waaseyaa import:resume my_xml_import
bin/waaseyaa import:rollback my_xml_import
bin/waaseyaa import:reset my_xml_import
```

If your reader can fail mid-stream (network, partial files), document the
recovery path in your package README:

1. Inspect `bin/waaseyaa import:status <id>` for the error message + last
   committed cursor.
2. Fix the underlying problem (e.g. restart the source server, repair the
   file).
3. Run `bin/waaseyaa import:resume <id>` — the runner picks up at the cursor.
4. If the lock file is stale (rare, only on hard process kill), the operator
   removes it manually: `rm storage/migration-locks/<id>.lock`.

---

## 8. Worked example — `XmlLineSource`

A complete, copy-pastable example. `<record>` elements stream one-per-line so
the reader stays memory-bounded regardless of file size.

```php
<?php

declare(strict_types=1);

namespace MyVendor\WaaseyaaMigrateSourceXml;

use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;
use Waaseyaa\Migration\Exception\SourceReadException;

/**
 * @api
 *
 * Streams one-record-per-line XML files.
 *
 * @spec FR-001
 */
final class XmlLineSource implements SourcePluginInterface
{
    /**
     * @param non-empty-string $path
     * @param non-empty-list<non-empty-string> $keyFields Columns that compose the SourceId.
     */
    public function __construct(
        private readonly string $path,
        private readonly array $keyFields = ['id'],
    ) {
        if (!\is_readable($this->path)) {
            throw new \InvalidArgumentException("XML path not readable: {$this->path}");
        }
    }

    public function id(): string
    {
        return 'xml_line';
    }

    public function stability(): string
    {
        return 'stable';
    }

    public function records(): iterable
    {
        $handle = \fopen($this->path, 'r');
        if ($handle === false) {
            throw new SourceReadException("Could not open {$this->path}");
        }

        try {
            while (($line = \fgets($handle)) !== false) {
                $line = \trim($line);
                if ($line === '') {
                    continue;
                }
                yield new SourceRecord(
                    sourceType: $this->id(),
                    fields: $this->parseLine($line),
                );
            }
        } finally {
            \fclose($handle);
        }
    }

    public function sourceIdFor(SourceRecord $record): SourceId
    {
        $keys = [];
        foreach ($this->keyFields as $field) {
            $keys[$field] = (string) $record->field($field, '');
        }
        return new SourceId(sourceType: $record->sourceType, keys: $keys);
    }

    public function count(): ?int
    {
        // Cheap line-count; null is also acceptable here.
        return null;
    }

    /** @return array<string, mixed> */
    private function parseLine(string $line): array
    {
        $xml = \simplexml_load_string($line);
        if ($xml === false) {
            throw new SourceReadException("Malformed line: {$line}");
        }
        return \json_decode((string) \json_encode($xml), associative: true) ?? [];
    }
}
```

---

## 9. Cross-references

- Spec: [`docs/specs/migration-platform.md`](../specs/migration-platform.md).
- Process plugin authoring: [`migration-process-plugins.md`](./migration-process-plugins.md).
- First-cut cookbook: [`docs/cookbook/migration-first-cut.md`](../cookbook/migration-first-cut.md).
- Charter §5.8: [`docs/specs/stability-charter.md`](../specs/stability-charter.md).
