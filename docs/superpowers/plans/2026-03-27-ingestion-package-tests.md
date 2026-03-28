# Ingestion Package Test Coverage (#579) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #579 by adding dedicated unit tests for Foundation ingestion DTOs/enum/exception and filling edge-case gaps in existing PayloadValidator and IngestionLogger tests.

**Architecture:** Four new focused test files for classes currently only tested indirectly, plus targeted additions to two existing test files for untested code paths. All tests follow existing project conventions: `#[Test]` + `#[CoversClass]` attributes, `Waaseyaa\Foundation\Tests\Unit\Ingestion` namespace, temp directories for filesystem tests.

**Tech Stack:** PHPUnit 10.5, PHP 8.4+, no mocks (final classes — use real instances)

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `packages/foundation/tests/Unit/Ingestion/EnvelopeTest.php` | Isolated DTO construction and serialization |
| Create | `packages/foundation/tests/Unit/Ingestion/IngestionErrorTest.php` | Value object construction, serialization, defaults |
| Create | `packages/foundation/tests/Unit/Ingestion/IngestionErrorCodeTest.php` | Enum completeness, prefix grouping, backed values |
| Create | `packages/foundation/tests/Unit/Ingestion/InvalidEnvelopeExceptionTest.php` | Exception hierarchy, error access, serialization |
| Modify | `packages/foundation/tests/Unit/Ingestion/PayloadValidatorTest.php` | Corrupt schema, type validation for number/boolean/array/object |
| Modify | `packages/foundation/tests/Unit/Ingestion/IngestionLoggerTest.php` | Prune edge cases: unparseable dates, all-expired |

---

### Task 1: EnvelopeTest — Isolated DTO Tests

**Files:**
- Create: `packages/foundation/tests/Unit/Ingestion/EnvelopeTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Ingestion\Envelope;

#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $envelope = new Envelope(
            source: 'manual',
            type: 'core.note',
            payload: ['title' => 'Hello'],
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
            tenantId: 'tenant-1',
            metadata: ['priority' => 'high'],
        );

        $this->assertSame('manual', $envelope->source);
        $this->assertSame('core.note', $envelope->type);
        $this->assertSame(['title' => 'Hello'], $envelope->payload);
        $this->assertSame('2026-03-08T17:00:00+00:00', $envelope->timestamp);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $envelope->traceId);
        $this->assertSame('tenant-1', $envelope->tenantId);
        $this->assertSame(['priority' => 'high'], $envelope->metadata);
    }

    #[Test]
    public function constructWithOptionalFieldsOmitted(): void
    {
        $envelope = new Envelope(
            source: 'api',
            type: 'core.note',
            payload: [],
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
        );

        $this->assertNull($envelope->tenantId);
        $this->assertSame([], $envelope->metadata);
    }

    #[Test]
    public function toArrayProducesExpectedShape(): void
    {
        $envelope = new Envelope(
            source: 'manual',
            type: 'core.note',
            payload: ['title' => 'Hello'],
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
            tenantId: 'tenant-1',
            metadata: ['priority' => 'high'],
        );

        $arr = $envelope->toArray();

        $this->assertSame('manual', $arr['source']);
        $this->assertSame('core.note', $arr['type']);
        $this->assertSame(['title' => 'Hello'], $arr['payload']);
        $this->assertSame('2026-03-08T17:00:00+00:00', $arr['timestamp']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $arr['trace_id']);
        $this->assertSame('tenant-1', $arr['tenant_id']);
        $this->assertSame(['priority' => 'high'], $arr['metadata']);
    }

    #[Test]
    public function toArrayIncludesNullTenantId(): void
    {
        $envelope = new Envelope(
            source: 'api',
            type: 'core.note',
            payload: [],
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
        );

        $arr = $envelope->toArray();

        $this->assertArrayHasKey('tenant_id', $arr);
        $this->assertNull($arr['tenant_id']);
    }

    #[Test]
    public function toArrayIncludesEmptyMetadata(): void
    {
        $envelope = new Envelope(
            source: 'api',
            type: 'core.note',
            payload: [],
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
        );

        $arr = $envelope->toArray();

        $this->assertSame([], $arr['metadata']);
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Ingestion/EnvelopeTest.php`
Expected: 5 tests, 5 passed

