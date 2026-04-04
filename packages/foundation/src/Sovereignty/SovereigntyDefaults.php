<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Sovereignty;

final class SovereigntyDefaults
{
    /** @var array<string, array<string, string>> */
    private const DEFAULTS = [
        'local' => [
            'storage' => 'filesystem',
            'embeddings' => 'sqlite',
            'llm_provider' => 'ollama',
            'transcriber' => 'whisper_ollama',
            'vector_store' => 'sqlite',
            'queue_backend' => 'sync',
        ],
        'self_hosted' => [
            'storage' => 'filesystem',
            'embeddings' => 'sqlite',
            'llm_provider' => 'ollama',
            'transcriber' => 'whisper_ollama',
            'vector_store' => 'sqlite',
            'queue_backend' => 'database',
        ],
        'northops' => [
            'storage' => 's3',
            'embeddings' => 'pgvector',
            'llm_provider' => 'api',
            'transcriber' => 'api',
            'vector_store' => 'pgvector',
            'queue_backend' => 'redis',
        ],
    ];

    /** @return array<string, string> */
    public static function for(SovereigntyProfile $profile): array
    {
        return self::DEFAULTS[$profile->value];
    }
}
