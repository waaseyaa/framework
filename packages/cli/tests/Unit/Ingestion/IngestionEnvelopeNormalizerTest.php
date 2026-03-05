<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Ingestion\IngestionEnvelopeNormalizer;

#[CoversClass(IngestionEnvelopeNormalizer::class)]
final class IngestionEnvelopeNormalizerTest extends TestCase
{
    #[Test]
    public function it_normalizes_core_envelope_fields_deterministically(): void
    {
        $normalizer = new IngestionEnvelopeNormalizer();
        $result = $normalizer->normalize([
            'batch_id' => '  batch-1  ',
            'source_set_uri' => '  DATASET://river  ',
            'policy' => '  ATOMIC_FAIL_FAST ',
            'items' => [[
                'source_uri' => '  item://a  ',
                'ingested_at' => ' 1735689600 ',
                'parser_version' => '  v1  ',
                'title' => '  Water  ',
            ]],
        ]);

        $envelope = $result['envelope'];
        $this->assertSame('batch-1', $envelope['batch_id']);
        $this->assertSame('DATASET://river', $envelope['source_set_uri']);
        $this->assertSame('atomic_fail_fast', $envelope['policy']);
        $this->assertSame('item://a', $envelope['items'][0]['source_uri']);
        $this->assertSame(1735689600, $envelope['items'][0]['ingested_at']);
        $this->assertSame('v1', $envelope['items'][0]['parser_version']);
        $this->assertSame('Water', $envelope['items'][0]['title']);
    }

    #[Test]
    public function it_defaults_missing_or_invalid_envelope_fields(): void
    {
        $normalizer = new IngestionEnvelopeNormalizer();
        $result = $normalizer->normalize([
            'batch_id' => null,
            'source_set_uri' => null,
            'policy' => null,
            'items' => 'not-an-array',
        ]);

        $this->assertSame([
            'batch_id' => '',
            'source_set_uri' => '',
            'policy' => '',
            'items' => [],
        ], $result['envelope']);
    }

    #[Test]
    public function it_reindexes_items_with_zero_based_indices(): void
    {
        $normalizer = new IngestionEnvelopeNormalizer();
        $result = $normalizer->normalize([
            'batch_id' => 'b',
            'source_set_uri' => 'dataset://s',
            'policy' => 'validate_only',
            'items' => [
                2 => ['source_uri' => 'a', 'ingested_at' => 1],
                5 => ['source_uri' => 'b', 'ingested_at' => 2],
            ],
        ]);

        $items = $result['envelope']['items'];
        $this->assertSame(['a', 'b'], [$items[0]['source_uri'], $items[1]['source_uri']]);
    }

    #[Test]
    public function it_normalizes_malformed_item_rows_into_empty_provenance_shape(): void
    {
        $normalizer = new IngestionEnvelopeNormalizer();
        $result = $normalizer->normalize([
            'batch_id' => 'b',
            'source_set_uri' => 'dataset://s',
            'policy' => 'atomic_fail_fast',
            'items' => [123, ['source_uri' => 'ok', 'ingested_at' => 7]],
        ]);

        $items = $result['envelope']['items'];
        $this->assertSame('', $items[0]['source_uri']);
        $this->assertNull($items[0]['ingested_at']);
        $this->assertNull($items[0]['parser_version']);
        $this->assertSame('ok', $items[1]['source_uri']);
    }

    #[Test]
    public function it_normalizes_whitespace_and_nullability_for_provenance_fields(): void
    {
        $normalizer = new IngestionEnvelopeNormalizer();
        $result = $normalizer->normalize([
            'batch_id' => ' b ',
            'source_set_uri' => ' dataset://set ',
            'policy' => ' validate_only ',
            'items' => [[
                'source_uri' => '   ',
                'ingested_at' => '   ',
                'parser_version' => '   ',
            ]],
        ]);

        $item = $result['envelope']['items'][0];
        $this->assertSame('', $item['source_uri']);
        $this->assertNull($item['ingested_at']);
        $this->assertNull($item['parser_version']);
    }

    #[Test]
    public function it_preserves_unknown_item_keys_and_trims_string_values(): void
    {
        $normalizer = new IngestionEnvelopeNormalizer();
        $result = $normalizer->normalize([
            'batch_id' => 'b',
            'source_set_uri' => 'dataset://s',
            'policy' => 'validate_only',
            'top_level_unknown' => 'ignored',
            'items' => [[
                'source_uri' => 's://1',
                'ingested_at' => 1,
                'custom' => '  value  ',
                'custom_number' => 42,
            ]],
        ]);

        $envelope = $result['envelope'];
        $this->assertArrayNotHasKey('top_level_unknown', $envelope);
        $this->assertSame('value', $envelope['items'][0]['custom']);
        $this->assertSame(42, $envelope['items'][0]['custom_number']);
    }

    #[Test]
    public function it_preserves_non_string_parser_version_for_type_validation(): void
    {
        $normalizer = new IngestionEnvelopeNormalizer();
        $result = $normalizer->normalize([
            'batch_id' => 'b',
            'source_set_uri' => 'dataset://s',
            'policy' => 'validate_only',
            'items' => [[
                'source_uri' => 'item://x',
                'ingested_at' => 1,
                'parser_version' => 101,
            ]],
        ]);

        $this->assertSame(101, $result['envelope']['items'][0]['parser_version']);
    }
}
