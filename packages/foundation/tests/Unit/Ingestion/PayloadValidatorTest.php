<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Ingestion\Envelope;
use Waaseyaa\Foundation\Ingestion\IngestionError;
use Waaseyaa\Foundation\Ingestion\IngestionErrorCode;
use Waaseyaa\Foundation\Ingestion\PayloadValidator;
use Waaseyaa\Foundation\Schema\DefaultsSchemaRegistry;

#[CoversClass(PayloadValidator::class)]
#[CoversClass(IngestionError::class)]
#[CoversClass(IngestionErrorCode::class)]
final class PayloadValidatorTest extends TestCase
{
    private string $defaultsDir;
    private PayloadValidator $validator;

    protected function setUp(): void
    {
        $this->defaultsDir = sys_get_temp_dir() . '/waaseyaa_payload_test_' . uniqid();
        mkdir($this->defaultsDir, 0755, true);

        $this->writeSchema('core.note', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['title', 'tenant_id'],
            'additionalProperties' => false,
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'readOnly' => true,
                ],
                'uuid' => [
                    'type' => 'string',
                    'readOnly' => true,
                ],
                'title' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 512,
                ],
                'tenant_id' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'body' => [
                    'type' => 'string',
                    'default' => '',
                ],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.note',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $this->validator = new PayloadValidator($registry);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->defaultsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->defaultsDir);
    }

    // ------------------------------------------------------------------
    // Happy path
    // ------------------------------------------------------------------

    #[Test]
    public function validPayloadReturnsNoErrors(): void
    {
        $envelope = $this->makeEnvelope('core.note', [
            'title'     => 'Hello World',
            'tenant_id' => 'tenant-1',
            'body'      => 'Some content.',
        ]);

        $errors = $this->validator->validate($envelope);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validPayloadWithOnlyRequiredFields(): void
    {
        $envelope = $this->makeEnvelope('core.note', [
            'title'     => 'Hello',
            'tenant_id' => 'tenant-1',
        ]);

        $this->assertSame([], $this->validator->validate($envelope));
    }

    // ------------------------------------------------------------------
    // Unknown type
    // ------------------------------------------------------------------

    #[Test]
    public function unknownTypeReturnsError(): void
    {
        $envelope = $this->makeEnvelope('nonexistent.type', ['title' => 'X']);

        $errors = $this->validator->validate($envelope);

        $this->assertCount(1, $errors);
        $this->assertSame('type', $errors[0]->field);
        $this->assertSame(IngestionErrorCode::PAYLOAD_SCHEMA_NOT_FOUND, $errors[0]->code);
        $this->assertStringContainsString('No schema registered', $errors[0]->message);
    }

    // ------------------------------------------------------------------
    // Missing required fields
    // ------------------------------------------------------------------

    #[Test]
    public function missingRequiredFieldReturnsError(): void
    {
        $envelope = $this->makeEnvelope('core.note', [
            'tenant_id' => 'tenant-1',
        ]);

        $errors = $this->validator->validate($envelope);

        $fields = array_map(static fn(IngestionError $e) => $e->field, $errors);
        $this->assertContains('title', $fields);
    }

    #[Test]
    public function multipleRequiredFieldsMissingReportsAll(): void
    {
        $envelope = $this->makeEnvelope('core.note', []);

        $errors = $this->validator->validate($envelope);

        $fields = array_map(static fn(IngestionError $e) => $e->field, $errors);
        $this->assertContains('title', $fields);
        $this->assertContains('tenant_id', $fields);
    }

    #[Test]
    public function readOnlyRequiredFieldIsNotRequired(): void
    {
        $this->writeSchema('core.strict', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['id', 'name'],
            'additionalProperties' => false,
            'properties' => [
                'id'   => ['type' => 'integer', 'readOnly' => true],
                'name' => ['type' => 'string', 'minLength' => 1],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.strict',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $envelope = $this->makeEnvelope('core.strict', ['name' => 'Test']);
        $errors = $validator->validate($envelope);

        $this->assertSame([], $errors);
    }

    // ------------------------------------------------------------------
    // Type validation
    // ------------------------------------------------------------------

    #[Test]
    public function wrongTypeReturnsError(): void
    {
        $envelope = $this->makeEnvelope('core.note', [
            'title'     => 42, // should be string
            'tenant_id' => 'tenant-1',
        ]);

        $errors = $this->validator->validate($envelope);

        $this->assertNotEmpty($this->findErrors($errors, 'title'));
        $titleError = $this->findErrors($errors, 'title')[0];
        $this->assertSame(IngestionErrorCode::PAYLOAD_FIELD_TYPE_INVALID, $titleError->code);
        $this->assertStringContainsString("type 'string'", $titleError->message);
    }

    #[Test]
    public function booleanWhereStringExpectedFails(): void
    {
        $envelope = $this->makeEnvelope('core.note', [
            'title'     => 'Valid',
            'tenant_id' => true,
        ]);

        $errors = $this->validator->validate($envelope);

        $this->assertNotEmpty($errors);
        $tenantErrors = $this->findErrors($errors, 'tenant_id');
        $this->assertNotEmpty($tenantErrors);
        $this->assertStringContainsString("type 'string'", $tenantErrors[0]->message);
    }

    // ------------------------------------------------------------------
    // String constraints
    // ------------------------------------------------------------------

    #[Test]
    public function emptyStringBelowMinLengthFails(): void
    {
        $envelope = $this->makeEnvelope('core.note', [
            'title'     => '',
            'tenant_id' => 'tenant-1',
        ]);

        $errors = $this->validator->validate($envelope);

        $titleErrors = $this->findErrors($errors, 'title');
        $this->assertNotEmpty($titleErrors);
        $this->assertStringContainsString('at least 1', $titleErrors[0]->message);
    }

    #[Test]
    public function stringExceedingMaxLengthFails(): void
    {
        $envelope = $this->makeEnvelope('core.note', [
            'title'     => str_repeat('x', 513),
            'tenant_id' => 'tenant-1',
        ]);

        $errors = $this->validator->validate($envelope);

        $titleErrors = $this->findErrors($errors, 'title');
        $this->assertNotEmpty($titleErrors);
        $this->assertStringContainsString('at most 512', $titleErrors[0]->message);
        $this->assertSame(IngestionErrorCode::PAYLOAD_FIELD_TOO_LONG, $titleErrors[0]->code);
    }

    // ------------------------------------------------------------------
    // readOnly fields
    // ------------------------------------------------------------------

    #[Test]
    public function readOnlyFieldInPayloadReturnsError(): void
    {
        $envelope = $this->makeEnvelope('core.note', [
            'title'     => 'Hello',
            'tenant_id' => 'tenant-1',
            'id'        => 42,
        ]);

        $errors = $this->validator->validate($envelope);

        $idErrors = $this->findErrors($errors, 'id');
        $this->assertNotEmpty($idErrors);
        $this->assertStringContainsString('read-only', $idErrors[0]->message);
        $this->assertSame(IngestionErrorCode::PAYLOAD_FIELD_READ_ONLY, $idErrors[0]->code);
    }

    #[Test]
    public function multipleReadOnlyFieldsReportAll(): void
    {
        $envelope = $this->makeEnvelope('core.note', [
            'title'     => 'Hello',
            'tenant_id' => 'tenant-1',
            'id'        => 1,
            'uuid'      => 'abc',
        ]);

        $errors = $this->validator->validate($envelope);

        $fields = array_map(static fn(IngestionError $e) => $e->field, $errors);
        $this->assertContains('id', $fields);
        $this->assertContains('uuid', $fields);
    }

    // ------------------------------------------------------------------
    // Unknown fields (additionalProperties: false)
    // ------------------------------------------------------------------

    #[Test]
    public function unknownFieldRejectedWhenAdditionalPropertiesFalse(): void
    {
        $envelope = $this->makeEnvelope('core.note', [
            'title'     => 'Hello',
            'tenant_id' => 'tenant-1',
            'foo'       => 'bar',
        ]);

        $errors = $this->validator->validate($envelope);

        $fooErrors = $this->findErrors($errors, 'foo');
        $this->assertNotEmpty($fooErrors);
        $this->assertStringContainsString('Unknown field', $fooErrors[0]->message);
        $this->assertSame(IngestionErrorCode::PAYLOAD_FIELD_UNKNOWN, $fooErrors[0]->code);
    }

    #[Test]
    public function unknownFieldAllowedWhenAdditionalPropertiesTrue(): void
    {
        $this->writeSchema('core.open', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['name'],
            'additionalProperties' => true,
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.open',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);
        $validator = new PayloadValidator($registry);

        $envelope = $this->makeEnvelope('core.open', [
            'name'  => 'Test',
            'extra' => 'allowed',
        ]);

        $this->assertSame([], $validator->validate($envelope));
    }

    // ------------------------------------------------------------------
    // Multiple errors reported together
    // ------------------------------------------------------------------

    #[Test]
    public function multipleErrorsReportedAtOnce(): void
    {
        $envelope = $this->makeEnvelope('core.note', [
            'id'  => 1,   // readOnly
            'foo' => 'x', // unknown
            // missing: title, tenant_id
        ]);

        $errors = $this->validator->validate($envelope);

        $fields = array_map(static fn(IngestionError $e) => $e->field, $errors);
        $this->assertContains('id', $fields);
        $this->assertContains('foo', $fields);
        $this->assertContains('title', $fields);
        $this->assertContains('tenant_id', $fields);
    }

    // ------------------------------------------------------------------
    // Canonical error shape
    // ------------------------------------------------------------------

    #[Test]
    public function errorsCarryTraceId(): void
    {
        $envelope = $this->makeEnvelope('core.note', []);

        $errors = $this->validator->validate($envelope);

        $this->assertNotEmpty($errors);
        foreach ($errors as $error) {
            $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $error->traceId);
        }
    }

    #[Test]
    public function errorsSerializeToCanonicalShape(): void
    {
        $envelope = $this->makeEnvelope('core.note', ['id' => 1]);

        $errors = $this->validator->validate($envelope);

        $arr = $errors[0]->toArray();
        $this->assertArrayHasKey('code', $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertArrayHasKey('field', $arr);
        $this->assertArrayHasKey('trace_id', $arr);
        $this->assertArrayHasKey('details', $arr);
    }

    // ------------------------------------------------------------------
    // Integration with real schema
    // ------------------------------------------------------------------

    #[Test]
    public function validatesAgainstRealCoreNoteSchema(): void
    {
        $realDefaultsDir = dirname(__DIR__, 5) . '/defaults';
        if (!is_dir($realDefaultsDir)) {
            $this->markTestSkipped('Real defaults/ directory not found.');
        }

        $registry = new DefaultsSchemaRegistry($realDefaultsDir);
        $validator = new PayloadValidator($registry);

        $envelope = $this->makeEnvelope('core.note', [
            'title'     => 'Integration Test Note',
            'body'      => 'This is a test.',
        ]);

        $this->assertSame([], $validator->validate($envelope));
    }

    #[Test]
    public function rejectsInvalidPayloadAgainstRealSchema(): void
    {
        $realDefaultsDir = dirname(__DIR__, 5) . '/defaults';
        if (!is_dir($realDefaultsDir)) {
            $this->markTestSkipped('Real defaults/ directory not found.');
        }

        $registry = new DefaultsSchemaRegistry($realDefaultsDir);
        $validator = new PayloadValidator($registry);

        $envelope = $this->makeEnvelope('core.note', [
            'id'    => 999,    // readOnly
            'title' => '',     // minLength 1
        ]);

        $errors = $validator->validate($envelope);
        $fields = array_map(static fn(IngestionError $e) => $e->field, $errors);

        $this->assertContains('id', $fields);
        $this->assertContains('title', $fields);
    }

    // ------------------------------------------------------------------
    // Corrupt / unreadable schema
    // ------------------------------------------------------------------

    #[Test]
    public function corruptSchemaFileReturnsLoadError(): void
    {
        // Write a valid schema so the registry builds a SchemaEntry for it.
        $this->writeSchema('core.corrupt', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type'    => 'object',
            'required' => ['title'],
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string'],
            ],
            'x-waaseyaa' => [
                'entity_type'   => 'core.corrupt',
                'version'       => '0.1.0',
                'compatibility' => 'liberal',
            ],
        ]);

        $registry = new DefaultsSchemaRegistry($this->defaultsDir);

        // Force the registry to load and cache the valid entry.
        $this->assertNotNull($registry->get('core.corrupt'));

        // Now corrupt the file so PayloadValidator::loadSchema() fails.
        file_put_contents(
            $this->defaultsDir . '/core.corrupt.schema.json',
            'NOT VALID JSON {{{',
        );

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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeEnvelope(string $type, array $payload): Envelope
    {
        return new Envelope(
            source:    'manual',
            type:      $type,
            payload:   $payload,
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId:   '550e8400-e29b-41d4-a716-446655440000',
        );
    }

    /** @param array<string, mixed> $data */
    private function writeSchema(string $name, array $data): void
    {
        file_put_contents(
            $this->defaultsDir . '/' . $name . '.schema.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<IngestionError> $errors
     * @return list<IngestionError>
     */
    private function findErrors(array $errors, string $field): array
    {
        return array_values(array_filter(
            $errors,
            static fn(IngestionError $e) => $e->field === $field,
        ));
    }
}
