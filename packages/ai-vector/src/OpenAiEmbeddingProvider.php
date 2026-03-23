<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

final class OpenAiEmbeddingProvider implements EmbeddingInterface
{
    /**
     * @param callable(string, array<string, string>, array<string, mixed>): array<string, mixed>|null $transport
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'text-embedding-3-small',
        private readonly string $endpoint = 'https://api.openai.com/v1/embeddings',
        private readonly mixed $transport = null,
        private readonly int $dimensions = 1536,
    ) {}

    public function embed(string $text): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('OpenAI API key is required.');
        }

        $payload = [
            'model' => $this->model,
            'input' => $text,
        ];

        $response = $this->request($payload);
        $data = $response['data'] ?? null;
        $embedding = is_array($data) && isset($data[0]['embedding']) ? $data[0]['embedding'] : null;
        if (!is_array($embedding)) {
            throw new \RuntimeException('Invalid OpenAI embeddings response.');
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(array $payload): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        if ($this->transport !== null) {
            return (array) ($this->transport)($this->endpoint, $headers, $payload);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$this->apiKey}\r\n",
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout' => 20,
            ],
        ]);

        $raw = file_get_contents($this->endpoint, false, $context);
        if ($raw === false) {
            throw new \RuntimeException('Failed to call OpenAI embeddings endpoint.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid JSON from OpenAI embeddings endpoint: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON from OpenAI embeddings endpoint.');
        }

        return $decoded;
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
