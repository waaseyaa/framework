<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates the defaults/core.note.schema.json file structure.
 *
 * These are structural tests — they assert that the schema file is valid JSON
 * and that the declared fields, constraints, and required array are correct.
 * They do not run payloads through a JSON Schema validator.
 *
 * Full payload validation (valid payload accepted, invalid payload rejected)
 * will be exercised by a real JSON Schema validator once the schema registry
 * is implemented in #207.
 */
#[CoversNothing]
final class NoteSchemaTest extends TestCase
{
    private string $schemaPath;

    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        $this->schemaPath = dirname(__DIR__, 4) . '/defaults/core.note.schema.json';
        $json = file_get_contents($this->schemaPath);
        $this->assertNotFalse($json, 'Schema file must be readable');
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->schema = $decoded;
    }

    #[Test]
    public function schemaFileIsValidJson(): void
    {
        $this->assertIsArray($this->schema);
        $this->assertSame('http://json-schema.org/draft-07/schema#', $this->schema['$schema']);
    }

    #[Test]
    public function titleIsRequired(): void
    {
        $this->assertContains('title', $this->schema['required']);
    }

    #[Test]
    public function tenantIdIsNotRequired(): void
    {
        $this->assertNotContains('tenant_id', $this->schema['required']);
    }

    #[Test]
    public function tenantIdPropertyDoesNotExist(): void
    {
        $this->assertArrayNotHasKey('tenant_id', $this->schema['properties']);
    }

    #[Test]
    public function titleHasMinLengthConstraint(): void
    {
        $this->assertArrayHasKey('title', $this->schema['properties']);
        $this->assertSame(1, $this->schema['properties']['title']['minLength']);
    }

    #[Test]
    public function titleHasMaxLengthConstraint(): void
    {
        $this->assertSame(512, $this->schema['properties']['title']['maxLength']);
    }

    #[Test]
    public function idAndUuidAreReadOnly(): void
    {
        $this->assertTrue($this->schema['properties']['id']['readOnly']);
        $this->assertTrue($this->schema['properties']['uuid']['readOnly']);
    }

    #[Test]
    public function additionalPropertiesAreForbidden(): void
    {
        $this->assertFalse($this->schema['additionalProperties']);
    }

    #[Test]
    public function validPayloadPassesRequiredConstraints(): void
    {
        $required = $this->schema['required'];

        $validPayload = ['title' => 'My Note'];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $validPayload, "Valid payload must include required field '$field'");
        }
    }

    #[Test]
    public function missingTitleFailsRequiredConstraint(): void
    {
        $required = $this->schema['required'];

        $invalidPayload = ['body' => 'No title here']; // no title
        $this->assertContains('title', $required);
        $this->assertArrayNotHasKey('title', $invalidPayload, 'Invalid payload must be missing title');
    }
}