- [ ] **Step 3: Commit**

```bash
git add packages/foundation/tests/Unit/Ingestion/EnvelopeTest.php
git commit -m "test(#579): add dedicated EnvelopeTest for isolated DTO coverage"
```

---

### Task 2: IngestionErrorTest — Value Object Tests

**Files:**
- Create: `packages/foundation/tests/Unit/Ingestion/IngestionErrorTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Ingestion\IngestionError;
use Waaseyaa\Foundation\Ingestion\IngestionErrorCode;

#[CoversClass(IngestionError::class)]
final class IngestionErrorTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $error = new IngestionError(
            code: IngestionErrorCode::PAYLOAD_FIELD_MISSING,
            message: "Required field 'title' is missing.",
            field: 'title',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
            details: ['schema_version' => '0.1.0'],
        );

        $this->assertSame(IngestionErrorCode::PAYLOAD_FIELD_MISSING, $error->code);
        $this->assertSame("Required field 'title' is missing.", $error->message);
        $this->assertSame('title', $error->field);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $error->traceId);
        $this->assertSame(['schema_version' => '0.1.0'], $error->details);
    }

    #[Test]
    public function constructWithOptionalFieldsDefaulted(): void
    {
        $error = new IngestionError(
            code: IngestionErrorCode::ENVELOPE_FIELD_MISSING,
            message: 'Missing source.',
            field: 'source',
        );

        $this->assertNull($error->traceId);
        $this->assertSame([], $error->details);
    }

    #[Test]
    public function toArraySerializesEnumCodeAsString(): void
    {
        $error = new IngestionError(
            code: IngestionErrorCode::PAYLOAD_FIELD_TOO_LONG,
            message: "Field 'title' exceeds max length.",
            field: 'title',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
            details: ['max' => 512],
        );

        $arr = $error->toArray();

        $this->assertSame('PAYLOAD_FIELD_TOO_LONG', $arr['code']);
        $this->assertSame("Field 'title' exceeds max length.", $arr['message']);
        $this->assertSame('title', $arr['field']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $arr['trace_id']);
        $this->assertSame(['max' => 512], $arr['details']);
    }

    #[Test]
    public function toArrayIncludesNullTraceId(): void
    {
        $error = new IngestionError(
            code: IngestionErrorCode::ENVELOPE_FIELD_EMPTY,
            message: 'Empty.',
            field: 'source',
        );

        $arr = $error->toArray();

        $this->assertArrayHasKey('trace_id', $arr);
        $this->assertNull($arr['trace_id']);
    }

    #[Test]
    public function toArrayIncludesEmptyDetails(): void
    {
        $error = new IngestionError(
            code: IngestionErrorCode::ENVELOPE_FIELD_EMPTY,
            message: 'Empty.',
            field: 'source',
        );

        $arr = $error->toArray();

        $this->assertSame([], $arr['details']);
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Ingestion/IngestionErrorTest.php`
Expected: 5 tests, 5 passed

- [ ] **Step 3: Commit**

```bash
git add packages/foundation/tests/Unit/Ingestion/IngestionErrorTest.php
git commit -m "test(#579): add dedicated IngestionErrorTest for value object coverage"
```

---

### Task 3: IngestionErrorCodeTest — Enum Completeness

