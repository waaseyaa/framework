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
}
