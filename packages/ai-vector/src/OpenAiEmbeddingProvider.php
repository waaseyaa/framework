<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretHandle;

/**
 * @api
 */
final class OpenAiEmbeddingProvider implements EmbeddingInterface
{
    public const string CREDENTIAL_PURPOSE = 'waaseyaa.ai.embedding.v1';

    private readonly SecretHandle $credential;

    /** @var (\Closure(string, array<string, mixed>): array<string, mixed>)|null */
    private readonly ?\Closure $transport;

    /** @var (\Closure(string, array<string, string>, array<string, mixed>): array<string, mixed>)|null */
    private readonly ?\Closure $authenticatedTransport;

    /**
     * @param callable(string, array<string, mixed>): array<string, mixed>|null $transport credential-free test/application seam
     * @param callable(string, array<string, string>, array<string, mixed>): array<string, mixed>|null $authenticatedTransport low-level test seam below credential injection
     */
    public function __construct(
        #[\SensitiveParameter]
        string|SecretHandle $apiKey,
        private readonly string $model = 'text-embedding-3-small',
        private readonly string $endpoint = 'https://api.openai.com/v1/embeddings',
        mixed $transport = null,
        private readonly int $dimensions = 1536,
        mixed $authenticatedTransport = null,
    ) {
        $this->credential = $apiKey instanceof SecretHandle
            ? $apiKey
            : SecretHandle::fromBytes(
                $apiKey,
                SecretClass::ProviderCredential,
                self::CREDENTIAL_PURPOSE,
                'legacy-static-v1',
                [OpenAiEmbeddingCredentialOperation::class],
            );
        $this->transport = $transport !== null ? \Closure::fromCallable($transport) : null;
        $this->authenticatedTransport = $authenticatedTransport !== null
            ? \Closure::fromCallable($authenticatedTransport)
            : null;
    }

    public function embed(string $text): array
    {
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
        if ($this->transport !== null) {
            return ($this->transport)($this->endpoint, $payload);
        }

        return $this->credential->consume(new OpenAiEmbeddingCredentialOperation(
            function (array $headers, string $version) use ($payload): array {
                if ($this->authenticatedTransport !== null) {
                    return ($this->authenticatedTransport)($this->endpoint, $headers, $payload);
                }

                return $this->requestAuthenticated($payload, $headers);
            },
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function requestAuthenticated(array $payload, array $headers): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines) . "\r\n",
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