**Files:**
- Create: `packages/foundation/tests/Unit/Ingestion/IngestionErrorCodeTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Ingestion\IngestionErrorCode;

#[CoversClass(IngestionErrorCode::class)]
final class IngestionErrorCodeTest extends TestCase
{
    #[Test]
    public function enumHasExpectedCaseCount(): void
    {
        $cases = IngestionErrorCode::cases();

        $this->assertCount(14, $cases);
    }

    #[Test]
    public function allCasesHaveDistinctValues(): void
    {
        $values = array_map(
            static fn(IngestionErrorCode $c) => $c->value,
            IngestionErrorCode::cases(),
        );

        $this->assertSame($values, array_unique($values));
    }

    #[Test]
    public function envelopePrefixCoversEnvelopePhase(): void
    {
        $envelopeCases = array_filter(
            IngestionErrorCode::cases(),
            static fn(IngestionErrorCode $c) => str_starts_with($c->value, 'ENVELOPE_'),
        );

        $this->assertCount(6, $envelopeCases);
    }

    #[Test]
    public function payloadPrefixCoversPayloadPhase(): void
    {
        $payloadCases = array_filter(
            IngestionErrorCode::cases(),
            static fn(IngestionErrorCode $c) => str_starts_with($c->value, 'PAYLOAD_'),
        );

        $this->assertCount(8, $payloadCases);
    }

    #[Test]
    public function backedValueMatchesCaseName(): void
    {
        foreach (IngestionErrorCode::cases() as $case) {
            $this->assertSame($case->name, $case->value);
        }
    }

    #[Test]
    public function canBeResolvedFromStringValue(): void
    {
        $resolved = IngestionErrorCode::from('PAYLOAD_FIELD_MISSING');

        $this->assertSame(IngestionErrorCode::PAYLOAD_FIELD_MISSING, $resolved);
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        $resolved = IngestionErrorCode::tryFrom('NONEXISTENT_CODE');

        $this->assertNull($resolved);
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Ingestion/IngestionErrorCodeTest.php`
Expected: 7 tests, 7 passed

- [ ] **Step 3: Commit**

```bash
git add packages/foundation/tests/Unit/Ingestion/IngestionErrorCodeTest.php
git commit -m "test(#579): add IngestionErrorCodeTest for enum completeness"
```

---

### Task 4: InvalidEnvelopeExceptionTest — Isolated Exception Tests

**Files:**
- Create: `packages/foundation/tests/Unit/Ingestion/InvalidEnvelopeExceptionTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Ingestion\IngestionError;
use Waaseyaa\Foundation\Ingestion\IngestionErrorCode;
use Waaseyaa\Foundation\Ingestion\InvalidEnvelopeException;

#[CoversClass(InvalidEnvelopeException::class)]
final class InvalidEnvelopeExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = new InvalidEnvelopeException([]);

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    #[Test]
    public function defaultMessageIsSet(): void
    {
        $exception = new InvalidEnvelopeException([]);

        $this->assertSame('Envelope validation failed.', $exception->getMessage());
    }

    #[Test]
    public function customMessageOverridesDefault(): void
    {
        $exception = new InvalidEnvelopeException([], message: 'Custom error.');

        $this->assertSame('Custom error.', $exception->getMessage());
    }

    #[Test]
    public function errorsAreAccessible(): void
    {
        $errors = [
            new IngestionError(
                code: IngestionErrorCode::ENVELOPE_FIELD_MISSING,
                message: 'Missing source.',
                field: 'source',
            ),
            new IngestionError(
                code: IngestionErrorCode::ENVELOPE_FIELD_MISSING,
                message: 'Missing type.',
                field: 'type',
            ),
        ];

        $exception = new InvalidEnvelopeException($errors, traceId: 'trace-1');

        $this->assertCount(2, $exception->errors);
        $this->assertSame('source', $exception->errors[0]->field);
        $this->assertSame('type', $exception->errors[1]->field);
        $this->assertSame('trace-1', $exception->traceId);
    }

    #[Test]
    public function traceIdDefaultsToNull(): void
    {
        $exception = new InvalidEnvelopeException([]);

        $this->assertNull($exception->traceId);
    }

    #[Test]
    public function toArrayDelegatesToIngestionErrorToArray(): void
    {
        $errors = [
            new IngestionError(
                code: IngestionErrorCode::ENVELOPE_FIELD_UNKNOWN,
                message: 'Unknown field.',
                field: 'extra',
                traceId: 'trace-1',
                details: ['hint' => 'remove field'],
            ),
        ];

        $exception = new InvalidEnvelopeException($errors);
        $arr = $exception->toArray();

        $this->assertCount(1, $arr);
        $this->assertSame('ENVELOPE_FIELD_UNKNOWN', $arr[0]['code']);
        $this->assertSame('Unknown field.', $arr[0]['message']);
        $this->assertSame('extra', $arr[0]['field']);
        $this->assertSame('trace-1', $arr[0]['trace_id']);
        $this->assertSame(['hint' => 'remove field'], $arr[0]['details']);
    }

    #[Test]
    public function toArrayWithEmptyErrorsReturnsEmptyList(): void
    {
        $exception = new InvalidEnvelopeException([]);

        $this->assertSame([], $exception->toArray());
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Ingestion/InvalidEnvelopeExceptionTest.php`
Expected: 7 tests, 7 passed

