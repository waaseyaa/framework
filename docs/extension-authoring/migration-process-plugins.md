# Authoring a Migration Process Plugin

**Audience:** package authors and application developers writing custom
process plugins for the migration platform.
**Substrate:** `waaseyaa/migration` (M-002).
**Spec:** [`docs/specs/migration-platform.md`](../specs/migration-platform.md).
**Charter:** `docs/specs/stability-charter.md` §5.8.

> The contracts described here are `@api` stable surface. Breaking changes
> follow the charter's deprecation cycle.

---

## 1. What is a process plugin?

A **process plugin** is a class implementing
`Waaseyaa\Migration\Plugin\ProcessPluginInterface` that transforms one source
value into one destination value during a migration run. Each entry in a
`MigrationDefinition::$process` map names a destination field; the value names
the plugin (or chain of plugins) that produces the destination value.

The framework ships seven built-in process plugins
(`PassThroughProcessor`, `HtmlSanitizeProcessor`, `LookupProcessor`,
`ConcatProcessor`, `TypeCoerceProcessor`, `DefaultValueProcessor`,
`PartitionedLookupProcessor`). Custom
process plugins extend this set — examples: `WordPressShortcodeStrip`,
`MarkdownToHtml`, `SlugifyTitle`, `ResolveAuthorByEmail`.

---

## 2. The `ProcessPluginInterface` contract

```php
namespace Waaseyaa\Migration\Plugin;

interface ProcessPluginInterface
{
    public function id(): string;
    public function stability(): string;             // 'stable' | 'experimental'
    public function transform(mixed $value, ProcessContext $context): mixed;
}
```

### 2.1 `id()` and the reserved-id namespace

`id()` returns a stable identifier for the plugin. Format:
`/^[a-z][a-z0-9_]*$/`.

The framework **reserves** seven ids — see
`Waaseyaa\Migration\Plugin\ReservedPluginIds`:

| Reserved id | Concrete class |
|---|---|
| `pass_through` | `PassThroughProcessor` |
| `html_sanitize` | `HtmlSanitizeProcessor` |
| `lookup` | `LookupProcessor` |
| `concat` | `ConcatProcessor` |
| `type_coerce` | `TypeCoerceProcessor` |
| `default_value` | `DefaultValueProcessor` |
| `partitioned_lookup` | `PartitionedLookupProcessor` |

App-defined process plugins MUST use a non-reserved id. Recommended naming
convention: `<vendor>_<purpose>` (e.g. `wordpress_shortcode_strip`,
`acme_uppercase_first_word`). The reservation policy mirrors ADR 010's
backend-id namespace policy.

Plugin registration fails fast with `MigrationPluginCollisionException` if
two plugins claim the same id.

### 2.2 `stability()`

Return `'stable'` once the plugin's API and behaviour are frozen. Returning
`'experimental'` is permitted during development; the framework emits a
one-time `migration.deprecation` log entry the first time the plugin runs in
a given process (channel: `Waaseyaa\Migration\Log\Channels::MIGRATION_DEPRECATION`).

### 2.3 `transform()`

Takes one input value, returns one output value. The method receives a
`ProcessContext` with:

- `$context->sourceRecord` — the full `SourceRecord` (so a plugin can read
  sibling fields).
- `$context->migration` — the `MigrationDefinition` (so a plugin can read
  configuration declared at the manifest level).
- `$context->lookup($migrationId, SourceId $sourceId): ?WriteResult` — a
  callable that consults `MigrationIdMap` for cross-migration references.

`transform()` may throw `Waaseyaa\Migration\Exception\ProcessException`. The
runner catches it, records the failure against the error-rate budget, and
continues (unless the halt threshold trips).

---

## 3. Chains — array order is execution order

A `MigrationDefinition::$process` value can be a single string, a single
`ProcessPluginInterface`, or **an array** of strings and processors. Arrays
are chains:

```php
'value_int' => [
    new PassThroughProcessor('signup_year'),   // pulls $sourceRecord['signup_year']
    new TypeCoerceProcessor('int'),            // coerces the previous output to int
],
```

The runner pipes the output of each plugin into the input of the next.
String entries are shorthand for `new PassThroughProcessor($string)` and are
typically only useful as the head of a chain (or as a single-element process
value).

---

## 4. `ProcessContext` reference

