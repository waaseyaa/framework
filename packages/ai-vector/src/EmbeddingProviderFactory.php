<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretHandle;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;

final class EmbeddingProviderFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, ?SecretResolverRegistry $secretResolverRegistry = null): ?EmbeddingProviderInterface
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
            'openai' => self::openAi($ai, $secretResolverRegistry),
            default => null,
        };
    }

    /** @param array<string, mixed> $ai */
    private static function openAi(array $ai, ?SecretResolverRegistry $secretResolverRegistry): OpenAiEmbeddingProvider
    {
        if (array_key_exists('openai_api_key', $ai)) {
            throw new ProviderCredentialConfigurationException('Raw ai.openai_api_key configuration is forbidden.');
        }
        if ($secretResolverRegistry === null) {
            throw new ProviderCredentialConfigurationException('OpenAI embedding credentials require kernel resolver custody.');
        }

        $rawReference = $ai['openai_credential_reference'] ?? null;
        if (!is_array($rawReference)) {
            throw new ProviderCredentialConfigurationException('OpenAI embedding credentials require a typed reference.');
        }
        $provider = $rawReference['provider'] ?? null;
        $identifier = $rawReference['identifier'] ?? null;
        $secretClass = $rawReference['secret_class'] ?? null;
        $purpose = $rawReference['purpose'] ?? null;
        if (!is_string($provider) || !is_string($identifier) || !is_string($secretClass) || !is_string($purpose)
            || $secretClass !== SecretClass::ProviderCredential->value
            || $purpose !== OpenAiEmbeddingProvider::CREDENTIAL_PURPOSE) {
            throw new ProviderCredentialConfigurationException('OpenAI embedding credential reference fields are invalid.');
        }

        try {
            $reference = SecretReference::create(
                $provider,
                $identifier,
                SecretClass::ProviderCredential,
                $purpose,
            );
        } catch (\InvalidArgumentException) {
            throw new ProviderCredentialConfigurationException('OpenAI embedding credential reference fields are invalid.');
        }

        return new OpenAiEmbeddingProvider(
            apiKey: SecretHandle::fromReference(
                $secretResolverRegistry,
                $reference,
                'waaseyaa/ai-vector',
                [OpenAiEmbeddingCredentialOperation::class],
            ),
            model: is_string($ai['openai_embedding_model'] ?? null)
                ? $ai['openai_embedding_model']
                : 'text-embedding-3-small',
        );
    }
}