- [ ] **Step 3: Commit**

```bash
git add packages/foundation/tests/Unit/Ingestion/InvalidEnvelopeExceptionTest.php
git commit -m "test(#579): add InvalidEnvelopeExceptionTest for isolated exception coverage"
```

---

### Task 5: PayloadValidatorTest — Edge Case Gaps

**Files:**
- Modify: `packages/foundation/tests/Unit/Ingestion/PayloadValidatorTest.php`

These tests cover code paths not exercised by existing tests: corrupt schema files, `number`/`boolean`/`array`/`object` type validation, and fields with no type declaration.

- [ ] **Step 1: Add edge case tests**

Append the following test methods before the `// Helpers` section (before line 442 `private function makeEnvelope`) in `PayloadValidatorTest.php`:

```php
    // ------------------------------------------------------------------
    // Corrupt / unreadable schema
    // ------------------------------------------------------------------

    #[Test]
    public function corruptSchemaFileReturnsLoadError(): void
    {
        file_put_contents(
            $this->defaultsDir . '/core.corrupt.schema.json',
            'NOT VALID JSON {{{',
        );

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $envelope = $this->makeEnvelope('core.corrupt', ['title' => 'X']);
        $errors = $validator->validate($envelope);

        $this->assertCount(1, $errors);
        $this->assertSame(IngestionErrorCode::PAYLOAD_SCHEMA_LOAD_FAILED, $errors[0]->code);
        $this->assertStringContainsString('Failed to load schema', $errors[0]->message);
    }

    // ------------------------------------------------------------------
    // Additional type validations
    // ------------------------------------------------------------------

    #[Test]
    public function integerFieldAcceptsInt(): void
    {
        $this->writeSchema('core.typed', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['count'],
            'additionalProperties' => false,
            'properties' => [
                'count' => ['type' => 'integer'],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.typed',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $envelope = $this->makeEnvelope('core.typed', ['count' => 42]);
        $this->assertSame([], $validator->validate($envelope));
    }

    #[Test]
    public function integerFieldRejectsFloat(): void
    {
        $this->writeSchema('core.typed', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['count'],
            'additionalProperties' => false,
            'properties' => [
                'count' => ['type' => 'integer'],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.typed',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $envelope = $this->makeEnvelope('core.typed', ['count' => 3.14]);
        $errors = $validator->validate($envelope);

        $this->assertNotEmpty($this->findErrors($errors, 'count'));
        $this->assertSame(IngestionErrorCode::PAYLOAD_FIELD_TYPE_INVALID, $this->findErrors($errors, 'count')[0]->code);
    }

    #[Test]
    public function numberFieldAcceptsIntAndFloat(): void
    {
        $this->writeSchema('core.metric', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['value'],
            'additionalProperties' => false,
            'properties' => [
                'value' => ['type' => 'number'],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.metric',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $this->assertSame([], $validator->validate($this->makeEnvelope('core.metric', ['value' => 42])));
        $this->assertSame([], $validator->validate($this->makeEnvelope('core.metric', ['value' => 3.14])));
    }

    #[Test]
    public function numberFieldRejectsString(): void
    {
        $this->writeSchema('core.metric', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['value'],
            'additionalProperties' => false,
            'properties' => [
                'value' => ['type' => 'number'],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.metric',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $envelope = $this->makeEnvelope('core.metric', ['value' => 'not-a-number']);
        $errors = $validator->validate($envelope);

        $this->assertNotEmpty($this->findErrors($errors, 'value'));
    }

    #[Test]
    public function booleanFieldAcceptsBool(): void
    {
        $this->writeSchema('core.flag', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['active'],
            'additionalProperties' => false,
            'properties' => [
                'active' => ['type' => 'boolean'],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.flag',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $this->assertSame([], $validator->validate($this->makeEnvelope('core.flag', ['active' => true])));
        $this->assertSame([], $validator->validate($this->makeEnvelope('core.flag', ['active' => false])));
    }

    #[Test]
    public function booleanFieldRejectsInt(): void
    {
        $this->writeSchema('core.flag', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['active'],
            'additionalProperties' => false,
            'properties' => [
                'active' => ['type' => 'boolean'],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.flag',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $errors = $validator->validate($this->makeEnvelope('core.flag', ['active' => 1]));
        $this->assertNotEmpty($this->findErrors($errors, 'active'));
    }

    #[Test]
    public function arrayFieldAcceptsList(): void
    {
        $this->writeSchema('core.tags', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['tags'],
            'additionalProperties' => false,
            'properties' => [
                'tags' => ['type' => 'array'],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.tags',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $this->assertSame([], $validator->validate($this->makeEnvelope('core.tags', ['tags' => ['a', 'b']])));
    }

    #[Test]
    public function arrayFieldRejectsAssociativeArray(): void
    {
        $this->writeSchema('core.tags', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['tags'],
            'additionalProperties' => false,
            'properties' => [
                'tags' => ['type' => 'array'],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.tags',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $errors = $validator->validate($this->makeEnvelope('core.tags', ['tags' => ['key' => 'val']]));
        $this->assertNotEmpty($this->findErrors($errors, 'tags'));
    }

    #[Test]
    public function objectFieldAcceptsAssociativeArray(): void
    {
        $this->writeSchema('core.meta', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['attrs'],
            'additionalProperties' => false,
            'properties' => [
                'attrs' => ['type' => 'object'],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.meta',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $this->assertSame([], $validator->validate($this->makeEnvelope('core.meta', ['attrs' => ['key' => 'val']])));
    }

    #[Test]
    public function objectFieldRejectsList(): void
    {
        $this->writeSchema('core.meta', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['attrs'],
            'additionalProperties' => false,
            'properties' => [
                'attrs' => ['type' => 'object'],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.meta',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $errors = $validator->validate($this->makeEnvelope('core.meta', ['attrs' => ['a', 'b']]));
        $this->assertNotEmpty($this->findErrors($errors, 'attrs'));
    }

    // ------------------------------------------------------------------
    // Field with no type declaration
    // ------------------------------------------------------------------

    #[Test]
    public function fieldWithNoTypePasses(): void
    {
        $this->writeSchema('core.loose', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['data'],
            'additionalProperties' => false,
            'properties' => [
                'data' => [],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.loose',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $this->assertSame([], $validator->validate($this->makeEnvelope('core.loose', ['data' => 'anything'])));
        $this->assertSame([], $validator->validate($this->makeEnvelope('core.loose', ['data' => 42])));
    }
```

