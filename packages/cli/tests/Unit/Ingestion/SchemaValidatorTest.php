<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Ingestion\SchemaValidator;

#[CoversClass(SchemaValidator::class)]
final class SchemaValidatorTest extends TestCase
{
    #[Test]
    public function it_reports_schema_violations_for_policy_scheme_and_duplicate_provenance(): void
    {
        $validator = new SchemaValidator();
        $violations = $validator->validate([
            'batch_id' => 'batch-1',
            'source_set_uri' => 'unknown://set',
            'policy' => 'invalid',
            'items' => [
                ['source_uri' => 'item://a', 'ingested_at' => 1735689600, 'parser_version' => null],
                ['source_uri' => 'item://a', 'ingested_at' => null, 'parser_version' => null],
            ],
        ]);

        $codes = array_values(array_map(static fn(array $row): string => (string) ($row['code'] ?? ''), $violations));
        sort($codes);

        $this->assertContains('schema.invalid_policy_value', $codes);
        $this->assertContains('schema.unknown_source_set_scheme', $codes);
        $this->assertContains('schema.duplicate_source_uri', $codes);
        $this->assertContains('schema.missing_required_provenance_field', $codes);
    }

    #[Test]
    public function it_reports_malformed_source_set_uri_when_format_is_invalid(): void
    {
        $validator = new SchemaValidator();
        $violations = $validator->validate([
            'batch_id' => 'batch-1',
            'source_set_uri' => 'dataset:/missing-slash',
            'policy' => 'atomic_fail_fast',
            'items' => [['source_uri' => 'a', 'ingested_at' => 1735689600, 'parser_version' => null]],
        ]);

        $codes = array_values(array_map(static fn(array $row): string => (string) ($row['code'] ?? ''), $violations));
        $this->assertContains('schema.malformed_source_set_uri', $codes);
        $this->assertNotContains('schema.unknown_source_set_scheme', $codes);
    }

    #[Test]
    public function it_accepts_allowed_source_set_scheme_case_insensitively(): void
    {
        $validator = new SchemaValidator();
        $violations = $validator->validate([
            'batch_id' => 'batch-1',
            'source_set_uri' => 'DATASET://river',
            'policy' => 'atomic_fail_fast',
            'items' => [['source_uri' => 'a', 'ingested_at' => 1735689600, 'parser_version' => null]],
        ]);

        $codes = array_values(array_map(static fn(array $row): string => (string) ($row['code'] ?? ''), $violations));
        $this->assertNotContains('schema.malformed_source_set_uri', $codes);
        $this->assertNotContains('schema.unknown_source_set_scheme', $codes);
    }

    #[Test]
    public function it_reports_missing_required_item_provenance_fields_independently(): void
    {
        $validator = new SchemaValidator();
        $violations = $validator->validate([
            'batch_id' => 'batch-1',
            'source_set_uri' => 'manual://set',
            'policy' => 'validate_only',
            'items' => [['source_uri' => '', 'ingested_at' => '', 'parser_version' => null]],
        ]);

        $missing = array_values(array_filter(
            $violations,
            static fn(array $row): bool => (string) ($row['code'] ?? '') === 'schema.missing_required_provenance_field',
        ));

        $locations = array_values(array_map(static fn(array $row): string => (string) ($row['location'] ?? ''), $missing));
        sort($locations);

        $this->assertSame(['/items/0/ingested_at', '/items/0/source_uri'], $locations);
    }

    #[Test]
    public function it_reports_missing_required_envelope_fields(): void
    {
        $validator = new SchemaValidator();
        $violations = $validator->validate([
            'batch_id' => '',
            'source_set_uri' => '',
            'policy' => '',
            'items' => [],
        ]);

        $missingEnvelope = array_values(array_filter(
            $violations,
            static fn(array $row): bool => (string) ($row['code'] ?? '') === 'schema.missing_required_envelope_field',
        ));
        $locations = array_values(array_map(static fn(array $row): string => (string) ($row['location'] ?? ''), $missingEnvelope));
        sort($locations);

        $this->assertSame(['/batch_id', '/policy', '/source_set_uri'], $locations);
    }

