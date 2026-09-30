<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\HttpClient\SymfonyHttpClient;

/** @internal Embedding response policy; networking belongs to http-client. */
final class EmbeddingHttpTransport
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function request(string $endpoint, array $headers, array $payload, int $deadlineMs): array
    {
        try {
            $client = new SymfonyHttpClient($deadlineMs / 1000, 1048576);
            $response = $client->post($endpoint, $headers, $payload);
            if (!$response->isSuccess()) {
                throw new \RuntimeException('Embedding HTTP request failed.');
            }
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Invalid JSON from embedding endpoint.');
            }

            return $decoded;
        } catch (\Throwable) {
            throw new \RuntimeException('Embedding HTTP request failed.');
        }
    }
}
