<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

final class EmbeddingProviderFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): ?EmbeddingProviderInterface
    {
        $ai = is_array($config['ai'] ?? null) ? $config['ai'] : [];
        $provider = strtolower((string) ($ai['embedding_provider'] ?? ''));

        return match ($provider) {
            'ollama' => new OllamaEmbeddingProvider(
                endpoint: is_string($ai['ollama_endpoint'] ?? null)
                    ? $ai['ollama_endpoint']
                    : 'http://127.0.0.1:11434/api/embeddings',
                model: is_string($ai['ollama_model'] ?? null)
                    ? $ai['ollama_model']
                    : 'nomic-embed-text',
            ),
            'openai' => new OpenAiEmbeddingProvider(
                apiKey: (string) ($ai['openai_api_key'] ?? getenv('OPENAI_API_KEY') ?: ''),
                model: is_string($ai['openai_embedding_model'] ?? null)
                    ? $ai['openai_embedding_model']
                    : 'text-embedding-3-small',
            ),
            default => null,
        };
    }
}
