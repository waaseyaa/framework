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
}
