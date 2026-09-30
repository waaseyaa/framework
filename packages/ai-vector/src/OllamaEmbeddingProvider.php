<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

/**
 * @api
 */
final class OllamaEmbeddingProvider implements EmbeddingInterface, EmbeddingProviderEgressInterface, EmbeddingSaveProviderInterface
{
    /**
     * @param callable(string, array<string, string>, array<string, mixed>): array<string, mixed>|null $transport
     */
    public function __construct(
        private readonly string $endpoint = 'http://127.0.0.1:11434/api/embeddings',
        private readonly string $model = 'nomic-embed-text',
        private readonly mixed $transport = null,
        private readonly int $dimensions = 768,
    ) {}

    public function embed(string $text): array
    {
        return $this->embedWithDeadline($text, 15000);
    }

    public function embedForSave(string $text): array
    {
        return $this->embedWithDeadline($text, 2000);
    }

    private function embedWithDeadline(string $text, int $deadlineMs): array
    {
        $payload = [
            'model' => $this->model,
            'prompt' => $text,
        ];

        $response = $this->request($payload, $deadlineMs);
        $embedding = $response['embedding'] ?? null;
        if (!is_array($embedding)) {
            throw new \RuntimeException('Invalid Ollama embedding response.');
        }

        return $this->normalizeVector($embedding);
    }

    public function embedBatch(array $texts): array
    {
        $embeddings = [];
        foreach ($texts as $text) {
            $embeddings[] = $this->embed((string) $text);
        }

        return $embeddings;
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function transmitsOffHost(): bool
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        if (!is_string($host)) {
            return true;
        }

        return !in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(array $payload, int $deadlineMs): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($this->transport !== null) {
            return (array) ($this->transport)($this->endpoint, $headers, $payload);
        }

        return EmbeddingHttpTransport::request($this->endpoint, $headers, $payload, $deadlineMs);
    }

    /**
     * @param array<int, mixed> $values
     * @return list<float>
     */
    private function normalizeVector(array $values): array
    {
        $vector = [];
        foreach ($values as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new \RuntimeException('Embedding vector contains non-numeric values.');
            }
            $vector[] = (float) $value;
        }

        return $vector;
    }
}