    #[Test]
    public function it_reports_invalid_items_type_when_items_is_not_an_array(): void
    {
        $validator = new SchemaValidator();
        $violations = $validator->validate([
            'batch_id' => 'batch-1',
            'source_set_uri' => 'dataset://set',
            'policy' => 'atomic_fail_fast',
            'items' => 'not-array',
        ]);

        $invalidItems = array_values(array_filter(
            $violations,
            static fn(array $row): bool => (string) ($row['code'] ?? '') === 'schema.invalid_items_type',
        ));

        $this->assertCount(1, $invalidItems);
        $this->assertSame('/items', $invalidItems[0]['location']);
    }

    #[Test]
    public function it_reports_malformed_ingested_at_when_value_is_not_timestamp_or_iso_date(): void
    {
        $validator = new SchemaValidator();
        $violations = $validator->validate([
            'batch_id' => 'batch-1',
            'source_set_uri' => 'dataset://set',
            'policy' => 'validate_only',
            'items' => [
                ['source_uri' => 'a', 'ingested_at' => 'not-a-date', 'parser_version' => null],
            ],
        ]);

        $malformed = array_values(array_filter(
            $violations,
            static fn(array $row): bool => (string) ($row['code'] ?? '') === 'schema.malformed_ingested_at',
        ));

        $this->assertCount(1, $malformed);
        $this->assertSame('/items/0/ingested_at', $malformed[0]['location']);
    }

    #[Test]
    public function it_reports_malformed_batch_id_when_format_is_invalid(): void
    {
        $validator = new SchemaValidator();
        $violations = $validator->validate([
            'batch_id' => 'batch id with spaces',
            'source_set_uri' => 'dataset://set',
            'policy' => 'atomic_fail_fast',
            'items' => [['source_uri' => 'item://a', 'ingested_at' => 1735689600, 'parser_version' => null]],
        ]);

        $malformed = array_values(array_filter(
            $violations,
            static fn(array $row): bool => (string) ($row['code'] ?? '') === 'schema.malformed_batch_id',
        ));

        $this->assertCount(1, $malformed);
        $this->assertSame('/batch_id', $malformed[0]['location']);
    }

    #[Test]
    public function it_reports_malformed_source_uri_when_item_source_uri_is_not_uri_like(): void
    {
        $validator = new SchemaValidator();
        $violations = $validator->validate([
            'batch_id' => 'batch_1',
            'source_set_uri' => 'dataset://set',
            'policy' => 'validate_only',
            'items' => [
                ['source_uri' => 'not-a-uri', 'ingested_at' => 1735689600, 'parser_version' => null],
            ],
        ]);

        $malformed = array_values(array_filter(
            $violations,
            static fn(array $row): bool => (string) ($row['code'] ?? '') === 'schema.malformed_source_uri',
        ));

        $this->assertCount(1, $malformed);
        $this->assertSame('/items/0/source_uri', $malformed[0]['location']);
    }

    #[Test]
    public function it_reports_invalid_parser_version_type_when_not_string_or_null(): void
    {
        $validator = new SchemaValidator();
        $violations = $validator->validate([
            'batch_id' => 'batch_1',
            'source_set_uri' => 'dataset://set',
            'policy' => 'validate_only',
            'items' => [
                ['source_uri' => 'item://a', 'ingested_at' => 1735689600, 'parser_version' => 101],
            ],
        ]);

        $invalidType = array_values(array_filter(
            $violations,
            static fn(array $row): bool => (string) ($row['code'] ?? '') === 'schema.invalid_parser_version_type',
        ));

        $this->assertCount(1, $invalidType);
        $this->assertSame('/items/0/parser_version', $invalidType[0]['location']);
    }
}