- [ ] **Step 2: Run test to verify all pass**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Ingestion/PayloadValidatorTest.php`
Expected: 31 tests (18 existing + 13 new), all passed

- [ ] **Step 3: Commit**

```bash
git add packages/foundation/tests/Unit/Ingestion/PayloadValidatorTest.php
git commit -m "test(#579): add PayloadValidator edge cases for type validation and corrupt schemas"
```

---

### Task 6: IngestionLoggerTest — Prune Edge Cases

**Files:**
- Modify: `packages/foundation/tests/Unit/Ingestion/IngestionLoggerTest.php`

The existing tests don't cover prune keeping entries with unparseable `logged_at` timestamps (IngestionLogger.php line 94) or pruning when all entries are expired.

- [ ] **Step 1: Add edge case tests**

Append the following test methods before the `// Helpers` section (before line 229 `private function makeEnvelope`) in `IngestionLoggerTest.php`:

```php
    #[Test]
    public function pruneKeepsEntriesWithUnparseableLoggedAt(): void
    {
        $dir = $this->projectRoot . '/storage/framework';
        mkdir($dir, 0755, true);

        $file = $dir . '/ingestion.jsonl';

        $unparseable = json_encode([
            'source'    => 'manual',
            'type'      => 'core.note',
            'status'    => 'accepted',
            'trace_id'  => 'trace-bad-date',
            'timestamp' => '2026-03-08T17:00:00+00:00',
            'logged_at' => 'NOT-A-DATE',
        ], JSON_THROW_ON_ERROR);

        $recent = json_encode([
            'source'    => 'manual',
            'type'      => 'core.note',
            'status'    => 'accepted',
            'trace_id'  => 'trace-recent',
            'timestamp' => '2026-03-08T17:00:00+00:00',
            'logged_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        file_put_contents($file, $unparseable . "\n" . $recent . "\n");

        $logger = new IngestionLogger($this->projectRoot);
        $logger->prune(30);

        $entries = $logger->read();
        $this->assertCount(2, $entries);

        $traceIds = array_column($entries, 'trace_id');
        $this->assertContains('trace-bad-date', $traceIds);
        $this->assertContains('trace-recent', $traceIds);
    }

    #[Test]
    public function pruneRemovesAllWhenAllExpired(): void
    {
        $logger = new IngestionLogger($this->projectRoot);

        $old = new IngestionLogEntry(
            source:    'manual',
            type:      'core.note',
            status:    'accepted',
            traceId:   'trace-old-1',
            timestamp: '2025-01-01T00:00:00+00:00',
            loggedAt:  '2025-01-01T00:00:00+00:00',
        );
        $logger->log($old);

        $logger->prune(30);

        $entries = $logger->read();
        $this->assertSame([], $entries);
    }
```

