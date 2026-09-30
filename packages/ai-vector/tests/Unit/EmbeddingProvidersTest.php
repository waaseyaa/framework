<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\OllamaEmbeddingProvider;
use Waaseyaa\AI\Vector\OpenAiEmbeddingProvider;

#[CoversClass(OllamaEmbeddingProvider::class)]
#[CoversClass(OpenAiEmbeddingProvider::class)]
final class EmbeddingProvidersTest extends TestCase
{
    #[Test]
    public function ollamaProviderUsesConfiguredModelAndReturnsVector(): void
    {
        $capturedPayload = [];
        $provider = new OllamaEmbeddingProvider(
            model: 'nomic-embed-text',
            transport: static function (string $url, array $headers, array $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;
                return ['embedding' => [0.1, 0.2, 0.3]];
            },
        );

        $vector = $provider->embed('hello');

        $this->assertSame([0.1, 0.2, 0.3], $vector);
        $this->assertSame('nomic-embed-text', $capturedPayload['model']);
        $this->assertSame('hello', $capturedPayload['prompt']);
    }

    #[Test]
    public function openAiProviderParsesEmbeddingResponse(): void
    {
        $capturedPayload = [];
        $provider = new OpenAiEmbeddingProvider(
            apiKey: 'test-key',
            transport: static function (string $url, array $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;
                return ['data' => [['embedding' => [1.0, 2.0]]]];
            },
        );

        $vector = $provider->embed('embed me');

        $this->assertSame([1.0, 2.0], $vector);
        $this->assertSame('embed me', $capturedPayload['input']);
    }

    #[Test]
    public function providers_report_their_real_egress_boundary(): void
    {
        self::assertFalse((new OllamaEmbeddingProvider())->transmitsOffHost());
        self::assertFalse((new OllamaEmbeddingProvider(endpoint: 'http://[::1]:11434/api/embeddings'))->transmitsOffHost());
        self::assertTrue((new OllamaEmbeddingProvider(endpoint: 'http://ollama.internal:11434/api/embeddings'))->transmitsOffHost());
        self::assertTrue((new OpenAiEmbeddingProvider(apiKey: 'test-key'))->transmitsOffHost());
    }
}
