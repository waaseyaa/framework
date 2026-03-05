<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Ingestion\SchemaDiagnosticEmitter;

#[CoversClass(SchemaDiagnosticEmitter::class)]
final class SchemaDiagnosticEmitterTest extends TestCase
{
    #[Test]
    public function it_emits_deterministic_message_and_context_shape(): void
    {
        $emitter = new SchemaDiagnosticEmitter();
        $diagnostics = $emitter->emit([
            [
                'code' => 'schema.unknown_source_set_scheme',
                'location' => '/source_set_uri',
                'item_index' => null,
                'value' => 'legacy',
                'expected' => ['dataset', 'manual'],
                'allowed_schemes' => ['dataset', 'manual'],
            ],
        ]);

        $this->assertCount(1, $diagnostics);
        $this->assertSame('schema.unknown_source_set_scheme', $diagnostics[0]['code']);
        $this->assertSame('/source_set_uri', $diagnostics[0]['location']);
        $this->assertStringContainsString('Allowed schemes: dataset, manual.', (string) $diagnostics[0]['message']);

        $context = $diagnostics[0]['context'];
        $this->assertSame(['value', 'expected', 'allowed_schemes'], array_keys($context));
        $this->assertSame('legacy', $context['value']);
        $this->assertSame(['dataset', 'manual'], $context['expected']);
    }

    #[Test]
    public function it_emits_fixed_templates_for_new_schema_rule_codes(): void
    {
        $emitter = new SchemaDiagnosticEmitter();
        $diagnostics = $emitter->emit([
            [
                'code' => 'schema.missing_required_envelope_field',
                'location' => '/batch_id',
                'item_index' => null,
                'value' => null,
                'expected' => 'non-empty string',
                'field_name' => 'batch_id',
            ],
            [
                'code' => 'schema.invalid_items_type',
                'location' => '/items',
                'item_index' => null,
                'value' => 'string',
                'expected' => 'array',
            ],
            [
                'code' => 'schema.malformed_ingested_at',
                'location' => '/items/0/ingested_at',
                'item_index' => 0,
                'value' => 'not-a-date',
                'expected' => 'unix_timestamp_or_iso8601',
            ],
        ]);

        $messages = array_values(array_map(static fn(array $row): string => (string) ($row['message'] ?? ''), $diagnostics));
        $this->assertContains('Missing required envelope field: "batch_id".', $messages);
        $this->assertContains('Invalid items field type: "string". Expected: "array".', $messages);
        $this->assertContains('Malformed ingested_at value: "not-a-date". Expected: "unix_timestamp_or_iso8601".', $messages);
    }

    #[Test]
    public function it_emits_fixed_templates_for_provenance_format_rule_codes(): void
    {
        $emitter = new SchemaDiagnosticEmitter();
        $diagnostics = $emitter->emit([
            [
                'code' => 'schema.malformed_batch_id',
                'location' => '/batch_id',
                'item_index' => null,
                'value' => 'bad id',
                'expected' => '^[a-z0-9][a-z0-9_-]*$',
            ],
            [
                'code' => 'schema.malformed_source_uri',
                'location' => '/items/0/source_uri',
                'item_index' => 0,
                'value' => 'not-a-uri',
                'expected' => '<scheme>://<identifier>',
            ],
            [
                'code' => 'schema.invalid_parser_version_type',
                'location' => '/items/0/parser_version',
                'item_index' => 0,
                'value' => 'integer',
                'expected' => 'string_or_null',
            ],
        ]);

        $messages = array_values(array_map(static fn(array $row): string => (string) ($row['message'] ?? ''), $diagnostics));
        $this->assertContains('Malformed batch_id value: "bad id". Expected: "^[a-z0-9][a-z0-9_-]*$".', $messages);
        $this->assertContains('Malformed source_uri value: "not-a-uri". Expected: "<scheme>://<identifier>".', $messages);
        $this->assertContains('Invalid parser_version type: "integer". Expected: "string_or_null".', $messages);
    }
}