- [ ] **Step 2: Run test to verify all pass**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Ingestion/IngestionLoggerTest.php`
Expected: 11 tests (9 existing + 2 new), all passed

- [ ] **Step 3: Commit**

```bash
git add packages/foundation/tests/Unit/Ingestion/IngestionLoggerTest.php
git commit -m "test(#579): add IngestionLogger prune edge cases"
```

---

### Task 7: Run Full Test Suite and Verify

- [ ] **Step 1: Run all ingestion tests together**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Ingestion/`
Expected: All tests pass (existing + new)

- [ ] **Step 2: Run full unit suite to check for regressions**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All tests pass, no regressions

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 4: Run code style check**

Run: `composer cs-check`
Expected: No violations in new/modified test files

---

## Summary

| File | Before | After | Delta |
|------|--------|-------|-------|
| `EnvelopeTest.php` | 0 | 5 | +5 |
| `IngestionErrorTest.php` | 0 | 5 | +5 |
| `IngestionErrorCodeTest.php` | 0 | 7 | +7 |
| `InvalidEnvelopeExceptionTest.php` | 0 | 7 | +7 |
| `PayloadValidatorTest.php` | 18 | 31 | +13 |
| `IngestionLoggerTest.php` | 9 | 11 | +2 |
| **Total new tests** | | | **+39** |