```php
final readonly class ProcessContext
{
    public function __construct(
        public SourceRecord $sourceRecord,
        public MigrationDefinition $migration,
        public \Closure $lookup,    // fn(string $migrationId, SourceId $id): ?WriteResult
    ) {}
}
```

### 4.1 Cross-migration references

The canonical use case for `$context->lookup` is resolving a foreign key from
a previously-imported migration:

```php
public function transform(mixed $value, ProcessContext $context): mixed
{
    $authorEmail = (string) $context->sourceRecord->field('author_email');
    $authorId = new SourceId(sourceType: 'users', keys: ['email' => $authorEmail]);

    $writeResult = ($context->lookup)('users_csv_to_widgets', $authorId);
    if ($writeResult === null) {
        throw new ProcessException("Unknown author email: {$authorEmail}");
    }

    return $writeResult->destinationUuid;
}
```

This is also what the built-in `LookupProcessor` does — prefer it for simple
key-lookups, write your own only when the source-key derivation is custom.

---

## 5. Stability and the deprecation channel

When `stability()` returns `'experimental'`, the framework emits one log
entry per plugin id per process the first time the plugin runs:

```
migration.deprecation: experimental plugin "acme_uppercase_first_word" used by migration "csv_to_widgets"
```

Consumers can monitor this channel to surface adoption of unstable contracts.
Promote your plugin to `'stable'` once you've committed to the surface.

---

## 6. Testing

There is no `ProcessConformanceTestCase` — process plugins are simple enough
that unit tests + the `MigrationPluginCollisionException` registration check
cover the contract. A typical unit test:

```php
final class UppercaseFirstWordProcessorTest extends TestCase
{
    #[Test]
    public function transforms_first_word(): void
    {
        $processor = new UppercaseFirstWordProcessor('title');

        $sourceRecord = new SourceRecord(
            sourceType: 'csv',
            fields: ['title' => 'hello world'],
        );
        $context = new ProcessContext(
            sourceRecord: $sourceRecord,
            migration: $this->createMigrationDefinitionStub(),
            lookup: static fn () => null,
        );

        $result = $processor->transform('hello world', $context);

        $this->assertSame('Hello world', $result);
    }

    #[Test]
    public function id_is_stable(): void
    {
        $processor = new UppercaseFirstWordProcessor('title');
        $this->assertSame('acme_uppercase_first_word', $processor->id());
        $this->assertSame($processor->id(), $processor->id());
    }
}
```

For chain semantics, an integration test wires the plugin into a real
`MigrationDefinition` and a `MigrationRunner` against an in-memory SQLite +
`InMemoryEntityStorage`.

---

## 7. Worked example — `UppercaseFirstWordProcessor`

A complete, copy-pastable custom process plugin.

```php
<?php

declare(strict_types=1);

namespace MyVendor\Waaseyaa\Process;

use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\ProcessContext;

/**
 * Uppercases the first word of the input string.
 *
 * Example usage in a MigrationDefinition:
 *
 *     'title' => [
 *         new PassThroughProcessor('title'),
 *         new UppercaseFirstWordProcessor(),
 *     ],
 *
 * @api
 */
final class UppercaseFirstWordProcessor implements ProcessPluginInterface
{
    public function id(): string
    {
        return 'acme_uppercase_first_word';
    }

    public function stability(): string
    {
        return 'stable';
    }

    public function transform(mixed $value, ProcessContext $context): mixed
    {
        if (!\is_string($value) || $value === '') {
            return $value;
        }

        $words = \explode(' ', $value, 2);
        $words[0] = \ucfirst($words[0]);

        return \implode(' ', $words);
    }
}
```

Composition inside a migration definition:

```php
namespace MyVendor\Waaseyaa;

use MyVendor\Waaseyaa\Process\UppercaseFirstWordProcessor;

/**
 * @api
 */
$definition = new MigrationDefinition(
    // ...
    process: ['title' => [new UppercaseFirstWordProcessor()]],
);
```

---

## 8. Cross-references

- Spec: [`docs/specs/migration-platform.md`](../specs/migration-platform.md).
- Source-reader authoring: [`migration-source-readers.md`](./migration-source-readers.md).
- First-cut cookbook: [`docs/cookbook/migration-first-cut.md`](../cookbook/migration-first-cut.md).
- Charter §5.8: [`docs/specs/stability-charter.md`](../specs/stability-charter.md).
