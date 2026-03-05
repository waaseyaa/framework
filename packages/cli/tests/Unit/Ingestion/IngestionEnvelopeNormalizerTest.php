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
}
